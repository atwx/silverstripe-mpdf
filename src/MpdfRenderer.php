<?php

namespace Atwx\Mpdf;

use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\ModelData;
use SilverStripe\View\SSViewer;

/**
 * Renders HTML or Silverstripe templates to PDF with mPDF.
 *
 * Everything is driven by YAML config (see _config/config.yml and README.md):
 * base mPDF settings, custom font folders and font families, global CSS files
 * and named profiles that bundle the settings of one kind of document.
 * Options passed to renderHtml()/renderTemplate() override the profile, which
 * overrides the global settings.
 *
 * Supported options:
 * - profile:    name of a profile from the `profiles` config
 * - mpdf:       mPDF constructor config (format, margin_*, default_font, ...)
 * - css:        CSS files (BASE_PATH-relative or "vendor/module:path"), applied in order
 * - styles:     inline CSS string, applied after the files
 * - title, author, subject, creator, keywords: PDF metadata
 * - background: PDF file used as letter paper behind every page (its first page
 *               behind the first page, its last page behind further pages)
 * - base_path:  folder that relative image/CSS paths are resolved against
 *               (default: the public folder)
 * - local_urls: rewrite site URLs in src/link attributes to local files (default true)
 * - keep_together: paragraphs and list items with at most this many characters
 *               are not split across pages (mPDF knows no orphans/widows; browsers
 *               keep at least 2 lines together, so short blocks never split there)
 *
 * Extension hooks: updateOptions, updateMpdfConfig, updateMpdf, updateHtml, updatePdf.
 */
class MpdfRenderer implements PdfRenderer
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Base mPDF constructor config, see https://mpdf.github.io/reference/mpdf-variables/overview.html
     */
    private static array $mpdf = [
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'P',
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 0,
        'margin_footer' => 0,
        'default_font' => 'dejavusans',
    ];

    /**
     * Folders with custom TTF fonts (BASE_PATH-relative or "vendor/module:path").
     * They are searched before mPDF's own font folder.
     */
    private static array $font_dirs = [];

    /**
     * Custom font families, merged into mPDF's fontdata. Keys are lower-case
     * family names used in CSS (font-family), values map styles to files:
     *   nunito: { R: Nunito-Regular.ttf, B: Nunito-SemiBold.ttf }
     * Additional mPDF font keys (useOTL, useKashida, ...) are passed through.
     */
    private static array $fonts = [];

    /**
     * CSS files applied to every document before profile/option CSS.
     */
    private static array $css = [];

    /**
     * Named option sets for kinds of documents, e.g.
     *   invoice: { mpdf: { margin_top: 10 }, css: [app/pdf/invoice.css] }
     */
    private static array $profiles = [];

    /**
     * Folder for mPDF's font cache and temporary files; empty means
     * TEMP_PATH/mpdf.
     */
    private static string $temp_dir = '';

    /**
     * Folder that relative image/CSS paths are resolved against; empty means
     * the public folder.
     */
    private static string $base_path = '';

    public function renderHtml(string $html, array $options = []): string
    {
        $options = $this->resolveOptions($options);
        $mpdf = $this->createMpdf($options);

        foreach ($this->collectCss($options) as $css) {
            $mpdf->WriteHTML($this->namedPageSizes($css), HTMLParserMode::HEADER_CSS);
        }

        if ($options['local_urls'] ?? true) {
            $html = $this->localiseUrls($html);
        }
        if (!empty($options['keep_together'])) {
            $html = $this->keepShortBlocksTogether($html, (int) $options['keep_together']);
        }
        $html = $this->namedPageSizes($html);
        $this->extend('updateHtml', $html, $options);

        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);

        $this->extend('updatePdf', $pdf, $options, $mpdf);
        return $pdf;
    }

    public function renderTemplate(string|array $template, mixed $data = null, array $options = []): string
    {
        if (is_array($data)) {
            $data = ArrayData::create($data);
        }
        if ($data instanceof ModelData) {
            $html = (string) $data->renderWith($template);
        } else {
            $html = (string) SSViewer::create($template)->process(ModelData::create());
        }
        return $this->renderHtml($html, $options);
    }

    /**
     * Merges global config, the chosen profile and the given options.
     * `mpdf` is merged key by key, `css` lists are concatenated.
     */
    public function resolveOptions(array $options): array
    {
        $profileName = $options['profile'] ?? null;
        $profile = [];
        if ($profileName) {
            $profiles = static::config()->get('profiles') ?: [];
            if (!isset($profiles[$profileName])) {
                throw new InvalidArgumentException(sprintf('Unknown PDF profile "%s"', $profileName));
            }
            $profile = $profiles[$profileName];
        }

        $resolved = array_replace($profile, $options);
        $resolved['mpdf'] = array_replace(
            static::config()->get('mpdf') ?: [],
            $profile['mpdf'] ?? [],
            $options['mpdf'] ?? []
        );
        $resolved['css'] = array_values(array_merge(
            static::config()->get('css') ?: [],
            $profile['css'] ?? [],
            $options['css'] ?? []
        ));

        $this->extend('updateOptions', $resolved);
        return $resolved;
    }

    /**
     * Creates the configured mPDF instance for already resolved options.
     * Public so that callers can drive mPDF directly for special cases.
     */
    public function createMpdf(array $options): Mpdf
    {
        $config = $options['mpdf'] ?? [];
        $config['tempDir'] = $this->getTempDir();

        $defaultFontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $fontDirs = array_map([$this, 'resolveFile'], static::config()->get('font_dirs') ?: []);
        $config['fontDir'] = array_merge($fontDirs, (array) ($config['fontDir'] ?? $defaultFontDirs));

        $defaultFontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $config['fontdata'] = array_replace(
            $defaultFontData,
            array_change_key_case(static::config()->get('fonts') ?: [], CASE_LOWER),
            $config['fontdata'] ?? []
        );

        $this->extend('updateMpdfConfig', $config, $options);

        $background = !empty($options['background']) ? $this->resolveFile($options['background']) : null;
        $mpdf = $background ? new LetterheadMpdf($config) : new Mpdf($config);

        $mpdf->SetBasePath(rtrim($this->getBasePath(), '/') . '/');

        if ($background) {
            // Page 1 of the paper for the first page, its last page for further pages.
            $mpdf->SetDocTemplate($background, true);
        }

        foreach (['title' => 'SetTitle', 'author' => 'SetAuthor', 'subject' => 'SetSubject',
                     'creator' => 'SetCreator', 'keywords' => 'SetKeywords'] as $key => $method) {
            if (!empty($options[$key])) {
                $mpdf->$method($options[$key]);
            }
        }

        $this->extend('updateMpdf', $mpdf, $options);
        return $mpdf;
    }

    /**
     * Reads all CSS for the document: files first, then the inline styles.
     *
     * @return string[]
     */
    protected function collectCss(array $options): array
    {
        $css = [];
        foreach ($options['css'] ?? [] as $file) {
            $path = $this->resolveFile($file);
            if (!is_file($path)) {
                throw new InvalidArgumentException(sprintf('PDF stylesheet "%s" not found', $file));
            }
            $css[] = $this->resolveCssUrls((string) file_get_contents($path), dirname($path));
        }
        if (!empty($options['styles'])) {
            $css[] = $options['styles'];
        }
        return $css;
    }

    /**
     * Makes relative url() references in a stylesheet absolute file paths
     * relative to the stylesheet's folder, like browsers do.
     */
    protected function resolveCssUrls(string $css, string $dir): string
    {
        return preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function (array $m) use ($dir) {
            $url = $m[2];
            if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/|\#)#i', $url)) {
                return $m[0];
            }
            $file = realpath($dir . '/' . $url);
            return $file ? 'url("' . $file . '")' : $m[0];
        }, $css);
    }

    /**
     * Turns site URLs in src attributes and <link href> into local file paths,
     * so mPDF reads images and stylesheets from disk instead of fetching them
     * over HTTP ($Vite(), Image.URL, resourceURL all produce site URLs).
     * <base> tags are dropped for the same reason. Links (<a href>) stay.
     */
    public function localiseUrls(string $html): string
    {
        $html = preg_replace('/<base\b[^>]*>/i', '', $html);

        $replace = function (array $m): string {
            $local = $this->localFileForUrl(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5));
            return $local === null ? $m[0] : $m[1] . '=' . $m[2] . htmlspecialchars($local, ENT_QUOTES) . $m[2];
        };

        $html = preg_replace_callback('/\b(src)\s*=\s*(["\'])(.*?)\2/i', $replace, $html);
        return preg_replace_callback('/<link\b[^>]*>/i', function (array $tag) use ($replace) {
            return preg_replace_callback('/\b(href)\s*=\s*(["\'])(.*?)\2/i', $replace, $tag[0]);
        }, $html);
    }

    /**
     * Rewrites named page sizes in @page rules (`size: A4`, `size: A4 landscape`)
     * to mPDF's `sheet-size: A4` / `sheet-size: A4-L`. mPDF only understands
     * lengths, auto, portrait and landscape for `size`; a named size, as usual
     * in stylesheets written for browsers, makes it produce hundreds of empty
     * pages.
     */
    public function namedPageSizes(string $css): string
    {
        return preg_replace_callback('/@page\b[^{]*\{[^}]*\}/i', function (array $rule): string {
            return preg_replace_callback(
                '/(?<![-\w])size\s*:\s*([^;}]+?)\s*(?=;|\}|$)/i',
                function (array $m): string {
                    $tokens = preg_split('/\s+/', strtolower(trim($m[1]))) ?: [];
                    $named = null;
                    $landscape = false;
                    foreach ($tokens as $token) {
                        if ($token === 'landscape') {
                            $landscape = true;
                        } elseif ($token === 'portrait') {
                            continue;
                        } elseif ($token === 'auto' || preg_match('/^[\d.]/', $token)) {
                            return $m[0];
                        } else {
                            $named = $token;
                        }
                    }
                    if ($named === null) {
                        return $m[0];
                    }
                    return 'sheet-size: ' . strtoupper($named) . ($landscape ? '-L' : '');
                },
                $rule[0]
            ) ?? $rule[0];
        }, $css) ?? $css;
    }

    /**
     * Adds page-break-inside: avoid to <p> and <li> elements (without nested
     * lists) whose text has at most $maxChars characters.
     */
    public function keepShortBlocksTogether(string $html, int $maxChars): string
    {
        return preg_replace_callback('#<(p|li)\b([^>]*)>(.*?)</\1>#is', function (array $m) use ($maxChars) {
            if (preg_match('#<(p|li|ul|ol|div|table)\b#i', $m[3])) {
                // Nested blocks: leave this element as is, but look at the inner ones
                return '<' . $m[1] . $m[2] . '>'
                    . $this->keepShortBlocksTogether($m[3] . '</' . $m[1] . '>', $maxChars);
            }
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));
            if (mb_strlen($text) > $maxChars) {
                return $m[0];
            }
            $attrs = $m[2];
            if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $attrs, $style)) {
                $attrs = str_replace($style[0], 'style=' . $style[1] . rtrim($style[2], '; ')
                    . '; page-break-inside: avoid' . $style[1], $attrs);
            } else {
                $attrs .= ' style="page-break-inside: avoid"';
            }
            return '<' . $m[1] . $attrs . '>' . $m[3] . '</' . $m[1] . '>';
        }, $html);
    }

    /**
     * Local file for a URL of this site, or null if the URL points elsewhere
     * or the file does not exist.
     */
    public function localFileForUrl(string $url): ?string
    {
        if ($url === '' || preg_match('#^(data|mailto|tel|javascript):|^\##i', $url)) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            if (!Director::is_site_url($url)) {
                return null;
            }
            $url = Director::makeRelative($url);
        } elseif (str_starts_with($url, '/')) {
            $url = Director::makeRelative($url);
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $file = rtrim($this->getBasePath(), '/') . '/' . ltrim(rawurldecode($path), '/');
        return is_file($file) ? $file : null;
    }

    /**
     * Absolute path for a BASE_PATH-relative path or a "vendor/module:path" resource.
     */
    public function resolveFile(string $path): string
    {
        if (str_contains($path, ':') && !preg_match('#^[a-z]:[\\\\/]#i', $path)) {
            $path = (string) ModuleResourceLoader::singleton()->resolvePath($path);
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return BASE_PATH . '/' . $path;
    }

    public function getTempDir(): string
    {
        $dir = static::config()->get('temp_dir') ?: TEMP_PATH . DIRECTORY_SEPARATOR . 'mpdf';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function getBasePath(): string
    {
        $path = static::config()->get('base_path');
        return $path ? $this->resolveFile($path) : PUBLIC_PATH;
    }
}

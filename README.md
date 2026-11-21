# atwx/silverstripe-mpdf

Render Silverstripe templates to PDF with [mPDF](https://mpdf.github.io/) – pure PHP,
no headless Chrome, a fraction of a second per document.

```php
use Atwx\Mpdf\PdfRenderer;
use Atwx\Mpdf\PdfResponse;
use SilverStripe\Core\Injector\Injector;

$pdf = Injector::inst()->get(PdfRenderer::class)
    ->renderTemplate('Invoice_pdf', $invoice, ['profile' => 'invoice']);

return PdfResponse::inline($pdf, 'Rechnung-2026-001.pdf');   // or ::download()
```

`renderTemplate()` takes a `ModelData`/`DataObject` or an array (wrapped in `ArrayData`);
`renderHtml()` takes ready HTML. Both return the PDF data as string.

## Configuration

Everything lives in YAML. Options given to a call override the profile, which
overrides the global settings; `mpdf` is merged key by key, `css` lists are concatenated.

```yaml
Atwx\Mpdf\MpdfRenderer:
  # Custom TTF fonts (BASE_PATH-relative or "vendor/module:path")
  font_dirs:
    - app/pdf/fonts
  fonts:
    nunito:                       # used as font-family: nunito
      R: Nunito-Regular.ttf
      B: Nunito-SemiBold.ttf
      useOTL: 0xFF                # OpenType layout, e.g. for GPOS kerning
  # mPDF constructor config for all documents
  mpdf:
    default_font: nunito
    useSubstitutions: true        # fall back to DejaVu for missing glyphs
  # CSS for all documents
  css: []
  # Named option sets per kind of document
  profiles:
    invoice:
      css:
        - app/pdf/css/invoice.css
      mpdf:
        margin_top: 10
        margin_left: 0
      background: app/pdf/letterhead.pdf
  temp_dir: ''                    # default: TEMP_PATH/mpdf (font cache)
  base_path: ''                   # default: the public folder
```

Options (YAML profile or per call):

| Option | Meaning |
|---|---|
| `profile` | name of a profile |
| `mpdf` | [mPDF config](https://mpdf.github.io/reference/mpdf-variables/overview.html): `format`, `margin_*`, `default_font`, … |
| `css` | stylesheet files, applied in order; relative `url()`s resolve against the stylesheet |
| `styles` | inline CSS string, applied last |
| `title`, `author`, `subject`, `creator`, `keywords` | PDF metadata |
| `background` | PDF used as letter paper: its first page behind page 1, its last page behind further pages; `<pagebreak resetpagenum="1" />` restarts it (batches of letters) |
| `local_urls` | rewrite site URLs in `src` and `<link href>` to local files (default `true`) |
| `keep_together` | `<p>`/`<li>` with at most this many characters are not split across pages (mPDF knows no `orphans`/`widows`) |

## Templates

mPDF supports a [CSS subset](https://mpdf.github.io/css-stylesheets/supported-css.html):
no flexbox/grid, no `calc()`, no `::before`, limited selectors. Use tables or floats
for columns and mPDF's page features for page furniture:

```html
<htmlpagefooter name="footer">…</htmlpagefooter>
<watermarkimage src="$PdfFile('app/pdf/stamp.svg')" alpha="0.2" />
```

```css
@page { footer: html_footer; background: url(../images/page.svg) no-repeat; background-image-resize: 6; }
@page :first { background: url(../images/first-page.svg) no-repeat; background-image-resize: 6; }
```

Images and stylesheets are read from disk: `$Vite()`, `Image.URL`, `$resourceURL()`
and relative paths just work; `$PdfFile('app/pdf/x.svg')` gives the absolute path of
any file (also outside the public folder). `<base>` tags are removed.

## Extension hooks

`MpdfRenderer` is `Extensible`: `updateOptions(&$options)`, `updateMpdfConfig(&$config, $options)`,
`updateMpdf($mpdf, $options)`, `updateHtml(&$html, $options)`, `updatePdf(&$pdf, $options, $mpdf)`.
[atwx/silverstripe-zugferd](https://github.com/atwx/silverstripe-zugferd) uses `updatePdf`.
For special cases `createMpdf($renderer->resolveOptions([...]))` returns the configured
`Mpdf` instance. Swap the engine by binding another `PdfRenderer` in Injector.

## Tests

```bash
SS_PHPUNIT_FLUSH=1 php phpunit.phar --configuration phpunit.xml.dist
```

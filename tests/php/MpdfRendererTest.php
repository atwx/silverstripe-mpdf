<?php

namespace Atwx\Mpdf\Tests;

use Atwx\Mpdf\MpdfRenderer;
use Atwx\Mpdf\PdfResponse;
use InvalidArgumentException;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;

class MpdfRendererTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        MpdfRenderer::config()->set('mpdf', ['format' => 'A4', 'margin_left' => 20, 'default_font' => 'dejavusans']);
        MpdfRenderer::config()->set('css', ['global.css']);
        MpdfRenderer::config()->set('profiles', [
            'letter' => ['mpdf' => ['margin_left' => 10], 'css' => ['letter.css'], 'title' => 'Letter'],
        ]);
    }

    public function testOptionsMergeGlobalProfileAndCall(): void
    {
        $options = MpdfRenderer::create()->resolveOptions([
            'profile' => 'letter',
            'mpdf' => ['margin_top' => 5],
            'css' => ['call.css'],
        ]);

        $this->assertSame(['format' => 'A4', 'margin_left' => 10, 'default_font' => 'dejavusans', 'margin_top' => 5], $options['mpdf']);
        $this->assertSame(['global.css', 'letter.css', 'call.css'], $options['css']);
        $this->assertSame('Letter', $options['title']);
    }

    public function testUnknownProfileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MpdfRenderer::create()->resolveOptions(['profile' => 'nope']);
    }

    public function testRendersHtmlToPdf(): void
    {
        MpdfRenderer::config()->set('css', []);
        $pdf = MpdfRenderer::create()->renderHtml('<h1>Hallo Welt</h1>', ['styles' => 'h1 { color: red; }']);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testSiteUrlsBecomeLocalFiles(): void
    {
        $renderer = MpdfRenderer::create();
        $dir = TEMP_PATH . '/mpdf-test';
        @mkdir($dir . '/img', 0775, true);
        file_put_contents($dir . '/img/a.png', 'x');
        MpdfRenderer::config()->set('base_path', $dir);

        $html = $renderer->localiseUrls(
            '<base href="https://example.com/"><img src="' . Director::absoluteURL('/img/a.png') . '">'
            . '<img src="/img/a.png?v=1"><img src="https://elsewhere.test/img/a.png">'
            . '<a href="/img/a.png">link</a><link rel="stylesheet" href="/img/missing.css">'
        );

        $this->assertStringNotContainsString('<base', $html);
        $this->assertSame(2, substr_count($html, 'src="' . $dir . '/img/a.png"'));
        $this->assertStringContainsString('src="https://elsewhere.test/img/a.png"', $html);
        $this->assertStringContainsString('<a href="/img/a.png">', $html, 'links stay links');
        $this->assertStringContainsString('href="/img/missing.css"', $html, 'missing files stay untouched');
    }

    public function testShortBlocksAreKeptTogether(): void
    {
        $html = MpdfRenderer::create()->keepShortBlocksTogether(
            '<p>short</p><p class="x" style="color: red">short</p><p>' . str_repeat('long ', 50) . '</p>'
            . '<li>outer<ul><li>inner</li></ul></li>',
            100
        );

        $this->assertStringContainsString('<p style="page-break-inside: avoid">short</p>', $html);
        $this->assertStringContainsString('style="color: red; page-break-inside: avoid"', $html);
        $this->assertSame(3, substr_count($html, 'page-break-inside'), 'long paragraph and outer li untouched');
        $this->assertStringContainsString('<li>outer<ul><li style="page-break-inside: avoid">inner</li></ul></li>', $html);
    }

    public function testPdfResponse(): void
    {
        $response = PdfResponse::download('%PDF-1.4', 'Rechnung März');

        $this->assertSame('application/pdf', $response->getHeader('Content-Type'));
        $this->assertSame(
            'attachment; filename="Rechnung_Marz.pdf"; filename*=UTF-8\'\'Rechnung%20M%C3%A4rz.pdf',
            $response->getHeader('Content-Disposition')
        );
    }

    public function testNamedPageSizesBecomeSheetSizes(): void
    {
        $renderer = MpdfRenderer::create();
        $this->assertSame('@page { sheet-size: A4; margin: 2cm; }', $renderer->namedPageSizes('@page { size: A4; margin: 2cm; }'));
        $this->assertSame('@page :first{sheet-size: A5-L}', $renderer->namedPageSizes('@page :first{size: landscape a5}'));
        $this->assertSame('@page { size: 210mm 297mm; }', $renderer->namedPageSizes('@page { size: 210mm 297mm; }'));
        $this->assertSame('@page { size: landscape; }', $renderer->namedPageSizes('@page { size: landscape; }'));
        $this->assertSame('p { font-size: 10pt; }', $renderer->namedPageSizes('p { font-size: 10pt; }'));
    }

    public function testNamedPageSizeRendersOnePage(): void
    {
        MpdfRenderer::config()->set('css', []);
        $pdf = MpdfRenderer::create()->renderHtml(
            '<html><head><style>@page { size: A4; margin: 20mm; }</style></head><body><p>Hallo</p></body></html>'
        );

        $this->assertMatchesRegularExpression('#/Count 1\b#', $pdf);
    }
}

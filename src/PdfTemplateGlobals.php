<?php

namespace Atwx\Mpdf;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\TemplateGlobalProvider;

/**
 * Template helpers for PDF templates:
 *   <img src="$PdfFile('app/pdf/logo.svg')">            BASE_PATH-relative file
 *   <img src="$PdfFile('vendor/module:images/x.png')">  module resource
 * Both return absolute file paths, which mPDF reads directly from disk
 * (files outside the public folder work too).
 */
class PdfTemplateGlobals implements TemplateGlobalProvider
{
    public static function get_template_global_variables(): array
    {
        return [
            'PdfFile' => 'pdfFile',
        ];
    }

    public static function pdfFile(string $path): string
    {
        return Injector::inst()->get(MpdfRenderer::class)->resolveFile($path);
    }
}

<?php

namespace Atwx\Mpdf;

use SilverStripe\Control\HTTPResponse;

/**
 * HTTP responses for PDF data: shown in the browser (inline) or saved (download).
 */
class PdfResponse
{
    public static function inline(string $pdf, string $filename = 'document.pdf'): HTTPResponse
    {
        return static::create($pdf, $filename, 'inline');
    }

    public static function download(string $pdf, string $filename = 'document.pdf'): HTTPResponse
    {
        return static::create($pdf, $filename, 'attachment');
    }

    public static function create(string $pdf, string $filename, string $disposition): HTTPResponse
    {
        if (!str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }
        // ASCII fallback for old clients, the UTF-8 name for all others (RFC 6266).
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename) ?: 'document.pdf';
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $transliterated);

        $response = HTTPResponse::create($pdf, 200);
        $response->addHeader('Content-Type', 'application/pdf');
        $response->addHeader('Content-Length', (string) strlen($pdf));
        $response->addHeader(
            'Content-Disposition',
            sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $ascii, rawurlencode($filename))
        );
        return $response;
    }
}

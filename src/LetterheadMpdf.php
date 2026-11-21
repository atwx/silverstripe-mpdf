<?php

namespace Atwx\Mpdf;

use Mpdf\Mpdf;

/**
 * mPDF with letter paper (a PDF as page background, see SetDocTemplate()):
 * a page break that restarts the page numbering (<pagebreak resetpagenum="1" />)
 * also restarts the letter paper, so every document of a batch (e.g. one
 * certificate per person) starts on the paper's first page; further pages use
 * its last page.
 */
class LetterheadMpdf extends Mpdf
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mPDF API
    public function AddPage(...$args)
    {
        $resetPageNumber = $args[2] ?? '';
        if ((string) $resetPageNumber === '1' && $this->page > 0) {
            $this->RestartDocTemplate();
        }
        return parent::AddPage(...$args);
    }
}

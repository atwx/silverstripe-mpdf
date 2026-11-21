<?php

namespace Atwx\Mpdf;

/**
 * Turns HTML (or a Silverstripe template) into PDF data.
 *
 * The default implementation is MpdfRenderer; swap it via Injector if a
 * document needs another engine.
 */
interface PdfRenderer
{
    /**
     * Renders HTML to PDF data.
     *
     * @param array $options see MpdfRenderer::resolveOptions() for the keys
     */
    public function renderHtml(string $html, array $options = []): string;

    /**
     * Renders a template with the given data to PDF data.
     *
     * @param string|array $template template name(s) as accepted by renderWith()
     * @param mixed $data ModelData/DataObject, array or null
     */
    public function renderTemplate(string|array $template, mixed $data = null, array $options = []): string;
}

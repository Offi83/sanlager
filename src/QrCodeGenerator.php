<?php

namespace LagerApp;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Erzeugt QR-Code-Grafiken (als SVG) für Artikel.
 *
 * Der QR-Code enthält ausschließlich die übergebene Artikelnummer, keine
 * weiteren Artikeldaten – so bleibt er auch nach Änderungen am Artikel
 * (Name, Kategorie, ...) gültig.
 */
class QrCodeGenerator
{
    /**
     * Rendert einen QR-Code für $value als SVG-Markup.
     */
    public function generate(string $value): string
    {
        $builder = new Builder(
            writer: new SvgWriter(),
            data: $value,
            size: 500,
            margin: 10
        );

        $result = $builder->build();

        return $result->getString();
    }
}

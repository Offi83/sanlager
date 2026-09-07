<?php

namespace LagerApp;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeGenerator
{
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

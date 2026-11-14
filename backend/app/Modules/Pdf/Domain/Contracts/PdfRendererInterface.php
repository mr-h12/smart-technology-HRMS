<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

interface PdfRendererInterface
{
    public function pdf(string $html): string;
}

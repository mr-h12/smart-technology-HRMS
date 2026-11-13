<?php

declare(strict_types=1);

namespace App\Modules\Documents\Domain;

interface FormRenderer
{
    public function pdf(string $html): string;
}

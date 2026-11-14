<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use Spatie\Browsershot\Browsershot;

final class BrowsershotPdfRenderer implements PdfRendererInterface
{
    public function pdf(string $html): string
    {
        return Browsershot::html($html)
            ->setNodeModulePath('/opt/hrms-pdf/node_modules')
            ->setChromePath('/usr/local/bin/hrms-chromium')
            ->setOption('newHeadless', true)
            ->usePipe()
            ->noSandbox()
            ->disableJavascript()
            ->blockUrls(['http:', 'https:', 'ws:', 'wss:', 'ftp:'])
            ->addChromiumArguments([
                'disable-background-networking',
                'proxy-server' => '127.0.0.1:9',
                'proxy-bypass-list' => '<-loopback>',
                'host-resolver-rules' => 'MAP * ~NOTFOUND',
            ])
            ->format('A4')
            ->showBackground()
            ->timeout(45)
            ->pdf();
    }
}

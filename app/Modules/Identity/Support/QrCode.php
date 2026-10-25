<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Draws text as a QR code in SVG, on the server, so the secret in an authenticator link never goes to a third party.
 */
final class QrCode
{
    public function svg(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($text);
    }
}

<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Imagen QR real y escaneable. Nivel de corrección M: aguanta rayones leves
 * en una credencial impresa sin hacer el código demasiado denso.
 */
final class QrImage
{
    private const INK = '#0E0A18';

    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
            'connectPaths' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'moduleValues' => [],
        ]);

        $svg = (new QRCode($options))->render($data);

        // Color de tinta y accesibilidad
        return str_replace(
            ['fill="#000"', '<svg '],
            ['fill="'.self::INK.'"', '<svg role="img" aria-label="Código QR '.e($data).'" shape-rendering="crispEdges" '],
            $svg,
        );
    }

    /** PNG de alta resolución, apto para imprimir (aprox. 1000 px por lado). */
    public static function png(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'scale' => 32,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
        ]);

        return (new QRCode($options))->render($data);
    }
}

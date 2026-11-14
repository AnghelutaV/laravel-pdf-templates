<?php

namespace AnghelutaV\PdfTemplates;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\HtmlString;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/**
 * QR codes and barcodes for HTML templates, as <img> tags with an SVG data URL (dompdf draws them as vectors):
 *   {{ order.url|qrcode }}                 {{ order.url|qrcode(140) }}      size in px, error correction L|M|Q|H
 *   {{ sku|barcode }}                      {{ isbn|barcode("ean13", 50) }}  type, height in px, bar width
 * Data that the type cannot encode (letters in an EAN-13…) gives an empty string, not an error.
 */
class Codes
{
    const BARCODES = [
        'code128' => BarcodeGeneratorSVG::TYPE_CODE_128,
        'code39' => BarcodeGeneratorSVG::TYPE_CODE_39,
        'code93' => BarcodeGeneratorSVG::TYPE_CODE_93,
        'ean13' => BarcodeGeneratorSVG::TYPE_EAN_13,
        'ean8' => BarcodeGeneratorSVG::TYPE_EAN_8,
        'upca' => BarcodeGeneratorSVG::TYPE_UPC_A,
        'upce' => BarcodeGeneratorSVG::TYPE_UPC_E,
        'itf14' => BarcodeGeneratorSVG::TYPE_ITF_14,
        'i25' => BarcodeGeneratorSVG::TYPE_INTERLEAVED_2_5,
        'codabar' => BarcodeGeneratorSVG::TYPE_CODABAR,
    ];

    // "140, h" → ['140', 'h']: the filter argument, Jinja's ("ean13", 50) included
    public static function args(?string $arg): array
    {
        return $arg === null || trim($arg) === ''
            ? []
            : array_map(fn ($part) => trim($part, " \t\"'"), explode(',', $arg));
    }

    public function qr(mixed $value, ?string $arg = null): HtmlString
    {
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '') {
            return new HtmlString('');
        }
        [$size, $ecc] = self::args($arg) + [null, null];
        $size = max(16, min(1000, (int) ($size ?: 120)));

        try {
            $svg = (new QRCode(new QROptions([
                'outputType' => QROutputInterface::MARKUP_SVG,
                'outputBase64' => true,
                'eccLevel' => match (strtoupper((string) $ecc)) {
                    'L' => EccLevel::L,
                    'Q' => EccLevel::Q,
                    'H' => EccLevel::H,
                    default => EccLevel::M,
                },
                'addQuietzone' => true,
                'quietzoneSize' => 1,
                'drawLightModules' => false,
                'svgUseFillAttributes' => true,
                'connectPaths' => true,
            ])))->render($text);
        } catch (Throwable) {
            return new HtmlString('');
        }

        return new HtmlString('<img class="pt-qrcode" src="'.$svg.'" width="'.$size.'" height="'.$size.'" style="width: '.$size.'px; height: '.$size.'px;" alt="">');
    }

    public function barcode(mixed $value, ?string $arg = null): HtmlString
    {
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '') {
            return new HtmlString('');
        }
        [$type, $height, $bar] = self::args($arg) + [null, null, null];
        $type = self::BARCODES[strtolower(str_replace(['-', '_', ' '], '', (string) ($type ?: 'code128')))] ?? BarcodeGeneratorSVG::TYPE_CODE_128;
        $height = max(10, min(400, (int) ($height ?: 40)));
        $bar = max(0.5, min(10, (float) ($bar ?: 2)));

        try {
            $svg = (new BarcodeGeneratorSVG)->getBarcode($text, $type, $bar, $height);
        } catch (Throwable) {
            return new HtmlString('');
        }
        $width = preg_match('/<svg[^>]*\swidth="([\d.]+)"/', $svg, $m) ? (float) $m[1] : $height * 3;

        return new HtmlString('<img class="pt-barcode" src="data:image/svg+xml;base64,'.base64_encode($svg).'" width="'.round($width).'" height="'.$height.'" style="width: '.round($width, 2).'px; height: '.$height.'px;" alt="">');
    }
}

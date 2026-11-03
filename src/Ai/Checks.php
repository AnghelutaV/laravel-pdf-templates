<?php

namespace AnghelutaV\PdfTemplates\Ai;

use AnghelutaV\PdfTemplates\TemplateEngine;
use InvalidArgumentException;

// Checks of the model's answer, run on the server; the problems are sent back to the model once to fix them
class Checks
{
    const PDFME_TYPES = ['text', 'multiVariableText', 'table', 'list', 'image', 'svg', 'line', 'rectangle', 'ellipse', 'qrcode', 'code128', 'ean13', 'date', 'checkbox'];

    /** @return list<string> template syntax errors (HTML mode) */
    public static function html(string $html, ?string $sampleData): array
    {
        $data = json_decode($sampleData ?: '{}', true);

        try {
            app(TemplateEngine::class)->render($html, is_array($data) ? $data : []);
        } catch (InvalidArgumentException $e) {
            return ['html: '.$e->getMessage()];
        }

        return [];
    }

    /** @return list<string> structure problems of a pdfme template */
    public static function pdfme(string $json): array
    {
        $template = json_decode($json, true);
        if (! is_array($template)) {
            return ['Invalid JSON: '.json_last_error_msg()];
        }
        if (! isset($template['basePdf'])) {
            return ['Missing basePdf'];
        }
        if (! isset($template['schemas']) || ! is_array($template['schemas']) || ! array_is_list($template['schemas'])) {
            return ['schemas must be an array of pages (arrays of fields)'];
        }

        $page = is_array($template['basePdf']) ? $template['basePdf'] : null;
        $errors = [];
        $names = [];

        foreach ($template['schemas'] as $p => $fields) {
            if (! is_array($fields) || ! array_is_list($fields)) {
                $errors[] = 'Page '.($p + 1).': must be an array of fields';

                continue;
            }
            foreach ($fields as $i => $field) {
                $name = is_array($field) ? ($field['name'] ?? null) : null;
                $label = 'Page '.($p + 1).' field '.($name ?? "#{$i}");

                if (! is_string($name) || $name === '') {
                    $errors[] = "{$label}: missing name";
                } elseif (isset($names[$name])) {
                    $errors[] = "{$label}: duplicate name";
                }
                $names[$name] = true;

                if (! in_array($field['type'] ?? null, self::PDFME_TYPES, true)) {
                    $errors[] = "{$label}: unknown type ".json_encode($field['type'] ?? null).' (use '.implode(', ', self::PDFME_TYPES).')';
                }
                if (! is_numeric($field['position']['x'] ?? null) || ! is_numeric($field['position']['y'] ?? null)) {
                    $errors[] = "{$label}: position must be {x, y} numbers";

                    continue;
                }
                if (! is_numeric($field['width'] ?? null) || ! is_numeric($field['height'] ?? null)) {
                    $errors[] = "{$label}: width and height must be numbers";

                    continue;
                }
                if ($page && ($field['position']['x'] + $field['width'] > ($page['width'] ?? INF) + 0.5
                    || $field['position']['y'] + $field['height'] > ($page['height'] ?? INF) + 0.5)) {
                    $errors[] = "{$label}: outside the page";
                }
            }
        }

        return $errors;
    }
}

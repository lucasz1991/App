<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

/** Finite diagnostic vocabulary. Raw evidence strings never cross this boundary. */
final class OutlookUniversalDiagnosticDetails
{
    public const CHECKPOINTS = [
        'bundle.contract',
        'html.parse', 'scope.boundary', 'markers.count', 'markers.tag',
        'markers.binding', 'shell.rows', 'shell.width', 'shell.align',
        'shell.hidden', 'grid.author', 'grid.columns', 'grid.rowspan',
        'grid.train', 'grid.legal', 'markers.position', 'shell.count',
        'material.hidden', 'material.float', 'material.content', 'dispatch.count',
        'images.src', 'images.width', 'images.height', 'images.count',
        'images.order', 'images.origin', 'links.content', 'links.scheme',
        'links.count', 'borders.structure', 'borders.invalid', 'borders.image', 'borders.cta', 'line.visibility', 'caps.structure', 'caps.visibility', 'colors.line', 'colors.background-image', 'colors.conflict', 'colors.legal',
        'colors.dispatch', 'colors.corners', 'state.present', 'state.kind',
        'state.version', 'state.unreadable', 'source.invalid', 'evidence.topology',
        'evidence.images', 'evidence.links', 'evidence.colors', 'evidence.borderColors', 'evidence.text',
        'compose.type', 'quote.bytes', 'author.signature', 'author.combined',
        'readback.unknown',
    ];

    public const KEYS = [
        'bundle.template',
        'bundle.contract',
        'html.parse', 'scope.boundary', 'markers.count', 'markers.tag',
        'markers.binding', 'shell.rows', 'shell.width', 'shell.align',
        'shell.hidden', 'grid.author', 'grid.columns', 'grid.rowspan',
        'grid.train', 'grid.legal', 'markers.position', 'shell.count',
        'material.hidden', 'material.float', 'material.content', 'dispatch.count',
        'images.src', 'images.width', 'images.height', 'images.count',
        'images.order', 'images.origin', 'links.content', 'links.scheme',
        'links.count', 'borders.structure', 'borders.invalid', 'borders.image', 'borders.cta', 'line.visibility', 'caps.structure', 'caps.visibility', 'colors.line', 'colors.background-image', 'colors.conflict', 'colors.legal',
        'colors.dispatch', 'colors.corners', 'state.present', 'state.kind',
        'state.version', 'state.unreadable', 'source.invalid', 'evidence.topology',
        'evidence.images', 'evidence.links', 'evidence.colors', 'evidence.borderColors', 'evidence.text',
        'compose.type', 'quote.bytes', 'author.signature', 'author.combined',
        'readback.unknown', 'evidence.images.count', 'evidence.images.src', 'evidence.images.width',
        'evidence.images.height', 'evidence.links.count', 'evidence.links.href', 'evidence.colors.count',
        'evidence.colors.value', 'evidence.borderColors.count', 'evidence.borderColors.value', 'evidence.text.length', 'evidence.text.sha256',
    ];

    public static function valid(array $input): bool
    {
        if (array_diff(array_keys($input), ['checkpoint', 'difference']) !== []) {
            return false;
        }
        if (array_key_exists('checkpoint', $input) && ! in_array($input['checkpoint'], self::CHECKPOINTS, true)) {
            return false;
        }
        if (! array_key_exists('difference', $input) || $input['difference'] === null) {
            return true;
        }
        $difference = $input['difference'];

        return is_array($difference) && count($difference) === 4
            && array_diff(array_keys($difference), ['key', 'index', 'expected', 'actual']) === []
            && in_array($difference['key'] ?? null, self::KEYS, true)
            && array_key_exists('index', $difference)
            && ($difference['index'] === null || is_int($difference['index']) && $difference['index'] >= 0 && $difference['index'] <= 4095)
            && array_key_exists('expected', $difference) && self::atom($difference['expected'])
            && array_key_exists('actual', $difference) && self::atom($difference['actual']);
    }

    private static function atom(mixed $value): bool
    {
        return $value === null || is_bool($value)
            || is_int($value) && abs($value) <= 1000000
            || is_string($value) && preg_match('/\Asha256:[0-9a-f]{64}:(?:0|[1-9][0-9]{0,6})\z/', $value)
                && (int) substr($value, 72) <= 1000000;
    }
}

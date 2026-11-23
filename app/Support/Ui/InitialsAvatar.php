<?php

namespace App\Support\Ui;

/**
 * Initialen-Avatar als SVG-data-URI, komplett lokal erzeugt.
 *
 * Ersetzt Jetstreams Rueckfall auf ui-avatars.com: Dort wuerde bei jedem
 * Seitenaufruf der Name einer Person an einen Drittanbieter gesendet.
 * Farben entsprechen dem bisherigen Jetstream-Look (7F9CF5 auf EBF4FF),
 * damit sich vorhandene Oberflaechen optisch nicht aendern.
 */
final class InitialsAvatar
{
    public static function initials(?string $name): string
    {
        $parts = preg_split('/[\s,]+/u', trim((string) $name)) ?: [];

        $initials = collect($parts)
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }

    public static function dataUri(?string $name): string
    {
        $text = htmlspecialchars(self::initials($name), ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            .'<rect width="96" height="96" fill="#EBF4FF"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#7F9CF5" '
            .'font-family="Inter,Segoe UI,Arial,sans-serif" font-size="38" font-weight="600">'
            .$text.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

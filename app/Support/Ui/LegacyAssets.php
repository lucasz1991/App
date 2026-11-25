<?php

namespace App\Support\Ui;

/**
 * Einzige Entscheidung, ob eine Seite die Legacy-Assets laedt
 * (build/css/tailwind.min.css, ApexCharts, Trix, Dropzone, FullCalendar).
 *
 * Die nativen Planungsseiten (Arbeitsplatz-Ansichten "cases" und "shifts")
 * kommen ohne diese Schicht aus. layouts.head-css und layouts.vendor-scripts
 * fragen beide hier nach, damit CSS- und Skriptseite nie auseinanderlaufen.
 */
final class LegacyAssets
{
    /** Arbeitsplatz-Ansichten, die ohne Legacy-Assets gerendert werden. */
    public const NATIVE_PAGES = ['cases', 'shifts'];

    public static function enabled(): bool
    {
        return ! self::nativePlanning();
    }

    public static function nativePlanning(): bool
    {
        $request = request();

        return $request->routeIs('operations.page')
            && in_array($request->route('page'), self::NATIVE_PAGES, true);
    }
}

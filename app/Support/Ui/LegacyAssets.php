<?php

namespace App\Support\Ui;

/**
 * Einzige Entscheidung, ob eine Seite die Legacy-Assets laedt
 * (resources/css/legacy-allowlist.css – Rest der frueheren
 * build/css/tailwind.min.css –, Trix, Dropzone, FullCalendar).
 *
 * Die nativen Planungsseiten (Arbeitsplatz-Ansichten "cases" und "shifts")
 * kommen ohne diese Schicht aus. layouts.head-css fragt hier nach.
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

<?php

namespace App\Support\Dashboard;

use App\Models\DashboardWidgetPlacement;
use App\Models\User;

/**
 * Verschmilzt WidgetRegistry (was DARF diese Person sehen) mit den
 * gespeicherten dashboard_widget_placements (was hat sie WIE angeordnet).
 * Ein Widget, das noch nie angefasst wurde, erscheint mit den Vorgaben aus
 * der Registry - erst eine Interaktion legt eine Zeile an.
 */
final class DashboardLayout
{
    /**
     * Alle fuer die Person erlaubten Widgets, sichtbar UND ausgeblendet,
     * sortiert nach Position. Ausgeblendete stehen hinten und speisen die
     * "Widget hinzufuegen"-Liste.
     *
     * @return list<array{key:string,title:string,description:string,icon:string,section:string,defaultSize:'sm'|'lg',size:'sm'|'lg',hidden:bool,position:int}>
     */
    public static function forUser(User $user): array
    {
        $available = WidgetRegistry::availableFor($user);
        $placements = DashboardWidgetPlacement::where('user_id', $user->id)
            ->whereIn('widget_key', array_keys($available))
            ->get()
            ->keyBy('widget_key');

        $items = [];
        $index = 0;
        foreach ($available as $key => $widget) {
            $placement = $placements->get($key);
            $items[] = [
                'key' => $key,
                'title' => $widget['title'],
                'description' => $widget['description'],
                'icon' => $widget['icon'],
                'section' => $widget['section'],
                'defaultSize' => $widget['defaultSize'],
                'size' => $placement->size ?? $widget['defaultSize'],
                'hidden' => $placement ? $placement->hidden : ! $widget['defaultVisible'],
                // Nie angefasste Widgets landen hinter jeder bewusst gesetzten
                // Position, behalten aber die Registry-Reihenfolge zueinander.
                'position' => $placement?->position ?? (10_000 + $index),
            ];
            $index++;
        }

        usort($items, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $items;
    }

    /** @return list<array{key:string,title:string,description:string,icon:string,section:string,defaultSize:'sm'|'lg',size:'sm'|'lg',hidden:bool,position:int}> */
    public static function visible(User $user): array
    {
        return array_values(array_filter(self::forUser($user), static fn (array $item) => ! $item['hidden']));
    }

    /** @return list<array{key:string,title:string,description:string,icon:string,section:string,defaultSize:'sm'|'lg',size:'sm'|'lg',hidden:bool,position:int}> */
    public static function hiddenCatalog(User $user): array
    {
        return array_values(array_filter(self::forUser($user), static fn (array $item) => $item['hidden']));
    }

    /**
     * Neue Reihenfolge nach einem Drag&Drop. Schluessel, die diese Person
     * gar nicht sehen darf, werden still uebergangen statt einen Fehler zu
     * werfen - ein manipuliertes Payload kann so nichts fuer andere setzen.
     *
     * @param  list<string>  $orderedKeys
     */
    public static function reorder(User $user, array $orderedKeys): void
    {
        $allowed = array_keys(WidgetRegistry::availableFor($user));
        $position = 0;
        foreach ($orderedKeys as $key) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            self::upsert($user, $key, ['position' => $position]);
            $position++;
        }
    }

    public static function setHidden(User $user, string $key, bool $hidden): void
    {
        if (! array_key_exists($key, WidgetRegistry::availableFor($user))) {
            return;
        }
        self::upsert($user, $key, ['hidden' => $hidden]);
    }

    public static function setSize(User $user, string $key, string $size): void
    {
        if (! in_array($size, ['sm', 'lg'], true) || ! array_key_exists($key, WidgetRegistry::availableFor($user))) {
            return;
        }
        self::upsert($user, $key, ['size' => $size]);
    }

    /** @param array<string, mixed> $changes */
    private static function upsert(User $user, string $key, array $changes): void
    {
        $widget = WidgetRegistry::find($key);
        $existing = DashboardWidgetPlacement::where('user_id', $user->id)->where('widget_key', $key)->first();

        DashboardWidgetPlacement::updateOrCreate(
            ['user_id' => $user->id, 'widget_key' => $key],
            array_merge([
                'size' => $existing?->size ?? $widget['defaultSize'],
                'hidden' => $existing?->hidden ?? ! $widget['defaultVisible'],
                'position' => $existing?->position ?? (DashboardWidgetPlacement::where('user_id', $user->id)->max('position') ?? -1) + 1,
            ], $changes),
        );
    }
}

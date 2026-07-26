<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Wer hat welche Seite schon gesehen?
 *
 * "Gesehen" heisst: die Seite wurde fuer diesen Nutzer gerendert. Der erste
 * Aufruf wird sofort beim Rendern vermerkt — dadurch erscheint jedes Intro
 * (Willkommensmodal, automatische Seiteninfo) genau einmal, auch wenn der
 * Nutzer es sofort wegklickt oder die Seite verlaesst.
 */
final class PageViews
{
    /**
     * Vermerkt den Aufruf und meldet, ob es der ERSTE war.
     */
    public static function firstVisit(?User $user, string $pageKey): bool
    {
        if (! $user || ! $user->exists) {
            return false;
        }

        $seen = DB::table('user_page_views')
            ->where('user_id', $user->id)
            ->where('page_key', $pageKey)
            ->exists();

        if ($seen) {
            return false;
        }

        try {
            DB::table('user_page_views')->insert([
                'user_id' => $user->id,
                'page_key' => $pageKey,
                'first_seen_at' => now(),
            ]);
        } catch (QueryException) {
            // Parallelanfrage war schneller (Unique-Index) — dann war dieser
            // Aufruf nicht der erste.
            return false;
        }

        return true;
    }

    public static function hasSeen(?User $user, string $pageKey): bool
    {
        if (! $user || ! $user->exists) {
            return false;
        }

        return DB::table('user_page_views')
            ->where('user_id', $user->id)
            ->where('page_key', $pageKey)
            ->exists();
    }
}

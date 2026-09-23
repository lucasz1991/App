<?php

namespace App\Services\Dropbox;

final class SyncContext
{
    private static int $depth = 0;

    public static function importing(): bool
    {
        return self::$depth > 0;
    }

    public static function import(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}

<?php

namespace App\Support\Database;

use Closure;

/**
 * Antworten auf Schema-Fragen (Tabelle/Spalte vorhanden?) für die Dauer einer Anfrage bzw. eines Jobs.
 *
 * Optionale Erweiterungen prüfen ihr Schema in Schleifen (Eignung je Person und Schicht); ohne
 * Merkliste stellte allein die Besetzungsvorschau über 30.000 identische information_schema-Abfragen.
 * Jede DDL auf der Verbindung leert die Liste (CachedSchemaBuilder + QueryExecuted-Hörer).
 */
final class SchemaIntrospectionCache
{
    /** @var array<string, mixed> */
    private array $answers = [];

    public function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->answers)) {
            $this->answers[$key] = $resolve();
        }

        return $this->answers[$key];
    }

    public function flush(): void
    {
        $this->answers = [];
    }

    public static function isSchemaChange(string $sql): bool
    {
        return preg_match('/^\s*(create|alter|drop|rename)\b/i', $sql) === 1;
    }
}

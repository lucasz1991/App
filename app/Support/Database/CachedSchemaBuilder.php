<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Builder;

/**
 * Schema-Fassade mit Merkliste für reine Existenzfragen. Alles andere wird unverändert an den
 * Treiber-Builder weitergereicht; jede Methode, die nicht nur liest (create, table, drop …),
 * leert vorher die Merkliste.
 *
 * Nicht final: Tests mocken die Schema-Fassade (Schema::shouldReceive).
 *
 * @mixin Builder
 */
class CachedSchemaBuilder
{
    public function __construct(
        private readonly Builder $builder,
        private readonly SchemaIntrospectionCache $cache,
        private readonly string $connection,
    ) {}

    public function hasTable($table): bool
    {
        return $this->cache->remember($this->key('table', $table), fn () => $this->builder->hasTable($table));
    }

    /** @return list<array<string, mixed>> */
    public function getColumns($table): array
    {
        return $this->cache->remember($this->key('columns', $table), fn () => $this->builder->getColumns($table));
    }

    /** @return list<string> */
    public function getColumnListing($table): array
    {
        return array_column($this->getColumns($table), 'name');
    }

    // Gleiche Semantik wie Illuminate\Database\Schema\Builder, nur auf der gemerkten Spaltenliste.
    public function hasColumn($table, $column): bool
    {
        return in_array(strtolower($column), array_map(strtolower(...), $this->getColumnListing($table)), true);
    }

    public function hasColumns($table, array $columns): bool
    {
        $listing = array_map(strtolower(...), $this->getColumnListing($table));
        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $listing, true)) {
                return false;
            }
        }

        return true;
    }

    public function getTables($schema = null): array
    {
        return $this->cache->remember($this->key('tables', json_encode($schema)), fn () => $this->builder->getTables($schema));
    }

    /** @return list<string> */
    public function getTableListing($schema = null, $schemaQualified = true): array
    {
        return array_column($this->getTables($schema), $schemaQualified ? 'schema_qualified_name' : 'name');
    }

    public function __call(string $method, array $arguments): mixed
    {
        // Lesende Abfragen (get…/has…) laufen ungemerkt durch; alles andere kann das Schema ändern.
        if (! str_starts_with($method, 'get') && ! str_starts_with($method, 'has')) {
            $this->cache->flush();
        }

        return $this->builder->{$method}(...$arguments);
    }

    private function key(string $kind, mixed $name): string
    {
        return $this->connection."\0".$kind."\0".strtolower((string) $name);
    }
}

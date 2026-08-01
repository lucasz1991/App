<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const SCHEDULE_COLUMNS = [
        'starts_at' => '__rt_starts_at_utc_20260801',
        'ends_at' => '__rt_ends_at_utc_20260801',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['orders', 'shifts'] as $table) {
            $this->convertTable($table);
        }
    }

    public function down(): void
    {
        // The UTC conversion is intentionally irreversible. Converting the
        // values back would recreate timezone-dependent wall times and the
        // limited MySQL TIMESTAMP range. The base migration removes the tables
        // on a complete rollback.
    }

    private function convertTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columnTypes = collect(array_keys(self::SCHEDULE_COLUMNS))
            ->mapWithKeys(fn (string $column): array => [$column => $this->columnType($table, $column)]);

        $temporaryColumns = collect(self::SCHEDULE_COLUMNS)
            ->filter(fn (string $temporaryColumn): bool => $this->columnType($table, $temporaryColumn) !== null);

        if ($columnTypes->every(fn (?string $type): bool => $type !== 'timestamp') && $temporaryColumns->isEmpty()) {
            return;
        }

        foreach (self::SCHEDULE_COLUMNS as $sourceColumn => $temporaryColumn) {
            if ($columnTypes->get($sourceColumn) !== 'timestamp') {
                continue;
            }

            if ($this->columnType($table, $temporaryColumn) === null) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s DATETIME NULL',
                    $this->quoteIdentifier($this->physicalTableName($table)),
                    $this->quoteIdentifier($temporaryColumn),
                ));
            }

            DB::table($table)
                ->select(['id', 'timezone', $sourceColumn])
                ->orderBy('id')
                ->chunkById(250, function ($rows) use ($table, $sourceColumn, $temporaryColumn): void {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([
                                $temporaryColumn => $this->wallTimeToUtc(
                                    $row->{$sourceColumn},
                                    $row->timezone,
                                    $table,
                                    (int) $row->id,
                                    $sourceColumn,
                                ),
                            ]);
                    }
                }, 'id');
        }

        foreach (self::SCHEDULE_COLUMNS as $sourceColumn => $temporaryColumn) {
            if ($columnTypes->get($sourceColumn) === 'timestamp') {
                DB::statement(sprintf(
                    'ALTER TABLE %s MODIFY COLUMN %s DATETIME NOT NULL',
                    $this->quoteIdentifier($this->physicalTableName($table)),
                    $this->quoteIdentifier($sourceColumn),
                ));
            }

            if ($this->columnType($table, $temporaryColumn) === null) {
                continue;
            }

            DB::statement(sprintf(
                'UPDATE %s SET %s = %s WHERE %s IS NOT NULL',
                $this->quoteIdentifier($this->physicalTableName($table)),
                $this->quoteIdentifier($sourceColumn),
                $this->quoteIdentifier($temporaryColumn),
                $this->quoteIdentifier($temporaryColumn),
            ));

            DB::statement(sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteIdentifier($this->physicalTableName($table)),
                $this->quoteIdentifier($temporaryColumn),
            ));
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        $metadata = DB::selectOne(
            <<<'SQL'
                SELECT DATA_TYPE AS data_type
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
                SQL,
            [$this->physicalTableName($table), $column],
        );

        return $metadata === null ? null : strtolower((string) $metadata->data_type);
    }

    private function wallTimeToUtc(
        mixed $value,
        mixed $timezone,
        string $table,
        int $id,
        string $column,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = filled($timezone)
            ? (string) $timezone
            : (string) config('app.timezone', 'Europe/Berlin');

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, $timezone)
                ->utc()
                ->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                'Could not convert %s.%s for row %d from timezone "%s" to UTC.',
                $table,
                $column,
                $id,
                $timezone,
            ), previous: $exception);
        }
    }

    private function physicalTableName(string $table): string
    {
        return DB::connection()->getTablePrefix().$table;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};

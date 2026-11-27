<?php

namespace App\Support;

use App\Models\File;
use App\Models\ManagedDocument;
use Illuminate\Support\Collection;

final class FileExplorer
{
    public const LAYOUTS = ['grid', 'list', 'details'];

    public const SORT_COLUMNS = ['name', 'date', 'type', 'size'];

    public static function type(?File $file): string
    {
        $extension = strtolower(pathinfo($file?->name_with_extension ?? '', PATHINFO_EXTENSION));

        return self::normalizeType($extension) ?: 'unknown';
    }

    public static function normalizeType(string $type): string
    {
        return preg_match('/\A[a-z0-9]{1,12}\z/', $type) === 1 ? $type : '';
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'folder' => __('file_explorer.folder'),
            'unknown' => __('file_explorer.unknown_type'),
            default => strtoupper($type),
        };
    }

    /**
     * @param iterable<File|null> $files
     * @return array<string, string>
     */
    public static function fileTypes(iterable $files, bool $includeFolders = false, string $current = ''): array
    {
        $types = [];

        foreach ($files as $file) {
            $type = self::type($file);
            $types[$type] = self::typeLabel($type);
        }

        if ($type = self::normalizeType($current)) {
            $types[$type] = self::typeLabel($type);
        }

        ksort($types, SORT_NATURAL);

        return $includeFolders ? ['folder' => self::typeLabel('folder')] + $types : $types;
    }

    public static function matchesSearch(string $name, string $search): bool
    {
        return str_contains(mb_strtolower($name), mb_strtolower(trim($search)));
    }

    public static function filterFiles(Collection $files, string $search, string $type): Collection
    {
        return $files
            ->filter(fn (File $file): bool => self::matchesSearch($file->name_with_extension, $search)
                && ($type === '' || self::type($file) === $type))
            ->values();
    }

    public static function filterDocuments(Collection $documents, string $search, string $type): Collection
    {
        return $documents
            ->filter(fn (ManagedDocument $document): bool => (
                self::matchesSearch((string) $document->title, $search)
                || self::matchesSearch((string) $document->description, $search)
                || self::matchesSearch($document->currentVersion?->file?->name_with_extension ?? '', $search)
            ) && ($type === '' || self::type($document->currentVersion?->file) === $type))
            ->values();
    }

    public static function sortFiles(Collection $files, string $sortBy, string $sortDirection): Collection
    {
        return self::sort($files, $sortBy, $sortDirection, fn (File $file): array => [
            'name' => $file->name_with_extension,
            'date' => $file->updated_at?->getTimestamp(),
            'type' => self::type($file),
            'size' => $file->size === null ? null : (int) $file->size,
        ]);
    }

    public static function sortDocuments(Collection $documents, string $sortBy, string $sortDirection): Collection
    {
        return self::sort($documents, $sortBy, $sortDirection, function (ManagedDocument $document): array {
            $file = $document->currentVersion?->file;

            return [
                'name' => (string) $document->title,
                'date' => $document->content_updated_at?->getTimestamp(),
                'type' => $file ? self::type($file) : null,
                'size' => $file?->size === null ? null : (int) $file->size,
            ];
        });
    }

    private static function sort(Collection $items, string $column, string $direction, callable $attributes): Collection
    {
        $column = in_array($column, self::SORT_COLUMNS, true) ? $column : 'name';
        $keys = $items->map($attributes);

        return $items->keys()
            ->sort(function ($left, $right) use ($keys, $column, $direction): int {
                $a = $keys[$left][$column];
                $b = $keys[$right][$column];
                if ($a === null || $b === null) {
                    $comparison = ($a === null) <=> ($b === null);
                } elseif (is_string($a)) {
                    $comparison = strnatcmp(mb_strtolower($a), mb_strtolower($b)) * ($direction === 'desc' ? -1 : 1);
                } else {
                    $comparison = ($a <=> $b) * ($direction === 'desc' ? -1 : 1);
                }

                return $comparison ?: strnatcmp(mb_strtolower($keys[$left]['name']), mb_strtolower($keys[$right]['name']));
            })
            ->map(fn ($key) => $items[$key])
            ->values();
    }
}

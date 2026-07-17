<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class PivotSorter
{
    public static function reorder(
        $model,
        string $relationMethod,
        $movedItem,
        int $newPosition,
        string $pivotOrderKey,
        array &$localArray,
        ?callable $mapModelToLocal = null
    ): void {
        $parsed = self::parseMovedItem($movedItem);
        if (!$parsed) {
            return;
        }

        if (!method_exists($model, $relationMethod)) {
            return;
        }

        $current = collect($localArray);
        $filtered = $current
            ->reject(fn ($i) => (int) ($i['id'] ?? 0) === $parsed['id'])
            ->values();

        $reordered = collect();
        foreach ($filtered as $index => $item) {
            if ($index === $newPosition) {
                $existing = $current->firstWhere('id', $parsed['id']) ?? [];
                $reordered->push(array_replace($existing, $parsed));
            }
            $reordered->push($item);
        }

        if ($newPosition >= $filtered->count()) {
            $existing = $current->firstWhere('id', $parsed['id']) ?? [];
            $reordered->push(array_replace($existing, $parsed));
        }

        $syncData = $reordered->mapWithKeys(
            fn ($item, $index) => [(int) $item['id'] => [$pivotOrderKey => $index]]
        )->toArray();

        $relation = $model->{$relationMethod}();
        $relation->sync($syncData);

        $fresh = $relation->get();

        $localArray = $fresh
            ->map(function ($item) use ($pivotOrderKey, $mapModelToLocal) {
                if ($mapModelToLocal) {
                    return $mapModelToLocal($item, $pivotOrderKey);
                }

                return [
                    'id' => $item->id,
                    'name' => $item->name ?? null,
                    $pivotOrderKey => $item->pivot->{$pivotOrderKey},
                ];
            })
            ->sortBy(fn ($row) => $row[$pivotOrderKey])
            ->values()
            ->toArray();
    }

    public static function reorderByOrderColumn(
        string $modelClass,
        $movedItem,
        int $newPosition,
        string $orderColumn = 'order_id',
        ?callable $scope = null
    ): void {
        if (!is_subclass_of($modelClass, Model::class)) {
            return;
        }

        $parsed = self::parseMovedItem($movedItem);
        if (!$parsed) {
            return;
        }

        $query = $modelClass::query();
        if ($scope) {
            $scope($query);
        }

        $items = $query->orderBy($orderColumn)->get();
        $moved = $items->firstWhere('id', $parsed['id']);
        if (!$moved) {
            return;
        }

        $filtered = $items->reject(fn ($i) => (int) $i->id === $parsed['id'])->values();
        $reordered = collect();

        foreach ($filtered as $index => $item) {
            if ($index === $newPosition) {
                $reordered->push($moved);
            }
            $reordered->push($item);
        }

        if ($newPosition >= $filtered->count()) {
            $reordered->push($moved);
        }

        foreach ($reordered as $index => $item) {
            $modelClass::whereKey($item->id)->update([$orderColumn => $index]);
        }
    }

    public static function reindexOrderColumn(
        string $modelClass,
        string $orderColumn = 'order_id',
        ?callable $scope = null
    ): void {
        if (!is_subclass_of($modelClass, Model::class)) {
            return;
        }

        $query = $modelClass::query();
        if ($scope) {
            $scope($query);
        }

        $items = $query->orderBy($orderColumn)->get();
        foreach ($items as $index => $item) {
            $modelClass::whereKey($item->id)->update([$orderColumn => $index]);
        }
    }

    private static function parseMovedItem($movedItem): ?array
    {
        if (is_numeric($movedItem)) {
            return ['id' => (int) $movedItem];
        }

        if (is_array($movedItem) && isset($movedItem['id'])) {
            return ['id' => (int) $movedItem['id']];
        }

        if (is_array($movedItem) && array_key_exists('item', $movedItem)) {
            return ['id' => (int) $movedItem['item']];
        }

        return null;
    }
}

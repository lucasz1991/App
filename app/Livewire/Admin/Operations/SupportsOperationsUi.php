<?php

namespace App\Livewire\Admin\Operations;

use App\Support\Operations\OperationsAccess;
use BackedEnum;
use Illuminate\Support\Str;

trait SupportsOperationsUi
{
    protected function ensureAdmin(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return array<int, array{value: string, label: string}>
     */
    protected function enumOptions(string $enumClass): array
    {
        return collect($enumClass::cases())
            ->map(fn (BackedEnum $case): array => [
                'value' => (string) $case->value,
                'label' => method_exists($case, 'label')
                    ? (string) $case->label()
                    : Str::headline((string) $case->value),
            ])
            ->values()
            ->all();
    }

    /** @param class-string<BackedEnum> $enumClass */
    protected function enumDefault(string $enumClass, string $preferred): string
    {
        $values = collect($enumClass::cases())->map(fn (BackedEnum $case): string => (string) $case->value);

        return $values->contains($preferred) ? $preferred : (string) $values->first();
    }
}

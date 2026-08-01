<?php

namespace App\Livewire\Admin\Operations;

use BackedEnum;
use Illuminate\Support\Str;

trait SupportsOperationsUi
{
    protected function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
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

    protected function enumLabel(BackedEnum|string|null $value): string
    {
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label')
                ? (string) $value->label()
                : Str::headline((string) $value->value);
        }

        return filled($value) ? Str::headline((string) $value) : 'Nicht gesetzt';
    }

    protected function statusClasses(BackedEnum|string|null $value): string
    {
        $key = $value instanceof BackedEnum ? (string) $value->value : (string) $value;

        return match ($key) {
            'confirmed', 'completed', 'active', 'invoiced' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
            'planned', 'requested', 'open', 'pending' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
            'in_progress' => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-500/10 dark:text-sky-300',
            'cancelled', 'declined', 'inactive' => 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
            default => 'border-rose-200 bg-rose-50 text-rt-red dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-300',
        };
    }
}

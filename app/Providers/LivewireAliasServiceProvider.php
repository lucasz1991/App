<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Übergangsnamen für umgezogene Livewire-Komponenten (UI-Aufräumplan Phase 7).
 *
 * Offene Seiten und gespeicherte Snapshots tragen nach dem Deploy noch die
 * alten Komponentennamen. Im Code wird überall der neue Name verwendet.
 *
 * Livewire leitet den Namen einer Klasse aus dem ERSTEN passenden Eintrag der
 * Alias-Liste ab. Deshalb wird je Klasse zuerst der neue (kanonische) Name
 * registriert und danach der alte. So rendern neue Instanzen mit dem neuen
 * Namen (wichtig für dispatchTo), alte Namen lösen aber weiter auf.
 */
class LivewireAliasServiceProvider extends ServiceProvider
{
    /**
     * Übergang Phase 7: alte Snapshot-/Tag-Namen; nach Auslieferung entfernbar.
     *
     * @var array<string, array{0: string, 1: class-string}> alter Name => [neuer Name, Klasse]
     */
    public const ALIASES = [
        // Phase 7 – Operations-Komponenten aus dem Admin-Namespace
        'admin.operations.orders' => ['operations.orders', \App\Livewire\Operations\Orders::class],
        'admin.operations.shift-management' => ['operations.shift-management', \App\Livewire\Operations\ShiftManagement::class],
        'admin.operations.calendar' => ['operations.calendar', \App\Livewire\Operations\Calendar::class],
        'admin.operations.customers' => ['operations.customers', \App\Livewire\Operations\Customers::class],
    ];

    public function boot(): void
    {
        foreach (self::ALIASES as [$name, $class]) {
            Livewire::component($name, $class);
        }

        foreach (self::ALIASES as $oldName => [, $class]) {
            Livewire::component($oldName, $class);
        }
    }
}

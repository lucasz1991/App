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

        // Phase 7 – Personal-Komponenten in neutrale Namespaces (Namespace = Nutzungsort)
        'admin.employees' => ['employees.index', \App\Livewire\Employees\Index::class],
        'admin.employees.employee-form-modal' => ['employees.employee-form-modal', \App\Livewire\Employees\EmployeeFormModal::class],
        // Zusammengeführt (livewire-admin-30): der Mehrfachdialog ist EmployeeTeamModal mit mode=bulk.
        // Beide alten Bulk-Namen lösen übergangsweise auf die gemeinsame Klasse auf. Ihre Snapshots
        // tragen keinen Modus; die Klasse lässt sie mit 419 ablaufen (Neuladen) statt zu raten.
        'admin.employees.employee-team-bulk-modal' => ['employees.employee-team-modal', \App\Livewire\Employees\EmployeeTeamModal::class],
        'employees.employee-team-bulk-modal' => ['employees.employee-team-modal', \App\Livewire\Employees\EmployeeTeamModal::class],
        'admin.employees.employee-team-modal' => ['employees.employee-team-modal', \App\Livewire\Employees\EmployeeTeamModal::class],
        'admin.employees.employee-teams' => ['employees.employee-teams', \App\Livewire\Employees\EmployeeTeams::class],
        'admin.employees.invite-employee-modal' => ['employees.invite-employee-modal', \App\Livewire\Employees\InviteEmployeeModal::class],
        'admin.employees.team-rbac-modal' => ['employees.team-rbac-modal', \App\Livewire\Employees\TeamRbacModal::class],
        'admin.user-profile' => ['employees.profile', \App\Livewire\Employees\Profile::class],
        'admin.user-profile.employee-documents' => ['employees.profile.employee-documents', \App\Livewire\Employees\Profile\EmployeeDocuments::class],
        'admin.user-profile.outlook-preferences' => ['employees.profile.outlook-preferences', \App\Livewire\Employees\Profile\OutlookPreferences::class],
        'admin.user-profile.user-messages' => ['employees.profile.user-messages', \App\Livewire\Employees\Profile\UserMessages::class],
        'admin.user-profile.user-notes' => ['employees.profile.user-notes', \App\Livewire\Employees\Profile\UserNotes::class],
        'admin.employee-duty-activities' => ['employees.profile.employee-duty-activities', \App\Livewire\Employees\Profile\EmployeeDutyActivities::class],
        'profile.employee-location-history' => ['employees.profile.employee-location-history', \App\Livewire\Employees\Profile\EmployeeLocationHistory::class],
        'profile.employee-operations' => ['employees.profile.employee-operations', \App\Livewire\Employees\Profile\EmployeeOperations::class],
        'profile.employee-regions' => ['employees.profile.employee-regions', \App\Livewire\Employees\Profile\EmployeeRegions::class],
        'profile.employee-phone-status' => ['employees.profile.employee-phone-status', \App\Livewire\Employees\Profile\EmployeePhoneStatus::class],
        'admin.users.messages.message-form' => ['messages.message-form', \App\Livewire\Messages\MessageForm::class],
        'admin.file-manager' => ['files.file-manager', \App\Livewire\Files\FileManager::class],
        'admin.managed-documents' => ['files.managed-documents', \App\Livewire\Files\ManagedDocuments::class],

        // Phase 7 – Umbenennungen
        'operations.wagon-list-prototype' => ['operations.wagon-list', \App\Livewire\Operations\WagonList::class],
        'operations.personal-page-workspace' => ['operations.personnel-page-workspace', \App\Livewire\Operations\PersonnelPageWorkspace::class],

        // Phase 7 – Sammelkomponente nach Fachbereich geteilt (Leistungen / Zeitwirtschaft).
        // Der alte Name löst auf die Leistungen auf; Snapshots mit einem Reiter der Zeitwirtschaft
        // (Fachregeln, Monatsabschluss, Terminalzugang) laufen dort mit 419 ab (Neuladen),
        // siehe ManagesEnhancementRecords::hydrate().
        'operations.operations-enhancements' => ['operations.service-fulfilment', \App\Livewire\Operations\ServiceFulfilment::class],
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

<?php

namespace App\Support\Operations;

use App\Livewire\Operations\WorkforceAccounts;
use App\Services\Operations\WorkforceAccountService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;

/**
 * Einzige Quelle für die persönlichen Arbeitsbereiche unter /mein-arbeitstag.
 * Seite und Menü lesen dieselbe Liste, damit jeder Menüpunkt genau eine
 * Ansicht öffnet und "Mein Arbeitstag" selbst nur noch den Tag trägt.
 */
final class PersonalWorkspaceAreas
{
    /** Menügruppen in Anzeigereihenfolge; '' = direkter Link ohne Gruppe. */
    public const GROUPS = [
        'Abwesenheit & Planung' => 'calendar',
        'Nachweise & Einsätze' => 'award',
        'Meine Personalakte' => 'folder',
    ];

    /**
     * @return array<string, array{label: string, icon: string, group: string, section: string, view: string, tab: ?string}>
     */
    public static function all(): array
    {
        $personnel = OperationsNavigation::enhancementReady('personnel-enhancements');
        $operations = OperationsNavigation::enhancementReady('operations-enhancements');
        $area = static fn (string $label, string $icon, string $group, string $view, ?string $tab = null, string $section = 'Mein Arbeitsplatz'): array => compact('label', 'icon', 'group', 'section', 'view', 'tab');

        return array_filter([
            'inbox' => $area('Arbeitsliste', 'inbox', '', 'inbox'),
            'work' => $area('Mein Arbeitstag', 'clock', '', 'my-work'),

            'absences' => $area('Urlaub & Abwesenheit', 'sun', 'Abwesenheit & Planung', 'my-work', 'absences'),
            'sickness' => $personnel ? $area('Krankmeldungen', 'thermometer', 'Abwesenheit & Planung', 'personnel', 'sickness') : null,
            'planning' => WorkforcePlanningSchema::ready() ? $area('Dienstwünsche & Angebote', 'repeat', 'Abwesenheit & Planung', 'planning') : null,
            'capacity' => CustomerPortalIntakeSchema::ready() ? $area('Kapazitätsanfragen', 'check-square', 'Abwesenheit & Planung', 'capacity') : null,
            'account' => self::accountsReady() ? $area('Zeitkonto', 'pie-chart', 'Abwesenheit & Planung', 'account') : null,

            'qualifications' => $area('Qualifikationen', 'award', 'Nachweise & Einsätze', 'my-work', 'records'),
            'proofs' => $operations ? $area('Leistungsnachweise', 'file-text', 'Nachweise & Einsätze', 'operations', 'proofs') : null,
            'travel' => $operations ? $area('Reisen', 'map', 'Nachweise & Einsätze', 'operations', 'travel') : null,
            'partners' => $operations ? $area('Partneranfragen', 'share-2', 'Nachweise & Einsätze', 'operations', 'partners') : null,

            'processes' => $personnel ? $area('Aufgaben & Prozesse', 'check-circle', 'Meine Personalakte', 'personnel', 'workflows') : null,
            'documents' => $personnel ? $area('Unterlagen & Unterschriften', 'edit-3', 'Meine Personalakte', 'personnel', 'documents') : null,
            'development' => $personnel ? $area('Entwicklung', 'trending-up', 'Meine Personalakte', 'personnel', 'development') : null,
            'feedback' => $personnel ? $area('Rückmeldungen', 'message-square', 'Meine Personalakte', 'personnel', 'surveys') : null,

            'terminal' => $operations ? $area('Terminalzugang', 'key', '', 'operations', 'terminal', 'Persönlich') : null,
            'emergency' => $personnel ? $area('Notfallkontakt', 'phone', '', 'personnel', 'emergency', 'Persönlich') : null,
        ]);
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Alte Adressen (?area=personnel&tab=documents, ?tab=planning …) stammen aus
     * Lesezeichen, Benachrichtigungen und der Arbeitsliste. Sie landen weiter
     * im passenden neuen Bereich statt auf einer 404-Seite.
     */
    public static function resolve(?string $area, ?string $tab): array
    {
        $area = is_string($area) ? $area : '';
        $tab = is_string($tab) ? $tab : '';
        $areas = self::all();
        $legacy = match ($area) {
            'personnel' => ['workflows' => 'processes', 'documents' => 'documents', 'emergency' => 'emergency', 'development' => 'development', 'surveys' => 'feedback', 'sickness' => 'sickness'][$tab] ?? 'processes',
            'operations' => ['proofs' => 'proofs', 'travel' => 'travel', 'partners' => 'partners', 'terminal' => 'terminal'][$tab] ?? 'proofs',
            '', 'work' => ['absences' => 'absences', 'records' => 'qualifications', 'personnel' => 'account', 'planning' => 'planning'][$tab] ?? 'work',
            default => $area,
        };
        $key = isset($areas[$legacy]) ? $legacy : 'work';
        $workTab = $key === 'work' && in_array($tab, ['today', 'schedule', 'time'], true) ? $tab : '';

        return [$key, $workTab];
    }

    /** Menülinks im Format von ApplicationNavigation, nach Abschnitt gruppiert. */
    public static function links(bool $hasGlobalWorklist = false): array
    {
        $links = [];
        foreach (self::all() as $key => $area) {
            $title = $key === 'inbox' && $hasGlobalWorklist ? 'Meine Arbeitsliste' : $area['label'];
            $links[$area['section']][] = [
                'title' => $title, 'route' => 'operations.mine', 'icon' => $area['icon'],
                'parameters' => $key === 'work' ? [] : ['area' => $key], 'navigate' => true,
                'group' => $area['group'] !== '' ? $area['group'] : null, 'excludedSections' => [],
            ];
        }

        return $links;
    }

    private static function accountsReady(): bool
    {
        return class_exists(WorkforceAccounts::class)
            && class_exists(WorkforceAccountService::class)
            && app(WorkforceAccountService::class)->ready();
    }
}

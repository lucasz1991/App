<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Operations\OperationsAccess;

/**
 * Ein Eintrag pro anzeigbarem App-Feature. Ob ein Nutzer ein Widget sehen
 * DARF, entscheidet ausschliesslich diese Klasse (dieselben Gates/Faehig-
 * keiten wie Sidebar und Cockpit); OB es gerade eingeblendet ist und in
 * welcher Groesse, steht in dashboard_widget_placements
 * (siehe DashboardLayout::forUser). Neue Features bekommen hier einen
 * neuen Eintrag - kein Aufraeumen an anderer Stelle noetig.
 */
final class WidgetRegistry
{
    /**
     * @return array<string, array{title:string, description:string, icon:string, section:string, ability:string|\Closure|null, defaultVisible:bool, defaultSize:'sm'|'lg'}>
     */
    public static function all(): array
    {
        return [
            'my_work' => [
                'title' => 'Mein Arbeitstag', 'description' => 'Laufende Zeiterfassung und die naechsten eigenen Dienste.',
                'icon' => 'clock', 'section' => 'Mein Arbeitsplatz',
                'ability' => static fn (User $u) => OperationsAccess::ready() && OperationsAccess::isEmployee($u),
                'defaultVisible' => true, 'defaultSize' => 'lg',
            ],
            'wagon_list' => [
                'title' => 'Wagenliste', 'description' => 'Schnellzugriff auf die Wagenliste des aktuellen Auftrags.',
                'icon' => 'list', 'section' => 'Mein Arbeitsplatz',
                'ability' => static fn (User $u) => $u->isAdmin() || in_array($u->dashboardAudience(), ['employee', 'management', 'administration'], true),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'messages' => [
                'title' => 'Nachrichten', 'description' => 'Ungelesene Nachrichten und die letzten drei im Posteingang.',
                'icon' => 'message-circle', 'section' => 'Persönlich', 'ability' => null,
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'files' => [
                'title' => 'Meine Ablage', 'description' => 'Zuletzt bereitgestellte Dateien aus Firma, Team und persoenlich.',
                'icon' => 'download-cloud', 'section' => 'Persönlich', 'ability' => null,
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'my_devices' => [
                'title' => 'Meine Geräte', 'description' => 'Zustand der eigenen zugewiesenen Geraete.',
                'icon' => 'smartphone', 'section' => 'Persönlich', 'ability' => null,
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'profile_completion' => [
                'title' => 'Profil', 'description' => 'Wie vollstaendig das eigene Profil ausgefuellt ist.',
                'icon' => 'user-check', 'section' => 'Persönlich', 'ability' => null,
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_inquiries' => [
                'title' => 'Offene Anfragen', 'description' => 'Unbearbeitete Kundenanfragen ohne Auftrag oder Dublette.',
                'icon' => 'inbox', 'section' => 'Management', 'ability' => self::opsAbility('operations.inquiries.manage'),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_orders' => [
                'title' => 'Leistungen', 'description' => 'Auftraege in Bearbeitung.',
                'icon' => 'clipboard', 'section' => 'Management', 'ability' => self::opsAbility('operations.manage'),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_shift_coverage' => [
                'title' => 'Besetzung diese Woche', 'description' => 'Zugesagt gegenueber benoetigt, Tag fuer Tag.',
                'icon' => 'bar-chart-2', 'section' => 'Management', 'ability' => self::opsAbility('operations.manage'),
                'defaultVisible' => true, 'defaultSize' => 'lg',
            ],
            'operations_next_shifts' => [
                'title' => 'Nächste Dienste', 'description' => 'Die naechsten sechs Dienste aus dem Schichtplan.',
                'icon' => 'calendar', 'section' => 'Management', 'ability' => self::opsAbility('operations.manage'),
                'defaultVisible' => true, 'defaultSize' => 'lg',
            ],
            'operations_customers' => [
                'title' => 'Kundendatenbank', 'description' => 'Anzahl aktiver Kunden.',
                'icon' => 'briefcase', 'section' => 'Management', 'ability' => self::opsAbility('operations.manage'),
                'defaultVisible' => false, 'defaultSize' => 'sm',
            ],
            'operations_qualifications' => [
                'title' => 'Nachweise prüfen', 'description' => 'Eingereichte Qualifikationsnachweise, die auf Pruefung warten.',
                'icon' => 'award', 'section' => 'Management', 'ability' => self::opsAbility('operations.qualifications.manage'),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_absences' => [
                'title' => 'Abwesenheiten prüfen', 'description' => 'Antraege, die noch auf eine Entscheidung warten.',
                'icon' => 'calendar', 'section' => 'Management', 'ability' => self::opsAbility('operations.absences.review'),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_times' => [
                'title' => 'Zeiten prüfen', 'description' => 'Eingereichte Zeitmeldungen, die auf Freigabe warten.',
                'icon' => 'check-circle', 'section' => 'Management', 'ability' => self::opsAbility('operations.time.review'),
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'operations_rules' => [
                'title' => 'Regelprofil', 'description' => 'Das aktive betriebliche Pruefregelwerk.',
                'icon' => 'shield', 'section' => 'Management', 'ability' => self::opsAbility('operations.rules.manage'),
                'defaultVisible' => false, 'defaultSize' => 'sm',
            ],
            'fleet_devices' => [
                'title' => 'Geräte & Lager', 'description' => 'Zustand der gesamten Geraeteflotte.',
                'icon' => 'monitor', 'section' => 'Management', 'ability' => 'devices.view',
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'employees' => [
                'title' => 'Mitarbeiter', 'description' => 'Personal- und Kontenbestand.',
                'icon' => 'users', 'section' => 'Management', 'ability' => 'employees.view',
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'recent_activity' => [
                'title' => 'Zuletzt aktiv', 'description' => 'Wer sich zuletzt angemeldet hat.',
                'icon' => 'activity', 'section' => 'Management', 'ability' => 'employees.view',
                'defaultVisible' => false, 'defaultSize' => 'lg',
            ],
            'account_growth' => [
                'title' => 'Kontenentwicklung', 'description' => 'Gesamtbestand an Konten, letzte 14 Tage.',
                'icon' => 'trending-up', 'section' => 'Management',
                'ability' => static fn (User $u) => $u->canViewManagementDashboard(),
                'defaultVisible' => false, 'defaultSize' => 'lg',
            ],
            'mail_management' => [
                'title' => 'Mailverwaltung', 'description' => 'Zustand des Mailversands.',
                'icon' => 'send', 'section' => 'Kommunikation', 'ability' => 'manage.messages',
                'defaultVisible' => false, 'defaultSize' => 'sm',
            ],
            'calls' => [
                'title' => 'Anrufe', 'description' => 'Zuletzt gefuehrte Videoanrufe.',
                'icon' => 'phone', 'section' => 'Kommunikation', 'ability' => 'calls.join',
                'defaultVisible' => false, 'defaultSize' => 'sm',
            ],
            'support_cases' => [
                'title' => 'IT-Support', 'description' => 'Eigene Supportfaelle, mit Freigabe die des ganzen Teams.',
                'icon' => 'life-buoy', 'section' => 'Kommunikation', 'ability' => null,
                'defaultVisible' => true, 'defaultSize' => 'sm',
            ],
            'marketing' => [
                'title' => 'Marketing-Motive', 'description' => 'Motive, die auf Freigabe warten.',
                'icon' => 'image', 'section' => 'Marketing',
                'ability' => static fn (User $u) => $u->isAdmin(),
                'defaultVisible' => false, 'defaultSize' => 'sm',
            ],
            'system_status' => [
                'title' => 'Systemzustand', 'description' => 'Version, Umgebung, Datenbank, Speicher.',
                'icon' => 'server', 'section' => 'System',
                'ability' => static fn (User $u) => $u->canViewSystemDashboard(),
                'defaultVisible' => false, 'defaultSize' => 'lg',
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Jedes operations_*-Widget haengt an Tabellen/Spalten aus der
     * Operations-Migration - eine reine Rechtepruefung reicht nicht, wenn
     * die Migration auf einer Umgebung noch nicht eingespielt ist (dieselbe
     * Bedingung wie ApplicationNavigation und Cockpit).
     */
    private static function opsAbility(string $ability): \Closure
    {
        return static fn (User $u) => OperationsAccess::ready() && $u->can($ability);
    }

    /**
     * @return array<string, array{title:string, description:string, icon:string, section:string, ability:string|\Closure|null, defaultVisible:bool, defaultSize:'sm'|'lg'}>
     */
    public static function availableFor(User $user): array
    {
        return array_filter(self::all(), static fn (array $widget) => self::allowed($user, $widget['ability']));
    }

    private static function allowed(User $user, string|\Closure|null $ability): bool
    {
        if ($ability === null) {
            return true;
        }

        return $ability instanceof \Closure ? (bool) $ability($user) : $user->can($ability);
    }
}

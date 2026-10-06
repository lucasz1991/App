<?php

namespace App\Support\Operations;

use App\Models\User;
use App\Services\Operations\OperationsDutyMonitorService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\WorkforceAccountService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;

final class OperationsNavigation
{
    public static function modules(): array
    {
        return [
            'inquiries' => ['title' => 'Anfragen', 'ability' => 'operations.inquiries.manage'],
            'orders' => ['title' => 'Leistungen', 'ability' => 'operations.manage'],
            'shift-management' => ['title' => 'Schichtplan', 'ability' => 'operations.manage'],
            'calendar' => ['title' => 'Kalender', 'ability' => 'operations.manage'],
            'customers' => ['title' => 'Kunden', 'ability' => 'operations.manage'],
            'customer-portal' => ['title' => 'Kundenportal', 'ability' => 'customers.portal.manage'],
            'qualifications' => ['title' => 'Nachweise', 'ability' => 'operations.qualifications.manage'],
            'absences' => ['title' => 'Abwesenheiten', 'ability' => 'operations.absences.review'],
            'times' => ['title' => 'Zeitprüfung', 'ability' => 'operations.time.review'],
            'exports' => ['title' => 'Zeitexport', 'ability' => 'operations.time.export'],
            'rules' => ['title' => 'Regelprofil', 'ability' => 'operations.rules.manage'],
            'workforce-accounts' => ['title' => 'Arbeitsmodelle & Konten', 'ability' => 'employees.master-data.view'],
            'personnel-processes' => ['title' => 'Personalprozesse', 'ability' => 'employees.master-data.view'],
            'workforce-planning' => ['title' => 'Planungsprozesse', 'ability' => 'operations.manage'],
            'plan-variants' => ['title' => 'Planvarianten', 'ability' => 'operations.manage'],
            'planning-enhancements' => ['title' => 'Bedarf & Teamplanung', 'ability' => 'operations.manage'],
            'personnel-enhancements' => ['title' => 'Personalmanagement', 'ability' => 'employees.master-data.view'],
            'operations-enhancements' => ['title' => 'Leistung & Monatsabschluss', 'ability' => 'operations.enhancements.view'],
            'attention-center' => ['title' => 'Arbeitsliste', 'ability' => 'operations.inbox.view'],
            'duty-monitor' => ['title' => 'Leitstelle', 'ability' => 'operations.manage'],
        ];
    }

    public static function forUser(User $user): array
    {
        return array_filter(self::modules(), function ($item, $key) use ($user) {
            if (! self::enhancementReady($key)) {
                return false;
            }
            if ($key === 'workforce-accounts'
                && (! class_exists(WorkforceAccountService::class) || ! app(WorkforceAccountService::class)->ready())) {
                return false;
            }
            if ($key === 'personnel-processes'
                && (! class_exists(PersonnelProcessService::class) || ! app(PersonnelProcessService::class)->ready())) {
                return false;
            }
            if (in_array($key, ['workforce-planning', 'plan-variants'], true) && ! WorkforcePlanningSchema::ready()) {
                return false;
            }

            return $user->can($item['ability']);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public static function enhancementReady(string $module): bool
    {
        return match ($module) {
            'customer-portal' => CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready(),
            'planning-enhancements' => PlanningEnhancementSchema::ready(),
            'personnel-enhancements' => app(PersonnelEnhancementService::class)->ready(),
            'operations-enhancements' => OperationsEnhancementsSchema::ready(),
            'duty-monitor' => app(OperationsDutyMonitorService::class)->ready(),
            default => true,
        };
    }

    public static function status(string $status): string
    {
        $additional = ['prepared' => 'Vorbereitet', 'closed' => 'Abgeschlossen', 'active' => 'Aktiv', 'read' => 'Gelesen', 'overdue' => 'Überfällig', 'done' => 'Erledigt', 'escalated' => 'Eskaliert', 'needs_review' => 'Erneut prüfen', 'pending_external' => 'Unterzeichnung offen', 'result_submitted' => 'Ergebnis eingereicht', 'verified_result' => 'Ergebnis geprüft', 'covered' => 'Abgedeckt', 'uncovered' => 'Abdeckung fehlt', 'follow_up' => 'Rückfrage', 'booked' => 'Gebucht', 'reviewed' => 'Geprüft', 'requested_partner' => 'Antwort offen', 'retired' => 'Beendet'];
        if (isset($additional[$status])) {
            return $additional[$status];
        }
        if (in_array($status, ['reported', 'scheduled', 'attended'], true)) {
            return ['reported' => 'Gemeldet', 'scheduled' => 'Geplant', 'attended' => 'Teilgenommen'][$status];
        }
        if (in_array($status, ['draft', 'open', 'filled'], true)) {
            return ['draft' => 'Entwurf', 'open' => 'Offen', 'filled' => 'Besetzt'][$status];
        }

        return ['new' => 'Neu', 'verified' => 'Geprüft', 'offered' => 'Angebot', 'accepted' => 'Zugesagt', 'converted' => 'Beauftragt', 'duplicate' => 'Dublette', 'pending' => 'In Prüfung', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'revoked' => 'Widerrufen', 'withdrawn' => 'Zurückgezogen', 'running' => 'Läuft', 'paused' => 'Pause', 'completed' => 'Erfasst', 'submitted' => 'Zur Prüfung', 'returned' => 'Korrektur', 'requested' => 'Antwort offen', 'confirmed' => 'Bestätigt', 'declined' => 'Abgelehnt', 'cancelled' => 'Storniert'][$status] ?? $status;
    }

    public static function auditLabel(string $action): string
    {
        return ['inquiry.saved' => 'Bedarf gespeichert', 'inquiry.verify' => 'Bedarf bestätigt', 'inquiry.offer' => 'Angebot festgehalten', 'inquiry.accept' => 'Zusage dokumentiert', 'inquiry.convert' => 'Auftrag angelegt', 'inquiry.duplicate' => 'Dublette verknüpft'][$action] ?? 'Vorgang aktualisiert';
    }
}

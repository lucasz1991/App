<?php

namespace App\Support\Operations;

use App\Models\User;

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
            'qualifications' => ['title' => 'Nachweise', 'ability' => 'operations.qualifications.manage'],
            'absences' => ['title' => 'Abwesenheiten', 'ability' => 'operations.absences.review'],
            'times' => ['title' => 'Zeitprüfung', 'ability' => 'operations.time.review'],
            'exports' => ['title' => 'Zeitexport', 'ability' => 'operations.time.export'],
            'rules' => ['title' => 'Regelprofil', 'ability' => 'operations.rules.manage'],
        ];
    }

    public static function forUser(User $user): array
    {
        return array_filter(self::modules(), fn ($item) => $user->can($item['ability']));
    }

    public static function status(string $status): string
    {
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

<?php

namespace App\Services\Dropbox;

final class SyncLabels
{
    public const FIELDS = [
        'location' => 'Einsatzort', 'date' => 'Einsatzdatum', 'starts' => 'Beginn', 'ends' => 'Planende', 'actual_end' => 'Gemeldetes Istende (ungeprüft)',
        'employee' => 'Mitarbeiter / Dienstleister', 'train_reference' => 'Zugreferenz', 'notes' => 'Bemerkungen', 'role' => 'Einsatz als', 'customer' => 'Kunde',
        'ordered_at' => 'Bestellt am', 'cancellation' => 'Storno / tatsächliche Abfahrt', 'information' => 'Informationen', 'billing_notes' => 'Buchhaltungsanliegen', 'other' => 'Sonstiges',
        'draft' => 'Entwurf', 'cancelled' => 'Storniert', 'first_name' => 'Vorname', 'last_name' => 'Nachname', 'name' => 'Name', 'contact_email' => 'Kontakt-E-Mail',
        'phone' => 'Diensttelefon', 'mobile' => 'Privattelefon', 'city' => 'Wohnort', 'birth_date' => 'Geburtsdatum', 'birth_place' => 'Geburtsort', 'nationality' => 'Nationalität',
        'deployment_region' => 'Einsatzgebiet', 'reported_qualifications' => 'Gemeldete Qualifikationen', 'value' => 'Gemeldeter Wert', 'color' => 'Zellfarbe',
    ];

    public static function error(?string $code): string
    {
        return match ($code) {
            null, '' => 'Wartet auf Verarbeitung', 'queue_unavailable' => 'Redis nicht erreichbar; Auftrag bleibt gespeichert',
            'reconnect_required' => 'Erneut mit Dropbox verbinden', 'rate_limit' => 'Dropbox begrenzt Anfragen; erneuter Versuch folgt',
            'revision_conflict' => 'Excel wurde gleichzeitig geändert; neuer Vergleich folgt', 'cursor_reset' => 'Änderungsstand wird neu eingelesen',
            'configuration_changed' => 'Durch neue Einstellungen beendet', 'mapping_required' => 'Zuordnung oder Ausgangswerte prüfen',
            'ambiguous_row' => 'Mehrere passende Einsätze', 'field_conflict' => 'Dasselbe Feld wurde unterschiedlich geändert',
            'business_rule', 'validation_required' => 'Fachliche Prüfung erforderlich', 'row_missing' => 'Excel-Zeile fehlt; App-Daten bleiben erhalten',
            'source_missing', 'matrix_missing' => 'Quelldatei fehlt', 'destination_required', 'matrix_target_ambiguous' => 'Eindeutiges Excel-Ziel auswählen',
            'not_representable' => 'Noch nicht in Excel abbildbar', 'template_missing', 'template_changed' => 'Geprüfte Vorlage fehlt oder wurde verändert',
            'destination_needs_import' => 'Zieldatei wird zuerst abgeglichen', 'file_busy' => 'Datei wird bereits verarbeitet',
            'employee_sheet_missing' => 'Mitarbeiterblatt fehlt; automatische Anlage ist aus', 'new_rows_disabled' => 'Neue Zeilen sind ausgeschaltet',
            'invalid_date' => 'Ungültiges Datum in Excel', 'source_unavailable' => 'Quelle fehlt oder liegt außerhalb des gewählten Bereichs',
            default => 'Verarbeitung benötigt Aufmerksamkeit ('.$code.')',
        };
    }
}

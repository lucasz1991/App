<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

/** Display labels only; never confirms an insertion or changes admission. */
final class OutlookUniversalStatusLabels
{
    public const FALLBACK_LABEL = 'Prüfschritt nicht verfügbar';

    public const CHECKPOINT_LABELS = [
        'bundle.contract' => 'Outlook-Add-in und Vorlagenformat abgleichen',
        'html.parse' => 'HTML-Struktur der Vorlage',
        'scope.boundary' => 'Eigener Nachrichtenteil und Zitatgrenze',
        'markers.count' => 'Anzahl der Vorlagenkennungen',
        'markers.tag' => 'Elemente mit Vorlagenkennung',
        'markers.binding' => 'Zusammengehörigkeit der Vorlagenkennungen',
        'shell.rows' => 'Zeilenaufbau der Vorlage',
        'shell.width' => 'Gesamtbreite der Vorlage',
        'shell.align' => 'Zentrierung der Vorlage',
        'shell.hidden' => 'Sichtbarkeit der gesamten Vorlage',
        'grid.author' => 'Nachrichtentextzeile der Vorlage',
        'grid.columns' => 'Vier Signaturspalten und ihre Breiten',
        'grid.rowspan' => 'Zeilenverbindung der Dispo-Spalte',
        'grid.train' => 'Zeile mit dem Zugbild',
        'grid.legal' => 'Zeile mit den Pflichtangaben',
        'markers.position' => 'Position der Vorlagenkennungen',
        'shell.count' => 'Anzahl eigener Vorlagenblöcke',
        'material.hidden' => 'Sichtbarkeit der Signaturbestandteile',
        'material.float' => 'Anordnung der Signaturbestandteile',
        'material.content' => 'Zulässige Signaturbestandteile',
        'dispatch.count' => 'Vorhandensein des Dispo-Feldes',
        'images.src' => 'Bildquellen der Signatur',
        'images.width' => 'Bildbreiten der Signatur',
        'images.height' => 'Bildhöhen der Signatur',
        'images.count' => 'Anzahl der Signaturbilder',
        'images.order' => 'Reihenfolge der Signaturbilder',
        'images.origin' => 'Gemeinsamer Bildserver',
        'links.content' => 'Inhalt der Signaturlinks',
        'links.scheme' => 'Zulässige Linkarten',
        'links.count' => 'Anzahl der Signaturlinks',
        'borders.structure' => 'Zellenaufbau für Rahmen und Trennlinie',
        'borders.invalid' => 'Gültigkeit und Sichtbarkeit der Rahmen',
        'borders.image' => 'Unerwartetes Rahmenbild',
        'borders.cta' => 'Unerwarteter Rahmen an der Dispo-Symbolzeile',
        'line.visibility' => 'Sichtbarkeit der weißen Dispo-Trennlinie',
        'colors.line' => 'Weiße Trennlinie über den Dispo-Symbolen',
        'colors.background-image' => 'Unerwartetes Hintergrundbild',
        'colors.conflict' => 'Widersprüchliche Hintergrundfarben',
        'colors.legal' => 'Hintergrund der Pflichtangaben',
        'colors.dispatch' => 'Roter Hintergrund des Dispo-Feldes',
        'colors.corners' => 'Hintergrundfarben von Dispo-Fläche und Abschlusszeilen',
        'caps.structure' => 'Struktur der Dispo-Abschlusszeilen',
        'caps.visibility' => 'Sichtbare Höhe der Dispo-Abschlusszeilen',
        'state.present' => 'Vorhandensein der eingefügten Vorlage',
        'state.kind' => 'Art des Vorlagenblocks',
        'state.version' => 'Version der eingefügten Vorlage',
        'state.unreadable' => 'Lesbarkeit der vorhandenen Vorlage',
        'source.invalid' => 'Freigegebene Vorlagenquelle',
        'evidence.topology' => 'Tabellen- und Zellenaufbau',
        'evidence.images' => 'Bildquellen und Bildmaße',
        'evidence.links' => 'Linkziele der Signatur',
        'evidence.colors' => 'Zellhintergrundfarben',
        'evidence.borderColors' => 'Rahmenfarben der Signatur',
        'evidence.text' => 'Signatur- und Pflichttexte',
        'compose.type' => 'Nachrichtentyp',
        'quote.bytes' => 'Unveränderter zitierter Verlauf',
        'author.signature' => 'Erhalt des vorhandenen Nachrichtentexts',
        'author.combined' => 'Erhalt des Vorlagennachrichtentexts',
        'readback.unknown' => 'Prüfung nach dem Einfügen',
    ];

    public const STEP_LABELS = [
        'bundle.contract' => 'Outlook-Add-in und Vorlagenformat abgleichen',
        'binding' => 'Postfach und Absender prüfen',
        'legacy' => 'Frühere Einfügungen prüfen',
        'session.read' => 'Frühere Einfügung dieser Nachricht prüfen',
        'session.claim' => 'Einmalige Einfügung reservieren',
        'session.finalize' => 'Bestätigung der Einfügung speichern',
        'session.verify' => 'Gespeicherte Bestätigung prüfen',
        'snapshot.type' => 'Nachrichtentyp prüfen',
        'snapshot.format' => 'HTML-Format prüfen',
        'snapshot.body' => 'Aktuellen Nachrichtentext lesen',
        'snapshot.region' => 'Eigenen Nachrichtenteil und Zitatgrenze prüfen',
        'source.state' => 'Vorhandene Vorlage prüfen',
        'header.write' => 'Vorlagenkennung im Nachrichtenkopf setzen',
        'body.write' => 'Vorlage einmalig einfügen',
        'context.compare' => 'Unveränderten Bearbeitungskontext prüfen',
        'readback.material' => 'Eingefügte Vorlage prüfen',
        'readback.compose_type' => 'Nachrichtentyp nach dem Einfügen prüfen',
        'readback.quote' => 'Zitat nach dem Einfügen prüfen',
        'readback.author' => 'Nachrichtentext nach dem Einfügen prüfen',
    ];

    public static function checkpointLabel(mixed $checkpoint, mixed $step = null): string
    {
        if (is_string($checkpoint) && array_key_exists($checkpoint, self::CHECKPOINT_LABELS)) {
            return self::CHECKPOINT_LABELS[$checkpoint];
        }
        if (is_string($step) && array_key_exists($step, self::STEP_LABELS)) {
            return self::STEP_LABELS[$step];
        }

        return self::FALLBACK_LABEL;
    }
}

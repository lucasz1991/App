<?php

declare(strict_types=1);

namespace App\Support\Mail;

use JsonSerializable;

/**
 * Ergebnis einer Haertungspruefung: das bereinigte HTML und alles, was dem
 * Sanitizer dabei aufgefallen ist.
 *
 * ZWEI SCHWEREGRADE, weil nicht jede Auffaelligkeit ein Verstoss ist:
 *
 *   VIOLATION  Etwas wurde ENTFERNT — ein verbotenes Element, ein
 *              Ereignis-Attribut, eine unerlaubte CSS-Eigenschaft. Nur
 *              darauf wirft der strenge Betrieb.
 *   NOTICE     Etwas ist STEHEN GEBLIEBEN, verdient aber einen Blick, etwa
 *              eine unbekannte Klasse. Die Umbruchregeln greifen
 *              ausschliesslich ueber Klassennamen; eine unbekannte Klasse
 *              schaltet das mobile Layout ab, ohne dass in der
 *              Schreibtischvorschau etwas auffiele. Melden ist hier
 *              richtig, Entfernen waere falsch.
 *
 * Der Bericht ist unveraenderlich. Wer ihn in eine Oberflaeche reicht,
 * nimmt toArray(); wer nur wissen will, ob veroeffentlicht werden darf,
 * nimmt hasViolations().
 */
final class EmailHtmlReport implements JsonSerializable
{
    public const SEVERITY_VIOLATION = 'violation';

    public const SEVERITY_NOTICE = 'notice';

    /**
     * @param  list<array{code: string, severity: string, message: string, context: string}>  $findings
     */
    public function __construct(
        public readonly string $html,
        public readonly array $findings = [],
    ) {}

    /**
     * Baustein fuer eine einzelne Beanstandung. Bewusst ein einfaches Feld
     * und keine eigene Klasse: der Bericht wandert als JSON in die
     * Oberflaeche und in das Aktivitaetsprotokoll.
     *
     * @return array{code: string, severity: string, message: string, context: string}
     */
    public static function finding(
        string $code,
        string $message,
        string $context = '',
        string $severity = self::SEVERITY_VIOLATION,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ];
    }

    /** Nichts zu beanstanden — weder Verstoss noch Hinweis. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /** Wurde etwas entfernt? Das ist die Frage, die ueber Veroeffentlichen entscheidet. */
    public function hasViolations(): bool
    {
        return $this->violations() !== [];
    }

    /** @return list<array{code: string, severity: string, message: string, context: string}> */
    public function violations(): array
    {
        return $this->bySeverity(self::SEVERITY_VIOLATION);
    }

    /** @return list<array{code: string, severity: string, message: string, context: string}> */
    public function notices(): array
    {
        return $this->bySeverity(self::SEVERITY_NOTICE);
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /**
     * Lesbare Meldungen mit Fundstelle — genau das, was ein Administrator
     * im Editor sehen soll.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_values(array_map(
            static fn (array $finding): string => $finding['context'] === ''
                ? $finding['message']
                : $finding['message'].' ('.$finding['context'].')',
            $this->findings,
        ));
    }

    /** @return list<string> */
    public function violationMessages(): array
    {
        return (new self($this->html, $this->violations()))->messages();
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->findings, 'code')));
    }

    public function has(string $code): bool
    {
        return in_array($code, array_column($this->findings, 'code'), true);
    }

    /** Wie oft trat ein bestimmter Code auf? Nuetzlich in Pruefungen. */
    public function countOf(string $code): int
    {
        return count(array_filter(
            $this->findings,
            static fn (array $finding): bool => $finding['code'] === $code,
        ));
    }

    /**
     * @return array{html: string, clean: bool, findings: list<array{code: string, severity: string, message: string, context: string}>}
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'clean' => $this->isClean(),
            'findings' => $this->findings,
        ];
    }

    /**
     * @return array{html: string, clean: bool, findings: list<array{code: string, severity: string, message: string, context: string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<array{code: string, severity: string, message: string, context: string}>
     */
    private function bySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (array $finding): bool => $finding['severity'] === $severity,
        ));
    }
}

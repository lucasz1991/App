<?php

namespace App\Support\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use Carbon\CarbonInterface;

/**
 * Gemeinsame Aufbereitung einer Schichtkarte (Schichtplan-Liste und Kalender).
 *
 * Rechnet Besetzung, Zustand und Veröffentlichungsstand aus den bereits geladenen
 * Zuteilungen, damit x-operations.shift-card und x-operations.calendar-shift
 * dieselben Zahlen zeigen. Stellt keine Abfragen.
 */
final class ShiftCardData
{
    private function __construct(
        public readonly CarbonInterface $starts,
        public readonly CarbonInterface $ends,
        public readonly int $required,
        public readonly int $reserved,
        public readonly int $confirmed,
        public readonly int $requested,
        public readonly int $open,
        public readonly bool $closed,
        private readonly ?int $revision,
        private readonly ?int $publishedRevision,
    ) {}

    public static function from(Shift $shift, string $timezone): self
    {
        $active = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability());
        $reserved = $active->count();
        $closed = in_array($shift->status, [ShiftStatus::Completed, ShiftStatus::Cancelled], true);
        $required = (int) $shift->required_staff;

        return new self(
            starts: $shift->starts_at->copy()->setTimezone($timezone),
            ends: $shift->ends_at->copy()->setTimezone($timezone),
            required: $required,
            reserved: $reserved,
            confirmed: $active->where('status', ShiftAssignmentStatus::Confirmed)->count(),
            requested: $active->where('status', ShiftAssignmentStatus::Requested)->count(),
            open: $closed ? 0 : max(0, $required - $reserved),
            closed: $closed,
            revision: $shift->revision === null ? null : (int) $shift->revision,
            publishedRevision: $shift->published_revision === null ? null : (int) $shift->published_revision,
        );
    }

    /** Besetzungsgrad in Prozent (0–100). */
    public function coverage(): int
    {
        return min(100, (int) round($this->reserved / max(1, $this->required) * 100));
    }

    public function plannedPlacesLabel(): string
    {
        return $this->required === 1 ? '1 Platz geplant' : $this->required.' Plätze geplant';
    }

    /** Ein Zustand je Schicht: closed, open, pending oder staffed. */
    public function state(): string
    {
        return match (true) {
            $this->closed => 'closed',
            $this->open > 0 => 'open',
            $this->requested > 0 => 'pending',
            default => 'staffed',
        };
    }

    /** Ob die Schicht versioniert ist und der aktuelle Stand nicht veröffentlicht wurde. */
    public function hasUnpublishedChanges(): bool
    {
        return (bool) $this->revision && $this->publishedRevision !== $this->revision;
    }

    /** Veröffentlichungsstand als Text; null, wenn die Schicht nicht versioniert ist. */
    public function publicationLabel(): ?string
    {
        if ($this->revision === null) {
            return null;
        }
        if ($this->publishedRevision === $this->revision) {
            return 'Veröffentlicht';
        }

        return $this->publishedRevision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht';
    }
}

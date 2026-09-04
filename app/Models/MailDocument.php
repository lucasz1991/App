<?php

namespace App\Models;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Ein bearbeitbares Maildokument — Nachrichtenschale oder Signaturblock.
 *
 * Gespeichert wird Token-HTML: die {{PLATZHALTER}} bleiben stehen und werden
 * erst beim Rendern ersetzt. Dadurch traegt EIN Dokument beide Fassungen
 * (hell und dunkel entstehen aus den Palettenwerten) und dieselbe Vorlage
 * passt fuer Person, Firma und Outlook-Export.
 */
class MailDocument extends Model
{
    protected $fillable = [
        'public_id',
        'kind',
        'name',
        'status',
        'is_active',
        'builder_data',
        'html',
        'css',
        'published_html',
        'published_css',
        'published_at',
        'content_hash',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'kind' => MailDocumentKind::class,
        'status' => MailDocumentStatus::class,
        'is_active' => 'boolean',
        'builder_data' => 'array',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            if (blank($document->public_id)) {
                $document->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MailDocumentVersion::class)->orderByDesc('revision');
    }

    /**
     * Dokumente, aus denen der Mailversand tatsaechlich lesen darf.
     *
     * Der Status allein genuegt nicht: ohne Abzug in published_html gaebe es
     * nichts auszuliefern, und ein leerer Abzug wuerde eine leere Mail
     * erzeugen statt auf die Blade-Quelle zurueckzufallen.
     */
    public function scopePublished(Builder $query): Builder
    {
        $query
            ->withPublishedSnapshot()
            ->where('status', MailDocumentStatus::Published->value);

        // Bis die neue Migration auf einer Installation gelaufen ist, bleibt
        // der bisherige UNIQUE-kind-Vertrag funktionsfaehig. Danach ist
        // is_active die zusaetzliche, datenbankseitig eindeutige Freigabe.
        if (Schema::hasColumn($query->getModel()->getTable(), 'is_active')) {
            $query->where('is_active', true);
        }

        return $query;
    }

    /**
     * Dokument-Slots mit einem echten, unveraenderlichen Freigabeabzug.
     *
     * Beim Aktivieren eines anderen Slots wird der bisher aktive Slot wieder
     * zum Entwurf. Sein zuletzt veroeffentlichter Abzug bleibt jedoch erhalten
     * und darf im Outlook-Add-in weiterhin bewusst ausgewaehlt werden. Dieser
     * Scope prueft deshalb absichtlich weder status noch is_active.
     */
    public function scopeWithPublishedSnapshot(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->whereNotNull('published_html')
            ->where('published_html', '!=', '');
    }

    public function scopeActive(Builder $query): Builder
    {
        if (Schema::hasColumn($query->getModel()->getTable(), 'is_active')) {
            return $query->where('is_active', true);
        }

        return $query->where('status', MailDocumentStatus::Published->value);
    }

    public function isActive(): bool
    {
        return Schema::hasColumn($this->getTable(), 'is_active')
            ? $this->is_active === true
            : $this->status === MailDocumentStatus::Published;
    }

    public function isPublished(): bool
    {
        return $this->isActive()
            && $this->status === MailDocumentStatus::Published
            && $this->published_at !== null
            && $this->publishedHtml() !== null;
    }

    /**
     * Die veroeffentlichte Fassung oder null.
     *
     * null ist das Signal fuer den Rueckfall auf die heutige Blade-/Master-
     * Quelle — deshalb liefert die Methode bei leerem Abzug bewusst null und
     * keinen Leerstring.
     */
    public function publishedHtml(): ?string
    {
        $html = trim((string) $this->published_html);

        return $html !== '' ? $html : null;
    }

    public function publishedCss(): ?string
    {
        $css = trim((string) $this->published_css);

        return $css !== '' ? $css : null;
    }

    /**
     * Weicht der Arbeitsstand vom zuletzt veroeffentlichten Abzug ab?
     *
     * HTML und CSS bilden gemeinsam den auslieferbaren Stand. Insbesondere
     * darf eine reine CSS-Aenderung nicht als bereits veroeffentlicht gelten.
     * Ein leeres Stylesheet ist dabei ein gueltiger veroeffentlichter Wert;
     * deshalb wird published_css direkt statt ueber publishedCss() verglichen.
     */
    public function hasUnpublishedChanges(): bool
    {
        if (! $this->isPublished()) {
            return true;
        }

        return trim((string) $this->published_html) !== trim((string) $this->html)
            || trim((string) $this->published_css) !== trim((string) $this->css);
    }

    /**
     * Traegt das Dokument noch das unberuehrte Startlayout?
     *
     * Zweiteilig wie im Marketing-Studio: version 1 allein genuegt nicht (auch
     * ein frisch angelegtes Dokument hat sie), das Schema-Kennzeichen allein
     * ebenso wenig (der Inhalt kann bearbeitet worden sein).
     */
    public function isUntouchedStarter(int $schema): bool
    {
        return $this->version === 1
            && data_get($this->builder_data, 'railtime.schema') === $schema;
    }

    /** Vergleich der optimistischen Sperre — zeitkonstant und ohne Gross-/Kleinschreibung. */
    public function matchesContentHash(string $expected): bool
    {
        return hash_equals((string) $this->content_hash, strtolower(trim($expected)));
    }

    /**
     * Fingerabdruck des Inhalts.
     *
     * Die Formel ist ein Vertrag, kein Implementierungsdetail: wird an der
     * Sortierung oder an den JSON-Flags etwas geaendert, sind ALLE
     * gespeicherten content_hash-Werte ungueltig und jeder Speichervorgang
     * scheitert. Identisch zu MarketingStudioService::contentHash().
     *
     * @param  array<string, mixed>  $builderData
     */
    public static function contentHashFor(array $builderData, string $html, string $css): string
    {
        try {
            return hash('sha256', json_encode([
                'builder_data' => self::sortRecursively($builderData),
                'html' => $html,
                'css' => $css,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new RuntimeException('Die Builder-Daten des Maildokuments enthalten ungültige Zeichen.', 0, $exception);
        }
    }

    /** Assoziative Ebenen sortieren, damit die Schluesselreihenfolge den Hash nicht bewegt. */
    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = self::sortRecursively($nested);
        }

        return $value;
    }
}

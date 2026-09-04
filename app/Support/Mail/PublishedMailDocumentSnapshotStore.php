<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ein konsistenter HTML-/CSS-Abzug pro Laravel-Ausfuehrungsscope.
 *
 * Die Bindung ist absichtlich `scoped`, nicht statisch oder singleton:
 * HTTP-/Octane-Requests und Queue-Jobs erhalten jeweils einen frischen
 * Speicher. Innerhalb genau dieses Scopes teilen dagegen auch getrennte
 * MailSignature-Instanzen denselben Datenbankstand.
 */
final class PublishedMailDocumentSnapshotStore
{
    /** @var array<string, array{html: string, css: string}|null> */
    private array $snapshots = [];

    /**
     * @var list<array{
     *     id: string,
     *     key: string,
     *     name: string,
     *     label: string,
     *     active: bool,
     *     html: string,
     *     css: string
     * }>|null
     */
    private ?array $templateSnapshots = null;

    /** @return array{html: string, css: string}|null */
    public function snapshot(MailDocumentKind $kind): ?array
    {
        if (array_key_exists($kind->value, $this->snapshots)) {
            return $this->snapshots[$kind->value];
        }

        return $this->snapshots[$kind->value] = $this->read($kind);
    }

    /**
     * Liest den aktuell veroeffentlichten Stand erneut aus der Datenbank.
     *
     * Dauerhafte, personenbezogene Ableitungen verwenden diese Variante fuer
     * ihren Eingabefingerabdruck. Damit kann ein laenger laufender Job keinen
     * zuvor im Scope gelesenen Freigabestand als vermeintlich aktuell sichern.
     *
     * @return array{html: string, css: string}|null
     */
    public function freshSnapshot(MailDocumentKind $kind): ?array
    {
        if ($kind === MailDocumentKind::Template) {
            $this->templateSnapshots = null;
        }

        return $this->snapshots[$kind->value] = $this->read($kind);
    }

    /**
     * Alle jemals veroeffentlichten Vorlagen-Slots, stabil fuer die Auswahl im
     * Outlook-Add-in geordnet. Der aktive Systemstand steht immer zuerst.
     *
     * @return list<array{
     *     id: string,
     *     key: string,
     *     name: string,
     *     label: string,
     *     active: bool,
     *     html: string,
     *     css: string
     * }>
     */
    public function templateSnapshots(): array
    {
        return $this->templateSnapshots ??= $this->readTemplateSnapshots();
    }

    /**
     * @return list<array{
     *     id: string,
     *     key: string,
     *     name: string,
     *     label: string,
     *     active: bool,
     *     html: string,
     *     css: string
     * }>
     */
    public function freshTemplateSnapshots(): array
    {
        $this->templateSnapshots = $this->readTemplateSnapshots();
        $active = $this->activeTemplateSnapshot($this->templateSnapshots);
        $this->snapshots[MailDocumentKind::Template->value] = $active === null
            ? null
            : ['html' => $active['html'], 'css' => $active['css']];

        return $this->templateSnapshots;
    }

    /**
     * Eine Freigabe im selben Scope darf danach bewusst den neuen Abzug
     * lesen. Andere Requests/Jobs besitzen eine eigene Store-Instanz und
     * behalten bis zu ihrem Ende ihren bereits gelesenen konsistenten Stand.
     */
    public function forget(MailDocumentKind $kind): void
    {
        unset($this->snapshots[$kind->value]);

        if ($kind === MailDocumentKind::Template) {
            $this->templateSnapshots = null;
        }
    }

    /** Setzt fuer genau diesen synchronen Request einen geprueften Entwurf ein. */
    public function useSnapshot(MailDocumentKind $kind, string $html, string $css): void
    {
        $this->snapshots[$kind->value] = ['html' => $html, 'css' => $css];
    }

    /** @return array{html: string, css: string}|null */
    private function read(MailDocumentKind $kind): ?array
    {
        try {
            if (! Schema::hasTable('mail_documents')) {
                return null;
            }

            $document = MailDocument::query()
                ->published()
                ->where('kind', $kind->value)
                ->first(['published_html', 'published_css']);
        } catch (Throwable) {
            return null;
        }

        if (! $document instanceof MailDocument) {
            return null;
        }

        $html = trim((string) $document->published_html);

        return $html === '' ? null : [
            'html' => $html,
            'css' => trim((string) $document->published_css),
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     key: string,
     *     name: string,
     *     label: string,
     *     active: bool,
     *     html: string,
     *     css: string
     * }>
     */
    private function readTemplateSnapshots(): array
    {
        try {
            if (! Schema::hasTable('mail_documents')) {
                return [];
            }

            $hasNames = Schema::hasColumn('mail_documents', 'name');
            $hasActiveFlag = Schema::hasColumn('mail_documents', 'is_active');
            $columns = [
                'public_id',
                'status',
                'published_html',
                'published_css',
                'published_at',
            ];
            if ($hasNames) {
                $columns[] = 'name';
            }
            if ($hasActiveFlag) {
                $columns[] = 'is_active';
            }

            $documents = MailDocument::query()
                ->withPublishedSnapshot()
                ->where('kind', MailDocumentKind::Template->value)
                ->get($columns);
        } catch (Throwable) {
            return [];
        }

        $snapshots = [];
        foreach ($documents as $document) {
            $id = trim((string) $document->public_id);
            $html = trim((string) $document->published_html);
            if (! Str::isUuid($id) || $html === '') {
                continue;
            }

            $name = $hasNames
                ? preg_replace('/\s+/u', ' ', trim((string) $document->getAttribute('name')))
                : '';
            $name = is_string($name) && $name !== ''
                ? $name
                : MailDocumentKind::Template->label();

            $snapshots[] = [
                'id' => $id,
                'key' => $id,
                'name' => $name,
                'label' => $name,
                'active' => $hasActiveFlag
                    ? $document->getAttribute('is_active') === true
                        && $document->status === MailDocumentStatus::Published
                    : $document->status === MailDocumentStatus::Published,
                'html' => $html,
                'css' => trim((string) $document->published_css),
            ];
        }

        usort($snapshots, static function (array $left, array $right): int {
            if ($left['active'] !== $right['active']) {
                return $left['active'] ? -1 : 1;
            }

            $byName = strcasecmp($left['name'], $right['name']);

            return $byName !== 0 ? $byName : strcmp($left['id'], $right['id']);
        });

        return $snapshots;
    }

    /**
     * @param  list<array{id: string, key: string, name: string, label: string, active: bool, html: string, css: string}>  $snapshots
     * @return array{id: string, key: string, name: string, label: string, active: bool, html: string, css: string}|null
     */
    private function activeTemplateSnapshot(array $snapshots): ?array
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot['active']) {
                return $snapshot;
            }
        }

        return null;
    }
}

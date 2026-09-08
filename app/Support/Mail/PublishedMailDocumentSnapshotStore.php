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
    public function __construct(
        private readonly MailDocumentSignatureResolver $signatureResolver,
    ) {}

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
     *     css: string,
     *     publishedSignature: array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null
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
     *     css: string,
     *     publishedSignature: array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null
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
     *     css: string,
     *     publishedSignature: array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null
     * }>
     */
    public function freshTemplateSnapshots(): array
    {
        $this->templateSnapshots = $this->readTemplateSnapshots();
        if (MailDocumentDelivery::available()) {
            return $this->templateSnapshots;
        }
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

    /** Outlook owns its signature default independently of system mail. */
    public function outlookSignatureSnapshot(): ?array
    {
        if (! MailDocumentDelivery::available()) {
            return $this->freshSnapshot(MailDocumentKind::Signature);
        }
        $document = MailDocument::query()->withPublishedSnapshot()->where('kind', 'signature')
            ->where('outlook_default', true)->first(['published_html', 'published_css']);

        return $document ? ['html' => trim($document->published_html), 'css' => trim((string) $document->published_css)] : null;
    }

    /** @return array{html: string, css: string}|null */
    private function read(MailDocumentKind $kind): ?array
    {
        $activeSystemTemplate = null;
        try {
            if (! Schema::hasTable('mail_documents')) {
                return null;
            }

            if ($kind === MailDocumentKind::Signature && MailDocumentSignatureResolver::available()) {
                // Resolve the pairing from the one active system template,
                // never from another released library entry that merely has
                // a pairing of its own. A null pairing intentionally keeps
                // the historical global system-signature lookup below.
                $activeSystemTemplate = MailDocument::query()
                    ->published()
                    ->where('kind', MailDocumentKind::Template->value)
                    ->when(
                        Schema::hasColumn('mail_documents', 'is_outlook_template'),
                        static fn ($query) => $query->where('is_outlook_template', false),
                    )
                    ->first();
            }

            $document = MailDocument::query()
                ->published()
                ->where('kind', $kind->value)
                ->first(['published_html', 'published_css']);
        } catch (Throwable) {
            return null;
        }

        if ($activeSystemTemplate instanceof MailDocument
            && $activeSystemTemplate->published_signature_document_id !== null) {
            $paired = $this->signatureResolver->publishedSnapshot($activeSystemTemplate, 'system');

            return $paired === null ? null : [
                'html' => $paired['html'],
                'css' => $paired['css'],
            ];
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
     *     css: string,
     *     publishedSignature: array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null
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
            $hasLibrary = Schema::hasColumn('mail_documents', 'is_outlook_template');
            $hasSignaturePairing = MailDocumentSignatureResolver::available();
            $columns = [
                'public_id',
                'kind',
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
            if ($hasLibrary) {
                array_push($columns, 'is_outlook_template', 'outlook_released', 'outlook_default');
            }
            if ($hasSignaturePairing) {
                $columns[] = 'published_signature_document_id';
            }

            $documents = MailDocument::query()
                ->withPublishedSnapshot()
                ->where('kind', MailDocumentKind::Template->value)
                ->when(MailDocumentDelivery::available(), static fn ($query) => $query->where('outlook_released', true))
                ->when($hasLibrary && ! MailDocumentDelivery::available(), static fn ($query) => $query->where(static fn ($visible) => $visible
                    ->where('is_outlook_template', false)
                    ->orWhere('outlook_released', true)))
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
                'active' => ! $document->isOutlookTemplate() && ($hasActiveFlag
                    ? $document->getAttribute('is_active') === true
                        && $document->status === MailDocumentStatus::Published
                    : $document->status === MailDocumentStatus::Published),
                'isDefault' => $hasLibrary && (MailDocumentDelivery::available() || $document->isOutlookTemplate())
                    && $document->outlook_released === true && $document->outlook_default === true,
                'html' => $html,
                'css' => trim((string) $document->published_css),
                // An explicit publication binding must never silently fall
                // back to the global Outlook signature. Invalid or missing
                // partners therefore fail while the payload is generated.
                'publishedSignature' => $hasSignaturePairing
                    && $document->published_signature_document_id !== null
                        ? $this->signatureResolver->publishedSnapshot($document, 'outlook')
                        : null,
            ];
        }

        usort($snapshots, static function (array $left, array $right): int {
            if ($left['active'] !== $right['active']) {
                return $left['active'] ? -1 : 1;
            }

            $byName = strcasecmp($left['name'], $right['name']);

            return $byName !== 0 ? $byName : strcmp($left['id'], $right['id']);
        });

        if (MailDocumentDelivery::available()) {
            // active is the legacy payload selection, never system-mail scope.
            $preferred = collect($snapshots)->firstWhere('isDefault', true)['id'] ?? ($snapshots[0]['id'] ?? null);
            foreach ($snapshots as &$snapshot) {
                $snapshot['active'] = $snapshot['id'] === $preferred;
            }
            unset($snapshot);
        }

        return $snapshots;
    }

    /**
     * @param  list<array{id: string, key: string, name: string, label: string, active: bool, html: string, css: string, publishedSignature: array|null}>  $snapshots
     * @return array{id: string, key: string, name: string, label: string, active: bool, html: string, css: string, publishedSignature: array|null}|null
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

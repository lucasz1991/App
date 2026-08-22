<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use Illuminate\Support\Facades\Schema;
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

    /** @return array{html: string, css: string}|null */
    public function snapshot(MailDocumentKind $kind): ?array
    {
        if (array_key_exists($kind->value, $this->snapshots)) {
            return $this->snapshots[$kind->value];
        }

        return $this->snapshots[$kind->value] = $this->read($kind);
    }

    /**
     * Eine Freigabe im selben Scope darf danach bewusst den neuen Abzug
     * lesen. Andere Requests/Jobs besitzen eine eigene Store-Instanz und
     * behalten bis zu ihrem Ende ihren bereits gelesenen konsistenten Stand.
     */
    public function forget(MailDocumentKind $kind): void
    {
        unset($this->snapshots[$kind->value]);
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
}

<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use RuntimeException;

/** Final system output only; published channel defaults admit the existing strict asset adapter. */
final class PublishedStaticSystemDelivery
{
    public static function project(string $html): string
    {
        $store = app(PublishedMailDocumentSnapshotStore::class);
        // Preview/testmail overrides have their own exact publication guard.
        // A draft supplied in this scope must never acquire default eligibility.
        if ($store->hasExplicitSnapshot(MailDocumentKind::Template)
            || $store->hasExplicitSnapshot(MailDocumentKind::Signature)) {
            return $html;
        }
        $used = [
            'template' => $store->snapshot(MailDocumentKind::Template),
            'signature' => $store->snapshot(MailDocumentKind::Signature),
        ];
        $profile = OutlookStaticLinkedTestProfiles::signatureProfile($used['signature']);
        if ($profile === null) {
            return $html;
        }
        $before = self::fresh();
        if ($used !== $before) {
            self::changed();
        }
        $projected = OutlookStaticLinkedTestDelivery::renderedPreview($html, $profile, (string) config('app.url'));
        if ($before !== self::fresh()) {
            self::changed();
        }

        // The preview adapter intentionally retains its runtime marker. Only
        // this admitted final delivery removes the one proven BODY attribute,
        // leaving the normal MessageSending embedder inactive for these PNGs.
        $removed = 0;
        $projected = preg_replace_callback('~<body\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', static function (array $match) use (&$removed): string {
            return preg_replace('~\s+data-rt-system-mail-inline-assets\s*=\s*(?:"1"|\'1\'|1(?=\s|>))~i', '', $match[0], 1, $removed) ?? $match[0];
        }, $projected, -1, $bodies);
        if (! is_string($projected) || $bodies !== 1 || $removed !== 1) {
            throw new RuntimeException('Die statische Systemmail besitzt keinen eindeutigen kompilierten Ausgabemarker.');
        }

        return $projected;
    }

    private static function fresh(): array
    {
        // An independent read must not overwrite the compiler's scoped snapshot.
        $store = new PublishedMailDocumentSnapshotStore(app(MailDocumentSignatureResolver::class));

        return [
            'template' => $store->freshSnapshot(MailDocumentKind::Template),
            'signature' => $store->freshSnapshot(MailDocumentKind::Signature),
        ];
    }

    private static function changed(): never
    {
        throw new RuntimeException('Der veröffentlichte statische Systemmail-Stand wurde während der Ausgabe geändert.');
    }
}

<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\MailDocumentSignatureResolver;
use App\Support\Mail\OutlookOptionalTemplateWording;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Pure candidate rendering before any preference write; never inserts or sends a message. */
final class OutlookOptionalWordingBudget
{
    public function validate(User $employee, array $candidate): void
    {
        if ((! ($candidate['showGreeting'] ?? false) && ! ($candidate['showFarewell'] ?? false))
            || ! MailDocumentSignatureResolver::available()) {
            return;
        }
        try {
            // Match the current publication/profile, not a scope-cached preview or an old loaded relation.
            MailDocument::query()->orderBy('id')->lockForUpdate()->get();
            DB::table('user_profiles')->where('user_id', $employee->getKey())->lockForUpdate()->get();
            $documents = app(PublishedMailDocumentSnapshotStore::class);
            $sources = $documents->freshTemplateSnapshots();
            $eligible = [];
            foreach ($sources as $source) {
                if (OutlookOptionalTemplateWording::eligible($source['html'])) {
                    $eligible[$source['id']] = OutlookStaticLinkedTestProfiles::templateProfile($source);
                }
            }
            if ($eligible === []) {
                return;
            }
            $user = $employee->withoutRelations()->load('profile');
            $canonical = app(OutlookAddinPayloadService::class)->forUser($user, $candidate);
            $wire = OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical));
            foreach ($wire['templates'] as $entry) {
                if (! array_key_exists($entry['id'], $eligible)) {
                    continue;
                }
                $profile = $eligible[$entry['id']];
                $entry = match ($profile) {
                    OutlookLinkedAnimatedDelivery::PROFILE => OutlookLinkedAnimatedDelivery::entry($entry, $profile, (string) config('app.url')),
                    OutlookStaticLinkedTestDelivery::PROFILE => OutlookStaticLinkedTestDelivery::entry($entry, $profile, (string) config('app.url')),
                    default => $entry,
                };
                $html = $entry['mobileComposeHtml'];
                preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
                if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + 168 >= 30000
                    || array_sum(array_map('strlen', $styles[1])) >= 12288) {
                    throw new \RuntimeException('Candidate exceeds the unchanged mobile budget.');
                }
            }
        } catch (Throwable $error) {
            $budget = false;
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                $budget = $budget || preg_match('/budget|transport|30[.,]?000|12[.,]?288/i', $cause->getMessage()) === 1;
            }
            throw ValidationException::withMessages(['wording' => $budget
                ? 'Anrede und Verabschiedung sind zusammen mit deinem Namen zu lang für die mobile Signatur. Bitte die Texte kürzen oder einen Schalter ausschalten. Die gespeicherten Werte bleiben erhalten.'
                : 'Die sichere Outlook-Darstellung kann derzeit nicht geprüft werden. Bitte später erneut versuchen. Die gespeicherten Werte bleiben erhalten.']);
        }
    }
}

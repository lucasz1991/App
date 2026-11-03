<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Bounded operational evidence only. Never accepts a message, token or profile value. */
final class OutlookUniversalDiagnostics
{
    public const HOSTS = ['server', 'unknown', 'Outlook', 'OutlookWebApp', 'OutlookIOS', 'OutlookAndroid'];

    public const PHASES = ['source', 'source_contract', 'profile_values', 'source_parse', 'profile_identity', 'profile_company',
        'profile_contacts', 'profile_website', 'dispatch', 'legal', 'author', 'pilot', 'desktop', 'template', 'render', 'budget',
        'preflight', 'claim', 'header', 'write', 'readback', 'complete'];

    public const READBACK_CODES = ['SIGNATURE_LAYOUT_UNCONFIRMED', 'U2E_INSERT_UNCERTAIN', 'U2E_NEW_DRAFT_REQUIRED', 'U2E_SOURCE_INVALID',
        'U2E_CONTEXT_UNREADABLE', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE', 'U2E_DOCUMENT_INVALID', 'U2E_INSERT_IN_PROGRESS',
        'U2E_SESSION_UNAVAILABLE', 'U2E_HTML_REQUIRED', 'U2E_CONTEXT_CHANGED', 'COMPOSE_ITEM_CHANGED', 'U2E_OPERATION_FAILED'];

    public const CODES = ['SIGNATURE_LAYOUT_UNCONFIRMED', 'U2E_INSERT_UNCERTAIN', 'U2E_NEW_DRAFT_REQUIRED', 'U2E_SOURCE_INVALID',
        'U2E_CONTEXT_UNREADABLE', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE', 'U2E_DOCUMENT_INVALID', 'U2E_INSERT_IN_PROGRESS',
        'U2E_SOURCE_REJECTED', 'U2E_ADMITTED', 'U2E_OUTSIDE_PILOT', 'U2E_DESKTOP_UNAVAILABLE', 'U2E_TEMPLATE_UNAVAILABLE',
        'U2E_RENDER_UNAVAILABLE', 'U2E_BUDGET_EXCEEDED', 'U2E_SESSION_UNAVAILABLE', 'U2E_HTML_REQUIRED',
        'U2E_CONTEXT_CHANGED', 'COMPOSE_ITEM_CHANGED', 'U2E_OPERATION_FAILED'];

    private const REASONS = ['admitted', 'outside_pilot', 'source_invalid', 'source_family_unsupported', 'source_contract_invalid',
        'profile_value_invalid', 'source_parse_failed', 'source_structure_invalid', 'profile_identity_mismatch',
        'profile_company_mismatch', 'profile_contact_mismatch', 'profile_contact_links_mismatch', 'profile_website_mismatch',
        'dispatch_links_invalid', 'profile_dispatch_mismatch', 'legal_structure_invalid', 'legal_text_missing',
        'author_structure_invalid', 'desktop_unavailable', 'template_unavailable', 'render_unavailable', 'budget_exceeded'];

    public function recordAdmission(User $user, array $event, ?string $mailbox = null): void
    {
        if (array_diff(array_keys($event), ['status', 'reason', 'phase', 'code', 'host', 'sourceLength', 'outputLength']) !== []
            || ! in_array($event['status'] ?? null, ['admitted', 'fallback'], true)
            || ! in_array($event['reason'] ?? null, self::REASONS, true)
            || ! $this->valid($event, 'outputLength')) {
            return;
        }
        $this->store($user, $event + ['host' => 'server'], 'admission', $mailbox);
    }

    public function recordReadback(User $user, array $event, ?string $mailbox = null): void
    {
        if (array_diff(array_keys($event), ['host', 'code', 'phase', 'sourceLength', 'bodyLength']) !== []
            || ! $this->valid($event, 'bodyLength') || ! in_array($event['code'] ?? null, self::READBACK_CODES, true)
            || ! in_array($event['phase'] ?? null, ['preflight', 'claim', 'header', 'write', 'readback', 'complete'], true)
            || ! in_array($event['host'] ?? null, self::HOSTS, true)) {
            return;
        }
        $this->store($user, $event, 'readback', $mailbox);
    }

    public function statusFor(User $user): array
    {
        try {
            $value = Cache::get($this->key($user));
            if (is_array($value)) {
                $recent = static fn (mixed $event): bool => is_array($event) && is_string($event['at'] ?? null)
                    && strtotime($event['at']) >= now()->subDays(7)->getTimestamp();

                return ['admission' => $recent($value['admission'] ?? null) ? $value['admission'] : null,
                    'lastAdmissionFailure' => $recent($value['lastAdmissionFailure'] ?? null) ? $value['lastAdmissionFailure'] : null,
                    'readbackErrors' => array_values(array_filter($value['readbackErrors'] ?? [], $recent))];
            }
        } catch (Throwable) {
            // Diagnosis must not grant admission or affect composing.
        }

        return ['admission' => null, 'lastAdmissionFailure' => null, 'readbackErrors' => []];
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'admitted' => 'Quelle und aktuelles Profil zugelassen',
            'outside_pilot' => 'außerhalb Pilot',
            'profile_identity_mismatch', 'profile_value_invalid' => 'Profilname oder Position stimmt nicht mit der veröffentlichten Quelle überein',
            'profile_company_mismatch', 'profile_website_mismatch' => 'Firmendaten stimmen nicht mit der veröffentlichten Quelle überein',
            'profile_contact_mismatch', 'profile_contact_links_mismatch' => 'Kontaktdaten stimmen nicht mit der veröffentlichten Quelle überein',
            'profile_dispatch_mismatch', 'dispatch_links_invalid' => 'Disposition ist nicht eindeutig zugelassen',
            'legal_structure_invalid', 'legal_text_missing' => 'Pflichttexte sind nicht eindeutig zugelassen',
            'author_structure_invalid' => 'Vorlageninhalt ist nicht eindeutig zugelassen',
            'budget_exceeded' => 'Editorfassung überschreitet das Zeichenbudget',
            'render_unavailable' => 'U2e-Ausgabe konnte nicht sicher erstellt werden',
            'template_unavailable' => 'ausgewählte veröffentlichte Vorlage fehlt',
            'desktop_unavailable' => 'veröffentlichte Desktopfassung ist nicht zugelassen',
            default => 'veröffentlichte Quelle entspricht nicht dem zugelassenen Format',
        };
    }

    private function valid(array $event, string $length): bool
    {
        return in_array($event['phase'] ?? null, self::PHASES, true)
            && in_array($event['code'] ?? null, self::CODES, true)
            && in_array($event['host'] ?? 'server', self::HOSTS, true)
            && $this->length($event['sourceLength'] ?? null) && $this->length($event[$length] ?? null);
    }

    private function length(mixed $value): bool
    {
        return $value === null || is_int($value) && $value >= 0 && $value <= 1000000;
    }

    private function store(User $user, array $event, string $kind, ?string $mailbox): void
    {
        // The caller supplies only a server-resolved binding or persisted user.
        $address = strtolower(trim($mailbox ?? (string) $user->email));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $event['at'] = now()->toIso8601String();
        try {
            $level = $kind === 'readback' || ($event['status'] ?? null) === 'fallback' ? 'warning' : 'notice';
            Log::log($level, 'RailTime Outlook U2e diagnostic.', ['mailbox' => $address, 'kind' => $kind] + $event);
        } catch (Throwable) {
            // Preserve the original insertion/admission outcome.
        }
        try {
            $status = $this->statusFor($user);
            if ($kind === 'admission') {
                $status['admission'] = $event;
                if ($event['status'] === 'fallback' && $event['reason'] !== 'outside_pilot') {
                    $status['lastAdmissionFailure'] = $event;
                }
            } else {
                $status['readbackErrors'] = array_slice([$event, ...($status['readbackErrors'] ?? [])], 0, 10);
            }
            Cache::put($this->key($user), $status, now()->addDays(7));
        } catch (Throwable) {
            // A cache outage must not affect the Office operation.
        }
    }

    private function key(User $user): string
    {
        return 'outlook:u2e:diagnostics:v1:'.hash('sha256', (string) $user->getKey()."\0".strtolower((string) $user->email));
    }
}

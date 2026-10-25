<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OutlookAddinBootstrapController extends Controller
{
    public function __invoke(
        Request $request,
        EntraAccessTokenValidator $validator,
        OutlookAddinIdentityResolver $identityResolver,
        OutlookAddinUserSnapshotStore $snapshots,
    ): JsonResponse|Response {
        try {
            $token = $request->bearerToken();
            if (! is_string($token) || trim($token) === '') {
                throw new OutlookAddinException(
                    'Die Microsoft-Anmeldung fehlt.',
                    401,
                    'outlook_addin_unauthorized',
                );
            }

            $identity = $validator->validate($token);
            $mailboxAddress = trim((string) $request->header('X-RailTime-Outlook-Mailbox'));
            $senderAddress = trim((string) $request->header('X-RailTime-Outlook-Sender'));
            $resolved = $identityResolver->resolve($identity, $mailboxAddress, $senderAddress);
            // Ein alter gecachter Client wuerde die Signatur noch aus dem
            // Legacy-Volltemplate einfuegen. Erst nach Postfachpruefung und
            // bestaetigtem Compose-Vertrag personenbezogene Bytes ausliefern.
            if ($request->header('X-RailTime-Compose-Contract') !== 'native-signature-v1') {
                throw new OutlookAddinException(
                    'Die Outlook-App ist veraltet. Bitte Outlook und die RailTime-App neu laden.',
                    409,
                    'outlook_addin_client_outdated',
                );
            }
            $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
            $preferences = $policy->preferencesForUser($resolved['user']);
            $event = $request->header('X-RailTime-Outlook-Context') === 'event';
            $v2 = $event && $request->header('X-RailTime-Automatic-Policy') === '2';
            $selection = $v2 ? $policy->automaticSelection($preferences, (string) $request->header('X-RailTime-Compose-Type')) : null;
            $automaticInsertionEnabled = $v2 ? ($selection['signature'] || $selection['template']) : $policy->allEnabled($preferences);
            $metadata = [
                'schema' => 1,
                'marker' => (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
                'automaticInsertionEnabled' => $automaticInsertionEnabled,
                'automaticInsertionPreferences' => $preferences,
                'binding' => $resolved['binding'],
            ];
            if ($v2) {
                $metadata['automaticInsertion'] = $selection;
            }
            if (! $automaticInsertionEnabled && $event) {
                // Cached runtimes may not understand the preference flag. Do
                // not give an automatic event any insertable artifacts then.
                return $this->payloadResponse($request, $metadata);
            }
            app(MicrosoftDeviceSyncScheduler::class)->afterMicrosoftSignIn($identity, $resolved['user']);
            $payload = $snapshots->currentForUser($resolved['user']);
            $signatureOnly = $v2 && $selection['signature'] && ! $selection['template'];
            $templateOnly = $v2 && ! $selection['signature'] && $selection['template'];
            if ($signatureOnly) {
                $payload = [
                    'schema' => $payload['schema'], 'marker' => $payload['marker'],
                    'signature' => $payload['signature'], 'templates' => [], 'automaticTemplateId' => null,
                    'version' => array_intersect_key($payload['version'], ['signature' => true, 'personal' => true]),
                ];
            }
            // Presentation hint only, after all identity checks and after the
            // desktop snapshot cache. Never changes publication or eligibility.
            if (! $templateOnly && $request->header('X-RailTime-Outlook-Profile') === OutlookMobileSignature::PROFILE) {
                $payload = OutlookMobileSignature::payload($payload);
            }
            $payload = OutlookClientPresentation::payload($payload);
            if ($templateOnly) {
                $payload = $this->templateOnlyPayload($payload, $request->header('X-RailTime-Outlook-Profile') === OutlookMobileSignature::PROFILE);
            }

            // Request-fresh preference, outside reusable canonical snapshots.
            // Manual signature/template data remains available in this response.
            // Der persoenliche Snapshot ist wiederverwendbar, die Freigabe
            // fuer den aktuellen Absender dagegen strikt requestgebunden.
            return $this->payloadResponse($request, array_replace($payload, $metadata));
        } catch (OutlookAddinException $exception) {
            $this->recordDeniedBootstrap($exception);

            return response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus, $this->headers());
        } catch (Throwable) {
            return response()->json([
                'error' => 'outlook_addin_error',
                'message' => 'Die Outlook-Daten konnten vorübergehend nicht geladen werden.',
            ], 500, $this->headers());
        }
    }

    /** Emit no signature-bearing legacy or combined fallback for template-only events. */
    private function templateOnlyPayload(array $payload, bool $mobile): array
    {
        $templates = [];
        foreach ($payload['templates'] as $template) {
            if (($template['id'] ?? null) !== ($payload['automaticTemplateId'] ?? null) || ($template['isDefault'] ?? false) !== true) {
                continue;
            }
            $html = $template['composeHtml'];
            preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
            if (($template['signatureMode'] ?? null) !== 'native' || ! str_contains($html, 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE')
                || str_contains($html, 'RT-SIGNATURE-') || str_contains($html, 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT')
                || ($mobile && (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + strlen('<div data-rt-compose-document="template-native-v1"></div>') > 30000 || array_sum(array_map('strlen', $styles[1])) >= 12288))) {
                throw new OutlookAddinException('Die signaturfreie Outlook-Vorlage ist nicht einsatzbereit.', 409, 'outlook_addin_publication_invalid');
            }
            $templates[] = array_intersect_key($template, array_fill_keys([
                'id', 'key', 'name', 'label', 'active', 'isDefault', 'version', 'hash', 'signatureMode', 'composeHtml', 'composeMedia',
            ], true));
        }

        return ['automaticTemplateId' => $payload['automaticTemplateId'], 'templates' => $templates];
    }

    private function payloadResponse(Request $request, array $payload): JsonResponse|Response
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $etag = '"'.hash('sha256', $encoded).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $this->headers($etag));
        }

        return response()->json($payload, 200, $this->headers($etag));
    }

    private function recordDeniedBootstrap(OutlookAddinException $exception): void
    {
        if ($exception->httpStatus !== 403 || ! in_array($exception->errorCode, [
            'outlook_addin_mailbox_unavailable',
            'outlook_addin_sender_unavailable',
            'outlook_addin_mailbox_mismatch',
            'outlook_addin_sender_mismatch',
            'outlook_addin_identity_not_linked',
            'outlook_addin_user_inactive',
        ], true)) {
            return;
        }

        // Fixed categories only: never log the request, identity, token,
        // mailbox, sender, exception message or signature/template content.
        // Diagnostic failure must not change the original denial response.
        try {
            Log::notice('RailTime Outlook bootstrap denied.', [
                'error_code' => $exception->errorCode,
                'http_status' => 403,
            ]);
        } catch (Throwable) {
            // Keep the existing authorization decision and HTTP response.
        }
    }

    /** @return array<string, string> */
    private function headers(?string $etag = null): array
    {
        return array_filter([
            'Cache-Control' => 'private, no-store, max-age=0',
            'ETag' => $etag,
            'Referrer-Policy' => 'no-referrer',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Outlook-Sender, X-RailTime-Compose-Contract, X-RailTime-Outlook-Profile, X-RailTime-Outlook-Context, X-RailTime-Automatic-Policy, X-RailTime-Compose-Type',
            'X-Content-Type-Options' => 'nosniff',
        ], static fn (?string $value): bool => $value !== null);
    }
}

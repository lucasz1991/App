<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticDetails;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureValidator;
use App\Support\OutlookAddin\OutlookUniversalDiagnostics;
use App\Support\OutlookAddin\OutlookUniversalOperationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Authenticated, read-only metadata. No provisioning, mail access or profile writes. */
final class OutlookAddinDiagnosticsController extends Controller
{
    public function __invoke(Request $request, EntraAccessTokenValidator $validator, OutlookAddinIdentityResolver $resolver, OutlookUniversalDiagnostics $diagnostics, OutlookUniversalDiagnosticFixtureStore $fixtures): JsonResponse
    {
        try {
            $token = $request->bearerToken();
            if (! is_string($token) || trim($token) === '') {
                throw new OutlookAddinException('Die Microsoft-Anmeldung fehlt.', 401, 'outlook_addin_unauthorized');
            }
            $identity = $validator->validate($token);
            if ($request->header('X-RailTime-Compose-Contract') !== 'native-signature-v1') {
                throw new OutlookAddinException('Bitte die Outlook-App neu laden.', 409, 'outlook_addin_client_outdated');
            }
            if (! in_array($request->header('X-RailTime-Outlook-Context'), ['taskpane', 'event'], true)) {
                throw new OutlookAddinException('Der Outlook-Kontext fehlt.', 403, 'outlook_addin_diagnostics_context_required');
            }
            // Telemetry, like compose bootstrap, never activates a RailTime account.
            $resolved = $resolver->resolveOwnAccount($identity, (string) $request->header('X-RailTime-Outlook-Mailbox'));
            $sender = strtolower(trim((string) $request->header('X-RailTime-Outlook-Sender')));
            if (! in_array($sender, $resolved['binding']['allowedSenderAddresses'], true)) {
                throw new OutlookAddinException('Der Outlook-Absender stimmt nicht überein.', 403, 'outlook_addin_sender_mismatch');
            }
            if (! $request->isJson() || strlen($request->getContent()) > OutlookUniversalDiagnosticFixtureStore::MAX_REQUEST_BYTES
                || array_diff(array_keys($request->all()), ['schema', 'host', 'code', 'phase', 'sourceLength', 'bodyLength', 'checkpoint', 'difference', 'fixture',
                    'originalCode', 'step', 'bodyLengthOrigin', 'composeType', 'automaticReason', 'diagnosticId']) !== []) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültige Diagnosefelder.']);
            }
            $data = $request->validate([
                'schema' => ['required', 'integer', 'in:1'],
                'host' => ['required', 'string', Rule::in(OutlookUniversalDiagnostics::HOSTS)],
                'code' => ['required', 'string', Rule::in(OutlookUniversalDiagnostics::READBACK_CODES)],
                'phase' => ['required', 'string', Rule::in(['preflight', 'claim', 'header', 'write', 'readback', 'complete'])],
                'sourceLength' => ['present', 'nullable', 'integer', 'between:0,1000000'],
                'bodyLength' => ['present', 'nullable', 'integer', 'between:0,1000000'],
                'diagnosticId' => ['sometimes', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            ]);
            if ($data['schema'] !== 1) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültige Diagnoseversion.']);
            }
            $diagnosticId = $data['diagnosticId'] ?? null;
            // The opaque receipt identifier never becomes log/admin/fixture metadata.
            unset($data['schema'], $data['diagnosticId']);
            // Laravel integer validation accepts numeric strings; wire metadata is typed.
            foreach (['sourceLength', 'bodyLength'] as $key) {
                if ($data[$key] !== null && ! is_int($data[$key])) {
                    throw ValidationException::withMessages(['diagnostic' => 'Ungültige Längenangabe.']);
                }
            }
            $details = array_intersect_key($request->all(), array_flip(['checkpoint', 'difference']));
            if (! OutlookUniversalDiagnosticDetails::valid($details)) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültiger Prüfpunkt.']);
            }
            $data = array_replace($data, $details);
            $operation = array_intersect_key($request->all(), array_flip(OutlookUniversalOperationDetails::KEYS));
            // Context is taken only from the already validated authentication header.
            $operation['context'] = $request->header('X-RailTime-Outlook-Context');
            if (! OutlookUniversalOperationDetails::valid($operation, $data['code'])) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültige Ablaufdiagnose.']);
            }
            if ($data['code'] === 'U2E_AUTOMATIC_SKIPPED'
                && ! OutlookUniversalDiagnosticFixtureStore::allowed($resolved['user'], $resolved['binding']['mailboxAddress'])) {
                throw new OutlookAddinException('Die Automatikdiagnose ist auf das Pilotpostfach begrenzt.', 403, 'outlook_addin_diagnostics_pilot_required');
            }
            $data = array_replace($data, $operation);
            $capture = null;
            $fixture = null;
            $hasFixture = array_key_exists('fixture', $request->all());
            if ($hasFixture) {
                if (! OutlookUniversalDiagnosticFixtureStore::allowed($resolved['user'], $resolved['binding']['mailboxAddress'])) {
                    throw new OutlookAddinException('Die Fixture-Erfassung ist auf das Pilotpostfach begrenzt.', 403, 'outlook_addin_diagnostics_pilot_required');
                }
                $fixture = $request->input('fixture');
                $boundary = is_array($fixture) && ($fixture['format'] ?? null) === OutlookUniversalDiagnosticFixtureValidator::BOUNDARY_FORMAT;
                $allowedFixture = $boundary
                    ? $data['code'] === 'U2E_NEW_DRAFT_REQUIRED' && $data['phase'] === 'preflight'
                        && ($data['step'] ?? null) === 'snapshot.region'
                        && in_array($data['composeType'] ?? null, ['reply', 'forward'], true)
                        && in_array($data['host'], ['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp'], true)
                    : $data['code'] === 'SIGNATURE_LAYOUT_UNCONFIRMED';
                if (! $allowedFixture || ! is_array($fixture)
                    || ! OutlookUniversalDiagnosticFixtureValidator::valid($fixture)) {
                    // Keep the independently validated checkpoint even if a host
                    // serialization cannot pass the stricter fixture boundary.
                    $diagnostics->recordReadback($resolved['user'], $data, $resolved['binding']['mailboxAddress']);
                    throw ValidationException::withMessages(['diagnostic' => 'Ungültige redigierte Compose-Fixture.']);
                }
            }
            if ($diagnosticId !== null) {
                // An atomic cache receipt closes the race between independent
                // Office runtimes, after all authentication and payload checks.
                $key = 'outlook:u2e:diagnostic-receipts:v1:'.hash('sha256', (string) $resolved['user']->getKey()."\0".$diagnosticId);
                try {
                    if (! Cache::add($key, true, now()->addDays(7))) {
                        if (Cache::get($key) !== true) {
                            throw new \RuntimeException('Diagnostic receipt unavailable.');
                        }

                        return $this->respond(['accepted' => true, 'duplicate' => true], 202);
                    }
                } catch (Throwable) {
                    throw new OutlookAddinException('Die Diagnose konnte nicht reserviert werden.', 503, 'outlook_addin_diagnostics_storage_unavailable');
                }
            }
            if ($hasFixture) {
                $capture = $fixtures->put($resolved['user'], $fixture, $data, $resolved['binding']['mailboxAddress']);
                if ($capture !== null) {
                    $data['fixtureId'] = $capture['id'];
                }
            }
            $diagnostics->recordReadback($resolved['user'], $data, $resolved['binding']['mailboxAddress']);
            if ($request->has('fixture') && $capture === null) {
                throw new OutlookAddinException('Die redigierte Fixture konnte nicht gespeichert werden.', 503, 'outlook_addin_diagnostics_storage_unavailable');
            }

            return $this->respond(['accepted' => true] + ($capture === null ? [] : ['fixture' => $capture]), 202);
        } catch (OutlookAddinException $error) {
            return $this->respond(['error' => $error->errorCode, 'message' => $error->getMessage()], $error->httpStatus);
        } catch (ValidationException) {
            return $this->respond(['error' => 'outlook_addin_diagnostics_invalid', 'message' => 'Die Diagnose enthält ungültige Felder.'], 422);
        } catch (Throwable) {
            return $this->respond(['error' => 'outlook_addin_error', 'message' => 'Die Diagnose konnte nicht gespeichert werden.'], 500);
        }
    }

    private function respond(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Outlook-Sender, X-RailTime-Compose-Contract, X-RailTime-Outlook-Context',
        ]);
    }
}

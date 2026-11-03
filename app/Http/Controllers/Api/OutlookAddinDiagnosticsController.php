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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            // Unlike compose bootstrap, telemetry must never activate an account.
            $resolved = $resolver->resolveOwnAccount($identity, (string) $request->header('X-RailTime-Outlook-Mailbox'));
            $sender = strtolower(trim((string) $request->header('X-RailTime-Outlook-Sender')));
            if (! in_array($sender, $resolved['binding']['allowedSenderAddresses'], true)) {
                throw new OutlookAddinException('Der Outlook-Absender stimmt nicht überein.', 403, 'outlook_addin_sender_mismatch');
            }
            if (! $request->isJson() || strlen($request->getContent()) > OutlookUniversalDiagnosticFixtureStore::MAX_REQUEST_BYTES
                || array_diff(array_keys($request->all()), ['schema', 'host', 'code', 'phase', 'sourceLength', 'bodyLength', 'checkpoint', 'difference', 'fixture']) !== []) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültige Diagnosefelder.']);
            }
            $data = $request->validate([
                'schema' => ['required', 'integer', 'in:1'],
                'host' => ['required', 'string', Rule::in(OutlookUniversalDiagnostics::HOSTS)],
                'code' => ['required', 'string', Rule::in(OutlookUniversalDiagnostics::READBACK_CODES)],
                'phase' => ['required', 'string', Rule::in(['preflight', 'claim', 'header', 'write', 'readback', 'complete'])],
                'sourceLength' => ['present', 'nullable', 'integer', 'between:0,1000000'],
                'bodyLength' => ['present', 'nullable', 'integer', 'between:0,1000000'],
            ]);
            if ($data['schema'] !== 1) {
                throw ValidationException::withMessages(['diagnostic' => 'Ungültige Diagnoseversion.']);
            }
            unset($data['schema']);
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
            $capture = null;
            if (array_key_exists('fixture', $request->all())) {
                if (! OutlookUniversalDiagnosticFixtureStore::allowed($resolved['user'], $resolved['binding']['mailboxAddress'])) {
                    throw new OutlookAddinException('Die Fixture-Erfassung ist auf das Pilotpostfach begrenzt.', 403, 'outlook_addin_diagnostics_pilot_required');
                }
                $fixture = $request->input('fixture');
                if ($data['code'] !== 'SIGNATURE_LAYOUT_UNCONFIRMED' || ! is_array($fixture)
                    || ! OutlookUniversalDiagnosticFixtureValidator::valid($fixture)) {
                    // Keep the independently validated checkpoint even if a host
                    // serialization cannot pass the stricter fixture boundary.
                    $diagnostics->recordReadback($resolved['user'], $data, $resolved['binding']['mailboxAddress']);
                    throw ValidationException::withMessages(['diagnostic' => 'Ungültige redigierte Signatur-Fixture.']);
                }
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

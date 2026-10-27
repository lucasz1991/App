<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Own-account controls only. No message, snapshot, attachment or compose API. */
final class OutlookAddinAccountPreferencesController extends Controller
{
    public function __invoke(Request $request, EntraAccessTokenValidator $validator, OutlookAddinIdentityResolver $resolver, OutlookAddinAutomaticInsertionPolicy $policy): JsonResponse
    {
        $resolved = null;
        try {
            $token = $request->bearerToken();
            if (! is_string($token) || trim($token) === '') {
                throw new OutlookAddinException('Die Microsoft-Anmeldung fehlt.', 401, 'outlook_addin_unauthorized');
            }
            $identity = $validator->validate($token);
            if ($request->header('X-RailTime-Compose-Contract') !== 'native-signature-v1') {
                throw new OutlookAddinException('Bitte die Outlook-App neu laden.', 409, 'outlook_addin_client_outdated');
            }
            if ($request->header('X-RailTime-Outlook-Context') !== 'account-settings') {
                throw new OutlookAddinException('Bitte die RailTime-Einstellungen öffnen.', 403, 'outlook_addin_preferences_context_required');
            }
            $resolved = $resolver->resolveOwnAccount($identity, (string) $request->header('X-RailTime-Outlook-Mailbox'));
            if ($request->isMethod('get')) {
                if ($request->all() !== []) {
                    throw ValidationException::withMessages(['preferences' => 'Die Kontoeinstellungen benötigen keine Nachrichtendaten.']);
                }
                $preferences = $policy->preferencesForUser($resolved['user']);
            } else {
                if (! $request->isJson() || array_diff(array_keys($request->all()), ['group', 'field', 'enabled', 'revision']) !== [] || ! is_bool($request->input('enabled'))) {
                    throw ValidationException::withMessages(['preferences' => 'Bitte genau eine gültige Outlook-Einstellung als JSON senden.']);
                }
                $data = $request->validate([
                    'group' => ['required', 'string', 'in:newMessages,replies'],
                    'field' => ['required', 'string', 'in:signature,template'],
                    'enabled' => ['required', 'boolean'],
                    'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
                ]);
                $preferences = $policy->updateForIdentity($resolved['user'], $identity, $resolved['binding'], $data['group'], $data['field'], $data['enabled'], $data['revision']);
            }

            return $this->respond(['automaticInsertionPreferences' => $preferences, 'binding' => $resolved['binding']]);
        } catch (OutlookAddinException $error) {
            $body = ['error' => $error->errorCode, 'message' => $error->getMessage()];
            if ($error->errorCode === 'outlook_addin_preferences_conflict' && $resolved !== null) {
                $body['automaticInsertionPreferences'] = $policy->preferencesForUser($resolved['user']);
                $body['binding'] = $resolved['binding'];
            }

            return $this->respond($body, $error->httpStatus);
        } catch (ValidationException) {
            return $this->respond(['error' => 'outlook_addin_preferences_invalid', 'message' => 'Die Outlook-Einstellung ist ungültig.'], 422);
        } catch (Throwable) {
            return $this->respond(['error' => 'outlook_addin_error', 'message' => 'Die Outlook-Einstellung konnte vorübergehend nicht geladen werden.'], 500);
        }
    }

    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Compose-Contract, X-RailTime-Outlook-Context',
        ]);
    }
}

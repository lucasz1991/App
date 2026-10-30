<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Account text preferences only: no selected message, sender, payload or activation. */
final class OutlookAddinAccountWordingController extends Controller
{
    public function __invoke(Request $request, EntraAccessTokenValidator $validator, OutlookAddinIdentityResolver $resolver, OutlookAddinWordingPolicy $policy): JsonResponse
    {
        $resolved = null;
        $identity = null;
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
                    throw ValidationException::withMessages(['wording' => 'Die Kontoeinstellungen benötigen keine Nachrichtendaten.']);
                }
                $preferences = $policy->preferencesForIdentity($resolved['user'], $identity, $resolved['binding']);
            } else {
                if (! $request->isJson() || array_diff(array_keys($request->all()), ['greeting', 'farewell', 'revision', 'showGreeting', 'showFarewell']) !== []) {
                    throw ValidationException::withMessages(['wording' => 'Bitte die Vorlagentexte als JSON senden.']);
                }
                $data = $request->validate([
                    'greeting' => ['required', 'string', 'max:'.OutlookAddinWordingPolicy::MAX_LENGTH],
                    'farewell' => ['required', 'string', 'max:'.OutlookAddinWordingPolicy::MAX_LENGTH],
                    'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
                    'showGreeting' => ['sometimes', 'required', 'boolean'],
                    'showFarewell' => ['sometimes', 'required', 'boolean'],
                ]);
                $preferences = $policy->updateForIdentity($resolved['user'], $identity, $resolved['binding'], $data['greeting'], $data['farewell'], $data['revision'], $data['showGreeting'] ?? null, $data['showFarewell'] ?? null);
            }

            return $this->respond(['wordingPreferences' => $preferences, 'binding' => $resolved['binding']]);
        } catch (OutlookAddinException $error) {
            $body = ['error' => $error->errorCode, 'message' => $error->getMessage()];
            if ($error->errorCode === 'outlook_addin_wording_conflict' && $resolved !== null && $identity !== null) {
                try {
                    $fresh = $resolver->resolveOwnAccount($identity, (string) $request->header('X-RailTime-Outlook-Mailbox'));
                    $body['wordingPreferences'] = $policy->preferencesForIdentity($fresh['user'], $identity, $fresh['binding']);
                    $body['binding'] = $fresh['binding'];
                } catch (OutlookAddinException $current) {
                    return $this->respond(['error' => $current->errorCode, 'message' => $current->getMessage()], $current->httpStatus);
                } catch (Throwable) {
                    return $this->unavailable();
                }
            }

            return $this->respond($body, $error->httpStatus);
        } catch (ValidationException) {
            return $this->respond(['error' => 'outlook_addin_wording_invalid', 'message' => 'Die persönlichen Vorlagentexte sind ungültig.'], 422);
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    private function unavailable(): JsonResponse
    {
        return $this->respond(['error' => 'outlook_addin_error', 'message' => 'Die persönlichen Vorlagentexte konnten vorübergehend nicht geladen werden.'], 500);
    }

    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Compose-Contract, X-RailTime-Outlook-Context',
        ]);
    }
}

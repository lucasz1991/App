<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            app(MicrosoftDeviceSyncScheduler::class)->afterMicrosoftSignIn($identity, $resolved['user']);
            $payload = $snapshots->currentForUser($resolved['user']);
            // Der persoenliche Snapshot ist wiederverwendbar, die Freigabe
            // fuer den aktuellen Absender dagegen strikt requestgebunden.
            $payload['binding'] = $resolved['binding'];
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $etag = '"'.hash('sha256', $encoded).'"';

            if (trim((string) $request->header('If-None-Match')) === $etag) {
                return response('', 304, $this->headers($etag));
            }

            return response()->json($payload, 200, $this->headers($etag));
        } catch (OutlookAddinException $exception) {
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

    /** @return array<string, string> */
    private function headers(?string $etag = null): array
    {
        return array_filter([
            'Cache-Control' => 'private, no-store, max-age=0',
            'ETag' => $etag,
            'Referrer-Policy' => 'no-referrer',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Outlook-Sender, X-RailTime-Compose-Contract',
            'X-Content-Type-Options' => 'nosniff',
        ], static fn (?string $value): bool => $value !== null);
    }
}

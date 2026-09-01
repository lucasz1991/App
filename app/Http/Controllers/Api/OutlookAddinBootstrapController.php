<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
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
        OutlookAddinPayloadService $payloadService,
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
            $user = $identityResolver->resolve($identity, $mailboxAddress);
            $payload = $payloadService->forUser($user);
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
            'Vary' => 'Authorization',
            'X-Content-Type-Options' => 'nosniff',
        ], static fn (?string $value): bool => $value !== null);
    }
}

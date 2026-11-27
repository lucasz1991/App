<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DeviceManagement\Desktop\OutlookDesktopPairingService;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RailTime-App erkennen und diesen PC koppeln. Nur das eigene Konto, keine Nachrichtendaten.
 * Das Add-in sperrt keine Funktion, wenn hier etwas fehlt oder scheitert.
 */
final class OutlookAddinDesktopClientController extends Controller
{
    public function __construct(
        private readonly EntraAccessTokenValidator $validator,
        private readonly OutlookAddinIdentityResolver $resolver,
        private readonly OutlookDesktopPairingService $pairings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($request): array {
            $this->assertEmpty($request);
            $key = $request->header('X-RailTime-Desktop-Pairing');

            return ['desktopClient' => $this->pairings->status($user, is_string($key) ? trim($key) : null)];
        });
    }

    public function start(Request $request): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($request): array {
            if (! $request->isJson() || $request->all() !== []) {
                throw new OutlookAddinException('Die Kopplung braucht keine weiteren Angaben.', 422, 'outlook_addin_pairing_invalid');
            }

            return ['pairing' => $this->pairings->start($user)];
        }, 201);
    }

    public function poll(Request $request, string $pairing): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($request, $pairing): array {
            $this->assertEmpty($request);

            return ['pairing' => $this->pairings->poll($user, $pairing)];
        });
    }

    private function guarded(Request $request, callable $action, int $status = 200): JsonResponse
    {
        try {
            $token = $request->bearerToken();
            if (! is_string($token) || trim($token) === '') {
                throw new OutlookAddinException('Die Microsoft-Anmeldung fehlt.', 401, 'outlook_addin_unauthorized');
            }
            $identity = $this->validator->validate($token);
            if ($request->header('X-RailTime-Compose-Contract') !== 'native-signature-v1') {
                throw new OutlookAddinException('Bitte die Outlook-App neu laden.', 409, 'outlook_addin_client_outdated');
            }
            if ($request->header('X-RailTime-Outlook-Context') !== 'account-settings') {
                throw new OutlookAddinException('Bitte die RailTime-Einstellungen öffnen.', 403, 'outlook_addin_preferences_context_required');
            }
            $resolved = $this->resolver->resolveOwnAccount($identity, (string) $request->header('X-RailTime-Outlook-Mailbox'));

            return $this->respond($action($resolved['user']), $status);
        } catch (OutlookAddinException $error) {
            return $this->respond(['error' => $error->errorCode, 'message' => $error->getMessage()], $error->httpStatus);
        } catch (ModelNotFoundException) {
            return $this->respond(['error' => 'outlook_addin_pairing_unknown', 'message' => 'Diese Kopplungsanfrage ist nicht bekannt.'], 404);
        } catch (HttpExceptionInterface $error) {
            $code = match ($error->getStatusCode()) {
                404 => 'outlook_addin_pairing_unknown',
                409 => 'outlook_addin_desktop_client_missing',
                429 => 'outlook_addin_pairing_throttled',
                503 => 'outlook_addin_desktop_client_unavailable',
                default => 'outlook_addin_pairing_invalid',
            };
            $message = $error->getStatusCode() === 404 ? 'Diese Kopplungsanfrage ist nicht bekannt.' : ($error->getMessage() ?: 'Die Kopplung ist gerade nicht möglich.');

            return $this->respond(['error' => $code, 'message' => $message], $error->getStatusCode());
        } catch (Throwable) {
            return $this->respond(['error' => 'outlook_addin_error', 'message' => 'Der Zustand der RailTime-App konnte vorübergehend nicht geladen werden.'], 500);
        }
    }

    private function assertEmpty(Request $request): void
    {
        if ($request->query() !== [] || $request->all() !== []) {
            throw new OutlookAddinException('Diese Abfrage braucht keine weiteren Angaben.', 422, 'outlook_addin_pairing_invalid');
        }
    }

    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Authorization, X-RailTime-Outlook-Mailbox, X-RailTime-Compose-Contract, X-RailTime-Outlook-Context, X-RailTime-Desktop-Pairing',
        ]);
    }
}

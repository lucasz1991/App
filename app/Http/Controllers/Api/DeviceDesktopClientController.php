<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceManagement\Desktop\DeviceDesktopService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DeviceDesktopClientController extends Controller
{
    public function enroll(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->enroll($this->input($request, [
            'enrollment_token', 'client_instance_id', 'client_version', 'platform', 'device_name',
        ])));
    }

    public function sync(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->sync($request->bearerToken() ?? '',
            $this->input($request, ['client_version', 'capabilities', 'report'])));
    }

    public function result(Request $request, string $job, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->result($request->bearerToken() ?? '', $job,
            $this->input($request, ['lease_id', 'status', 'exit_code', 'message'])));
    }

    private function input(Request $request, array $keys): array
    {
        abort_unless($request->isJson(), 415, 'JSON erforderlich.');
        $body = $request->getContent();
        abort_if(strlen($body) > 8192, 413);
        $object = json_decode($body, false, 8);
        abort_unless($object instanceof \stdClass && json_last_error() === JSON_ERROR_NONE, 422, 'JSON-Objekt erforderlich.');
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        abort_if(array_diff(array_keys($data), $keys) !== [], 422, 'Unbekannte Eingabefelder.');

        return $data;
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (ValidationException $exception) {
            return $this->json(['message' => 'Eingaben prüfen.', 'errors' => $exception->errors()], 422);
        } catch (AuthorizationException) {
            return $this->json(['message' => 'Clientanfrage nicht zugelassen.'], 403);
        } catch (ModelNotFoundException) {
            return $this->json(['message' => 'Clientanfrage nicht zugelassen.'], 404);
        } catch (HttpExceptionInterface $exception) {
            return $this->json(['message' => 'Clientanfrage nicht zugelassen.'], $exception->getStatusCode());
        } catch (Throwable $exception) {
            // Never give raw request arguments, SQL bindings or bearer values
            // to the global exception reporter (also protects APP_DEBUG=true).
            $reference = (string) Str::uuid();
            Log::error('Desktopclient request failed.', ['failure_class' => get_class($exception), 'reference' => $reference]);

            return $this->json(['message' => 'Ergebnis konnte nicht sicher bestätigt werden.', 'reference' => $reference], 500);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

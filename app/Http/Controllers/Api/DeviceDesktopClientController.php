<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SupportCase;
use App\Services\DeviceManagement\Desktop\DeviceDesktopService;
use App\Services\DeviceManagement\DeviceWorkplaceService;
use App\Services\Support\SupportCaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DeviceDesktopClientController extends Controller
{
    public function help(): JsonResponse
    {
        $contact = (array) Setting::getValueUncached('device_management', 'support');

        return $this->json(['version' => 1, 'support_url' => url('/support'), 'anonymous_upload' => false, 'email' => $contact['email'] ?? '', 'phone' => $contact['phone'] ?? '']);
    }

    public function supportCreate(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->withAuthenticated($request->bearerToken() ?? '', function ($client) use ($request): array {
            $service = app(SupportCaseService::class);
            $user = $client->assignment->user;
            $case = $service->create($user, $this->input($request, ['request_id', 'subject', 'message', 'category', 'diagnostics', 'diagnostics_confirmed']), $client);

            return $service->serialize($case, $user);
        }, requireManagement: false));
    }

    public function supportList(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->withAuthenticated($request->bearerToken() ?? '', function ($client): array {
            $service = app(SupportCaseService::class);

            return ['cases' => SupportCase::query()->where('client_id', $client->id)
                ->where('user_id', $client->assignment->user_id)->latest('updated_at')->limit(20)->get()
                ->map(fn ($case) => $service->serialize($case, $client->assignment->user))->all()];
        }, requireManagement: false));
    }

    public function supportReply(Request $request, string $supportCase, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->withAuthenticated($request->bearerToken() ?? '', function ($client) use ($request, $supportCase): array {
            $case = SupportCase::query()->where('public_id', $supportCase)->where('client_id', $client->id)
                ->where('user_id', $client->assignment->user_id)->firstOrFail();
            $data = $this->input($request, ['request_id', 'message', 'status']);
            $service = app(SupportCaseService::class);
            $user = $client->assignment->user;
            if (isset($data['message'])) {
                $service->reply($case, $user, (string) ($data['request_id'] ?? ''), (string) $data['message']);
            } else {
                $service->transition($case, $user, (string) ($data['status'] ?? ''));
            }

            return $service->serialize($case->fresh(), $user);
        }, requireManagement: false));
    }

    public function workplace(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(fn () => $clients->withAuthenticated($request->bearerToken() ?? '',
            fn ($client, $device): array => app(DeviceWorkplaceService::class)->summary($device), requireManagement: false));
    }

    public function withdraw(Request $request, DeviceDesktopService $clients): JsonResponse
    {
        return $this->respond(function () use ($request, $clients): array {
            $data = $this->input($request, ['confirmed']);
            abort_unless(($data['confirmed'] ?? null) === true, 422);
            $token = $request->bearerToken() ?? '';
            abort_unless(strlen($token) >= 32 && strlen($token) <= 512, 401);
            $hash = hash('sha256', $token);
            $receipt = ['revoked' => true, 'cleanup_state' => 'pending', 'message' => 'Zugriff gesperrt – lokale Bereinigung ausstehend.'];
            // A revoked bearer may only recover this minimal receipt, never authenticate elsewhere.
            if (DB::table('device_withdrawal_receipts')->where('token_hash', $hash)->where('expires_at', '>', now())->exists()) {
                return $receipt;
            }

            return $clients->withAuthenticated($token, function ($client, $device) use ($hash, $receipt): array {
                app(DeviceWorkplaceService::class)->revoke($device, $client->assignment->user);
                DB::table('device_withdrawal_receipts')->updateOrInsert(['token_hash' => $hash], ['expires_at' => now()->addDays(7)]);

                return $receipt;
            }, requireManagement: false);
        });
    }

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

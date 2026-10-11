<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\SessionPermissionGrant;
use App\Notifications\RailtimeWebPushNotification;
use App\Rules\PushEndpoint;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushSubscriptionController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $diagnostics = WebPushConfiguration::diagnostics();
        $permissions = app(SessionPermissionService::class);
        $grant = $permissions->current($request->user());
        $sessionAllowed = $grant !== null && $permissions->active($grant, 'push');
        $preferences = collect(PushCategory::cases())
            ->mapWithKeys(fn (PushCategory $category): array => [
                $category->value => (bool) $request->user()
                    ->notificationPreferences()
                    ->where('category', $category->value)
                    ->value('web_push_enabled'),
            ]);

        return response()->json([
            'enabled' => $diagnostics['enabled'],
            'configured' => $diagnostics['configured'],
            'ready' => $diagnostics['ready'],
            'configuration_issues' => $diagnostics['issues'],
            'auto_provisioned' => $diagnostics['auto_provisioned'],
            'session_decision' => $grant?->push_decision,
            'session_allowed' => $sessionAllowed,
            'subscription_count' => $sessionAllowed ? $request->user()
                ->pushSubscriptions()->whereNull('revoked_at')
                ->where('session_permission_grant_id', $grant->id)->count() : 0,
            'preferences' => $preferences,
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        abort_unless(config('webpush.enabled'), 404);
        abort_unless(WebPushConfiguration::isConfigured(), 503);

        $data = $request->validated();
        $permissions = app(SessionPermissionService::class);
        $current = $permissions->current($request->user());
        abort_unless($current && $permissions->active($current, 'push'), 403,
            'Bitte Push-Benachrichtigungen zuerst für diese Anmeldung freigeben.');

        $subscription = DB::transaction(function () use ($request, $data, $permissions, $current): PushSubscription {
            $grant = SessionPermissionGrant::query()->lockForUpdate()->find($current->id);
            abort_unless($grant && $permissions->active($grant, 'push'), 403);

            $subscription = $request->user()->updatePushSubscription(
                endpoint: $data['endpoint'],
                key: $data['keys']['p256dh'],
                token: $data['keys']['auth'],
                contentEncoding: $data['contentEncoding'] ?? 'aes128gcm',
            );

            $subscription->forceFill([
                'session_permission_grant_id' => $grant->id,
                'device_name' => $data['deviceName'] ?? null,
                'platform' => $data['platform'] ?? 'unknown',
                'browser' => $data['browser'] ?? null,
                'user_agent_hash' => filled($request->userAgent())
                    ? hash('sha256', $request->userAgent())
                    : null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();

            $request->user()->enableDefaultPushPreferences();

            return $subscription;
        });

        return response()->json([
            'subscribed' => true,
            'subscription_id' => $subscription->getKey(),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate([
            'subscription_id' => ['nullable', 'required_without:endpoint', 'integer', 'min:1'],
            'endpoint' => ['nullable', 'required_without:subscription_id', 'url', 'max:4096', new PushEndpoint],
        ]);

        $subscription = $request->user()
            ->pushSubscriptions()
            ->whereNull('revoked_at');

        if (filled($data['subscription_id'] ?? null)) {
            $subscription->whereKey((int) $data['subscription_id']);
        } else {
            $subscription->where(
                'endpoint_hash',
                PushSubscription::hashEndpoint($data['endpoint']),
            );
        }

        $subscription->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $allowed = collect(PushCategory::cases())->pluck('value')->all();
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $category => $enabled) {
            abort_unless(in_array($category, $allowed, true), 422);

            $request->user()->notificationPreferences()->updateOrCreate(
                ['category' => $category],
                ['web_push_enabled' => $enabled],
            );
        }

        return response()->json(['saved' => true]);
    }

    public function test(Request $request): JsonResponse
    {
        abort_unless(config('webpush.enabled') && config('webpush.test_enabled'), 404);
        abort_unless(WebPushConfiguration::isConfigured(), 503);
        $permissions = app(SessionPermissionService::class);
        $grant = $permissions->current($request->user());
        abort_unless($grant && $permissions->active($grant, 'push'), 403);

        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'min:1'],
        ]);
        $subscription = $request->user()
            ->pushSubscriptions()
            ->whereNull('revoked_at')
            ->where('session_permission_grant_id', $grant->id)
            ->find($data['subscription_id']);

        abort_if(! $subscription, 422, __('app.push_no_active_device'));

        $request->user()->notify(new RailtimeWebPushNotification(
            notificationId: 'test:'.Str::uuid(),
            title: __('app.push_test_title'),
            body: __('app.push_test_body'),
            url: 'user/profile?tab=app',
            category: PushCategory::Messages,
            bypassPreferences: true,
            targetSubscriptionId: $subscription->getKey(),
        ));

        return response()->json(['queued' => true], 202);
    }
}

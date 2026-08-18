<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceCommandStatus;
use App\Http\Controllers\Controller;
use App\Models\DeviceArtifact;
use App\Models\DeviceCommand;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DeviceArtifactDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        DeviceArtifact $artifact,
        DeviceProviderRegistry $providers,
        DeviceManagementSettings $settings,
    ): StreamedResponse|BinaryFileResponse {
        try {
            $driver = $providers->get($provider, fresh: true);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($driver->enabled(), 404);
        // Artifact delivery is part of the external command path. Re-read the
        // global kill switch here so an emergency shutdown also stops a
        // connector that has not downloaded a queued script/package yet.
        abort_unless($providers->commandsEnabledFor($provider), 404);
        abort_unless($artifact->approved_at && $artifact->device_id, 404);

        $timestamp = $request->header('X-RailTime-Timestamp');
        $signature = $request->header('X-RailTime-Signature');
        $secret = $providers->webhookSecret($provider);
        abort_unless(is_string($timestamp) && ctype_digit($timestamp) && is_string($signature) && $secret !== '', 401);

        $tolerance = $settings->webhookToleranceSeconds();
        abort_unless(abs(now()->getTimestamp() - (int) $timestamp) <= $tolerance, 401);

        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $canonical = $timestamp.'.GET.'.$request->getPathInfo();
        $expected = hash_hmac('sha256', $canonical, $secret);
        abort_unless(strlen($signature) === 64 && hash_equals($expected, strtolower($signature)), 401);

        // Das Artefakt ist nicht allgemein mit einem Provider-Token ladbar.
        // Es muss zu einem gerade versandten, passenden Command gehoeren.
        $authorized = DeviceCommand::query()
            ->where('device_id', $artifact->device_id)
            ->where('provider', $provider)
            ->whereIn('status', [DeviceCommandStatus::Dispatched->value, DeviceCommandStatus::Running->value])
            ->where('requested_at', '>=', now()->subDay())
            ->latest('requested_at')
            ->limit(50)
            ->get()
            ->contains(fn (DeviceCommand $command): bool => is_array($command->payload)
                && hash_equals(
                    (string) ($command->payload['artifact_public_id'] ?? ''),
                    (string) $artifact->public_id,
                ));
        abort_unless($authorized, 403);

        $disk = (string) $artifact->disk;
        abort_unless(config("filesystems.disks.{$disk}.visibility") === 'private', 404);
        abort_unless(Storage::disk($disk)->exists($artifact->storage_path), 404);

        activity('device-management')
            ->performedOn($artifact->device)
            ->event('device-artifact.downloaded-by-connector')
            ->withProperties([
                'artifact_id' => (string) $artifact->public_id,
                'provider' => $provider,
                'sha256' => $artifact->sha256,
            ])
            ->log('Geräteartefakt an Connector ausgeliefert');

        return Storage::disk($disk)->download(
            $artifact->storage_path,
            basename((string) ($artifact->original_name ?: $artifact->name)),
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Content-SHA256' => $artifact->sha256,
            ],
        );
    }
}

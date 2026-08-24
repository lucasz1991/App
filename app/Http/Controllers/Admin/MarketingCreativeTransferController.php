<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ImportMarketingCreativeRequest;
use App\Models\MarketingCreative;
use App\Services\Marketing\MarketingCreativeTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MarketingCreativeTransferController extends Controller
{
    public function export(
        Request $request,
        MarketingCreative $creative,
        MarketingCreativeTransferService $transfer,
    ): StreamedResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $json = json_encode(
                $transfer->export($creative),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'creative' => 'Das Motiv konnte nicht als JSON-Paket serialisiert werden.',
            ]);
        }

        $slug = Str::slug((string) $creative->title) ?: 'marketing-motiv';

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $slug.'-railtime-motiv-v'.MarketingCreativeTransferService::VERSION.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ],
        );
    }

    public function import(
        ImportMarketingCreativeRequest $request,
        MarketingCreativeTransferService $transfer,
    ): RedirectResponse {
        $uploaded = $request->file('bundle');
        $realPath = $uploaded?->getRealPath();
        $contents = is_string($realPath) && $realPath !== ''
            ? file_get_contents($realPath)
            : false;
        if (! is_string($contents) || trim($contents) === '') {
            throw ValidationException::withMessages([
                'bundle' => 'Das ausgewählte Motivpaket ist leer oder nicht lesbar.',
            ]);
        }

        try {
            $bundle = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'bundle' => 'Die Datei enthält kein gültiges JSON-Motivpaket.',
            ]);
        }
        if (! is_array($bundle)) {
            throw ValidationException::withMessages([
                'bundle' => 'Die Datei enthält kein gültiges RailTime-Motivpaket.',
            ]);
        }

        try {
            $creative = $transfer->import($bundle, $request->user());
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->filter()->values();

            throw ValidationException::withMessages([
                'bundle' => (string) ($messages->first() ?: 'Das Motivpaket konnte nicht sicher importiert werden.'),
            ]);
        }

        return redirect()
            ->route('admin.marketing.creatives.index')
            ->with('marketing_import_success', sprintf(
                '„%s“ wurde vollständig als neuer Entwurf importiert.',
                $creative->title,
            ));
    }
}

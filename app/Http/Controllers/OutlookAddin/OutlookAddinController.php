<?php

namespace App\Http\Controllers\OutlookAddin;

use App\Http\Controllers\Controller;
use App\Support\OutlookAddin\OutlookAddinBuild;
use App\Support\OutlookAddin\OutlookAddinConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

final class OutlookAddinController extends Controller
{
    public function config(OutlookAddinConfiguration $configuration): JsonResponse
    {
        return response()->json($configuration->publicConfiguration(), 200, [
            'Cache-Control' => 'public, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function taskpane(): Response
    {
        return $this->addinView('outlook-addin.taskpane', 'taskpane');
    }

    public function runtime(): Response
    {
        return $this->addinView('outlook-addin.runtime', 'runtime');
    }

    public function bundle(string $bundle): Response
    {
        abort_unless(in_array($bundle, ['runtime', 'taskpane'], true), 404);

        $path = public_path("outlook-addin/{$bundle}.js");
        abort_unless(is_file($path) && is_readable($path), 503, 'Das Outlook-Add-in wurde noch nicht gebaut.');

        $javascript = file_get_contents($path);
        abort_unless(is_string($javascript), 503, 'Das Outlook-Add-in konnte nicht geladen werden.');

        // Old manifests still receive current code, but only an exact content
        // revision may be cached. The same rule applies to both bundles.
        $version = request()->query('v');
        $cacheable = is_string($version) && preg_match('/\A[0-9a-f]{64}\z/', $version)
            && hash_equals(hash('sha256', $javascript), $version);

        return response($javascript, 200, [
            'Cache-Control' => $cacheable ? 'public, max-age=3600' : 'no-store, no-cache, max-age=0, must-revalidate',
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function allowed(OutlookAddinConfiguration $configuration): JsonResponse
    {
        $baseUrl = $configuration->baseUrl(throwWhenInvalid: false);

        return response()->json([
            'allowed' => $baseUrl !== ''
                ? array_values(array_unique([app(OutlookAddinBuild::class)->bundleUrl($baseUrl, 'runtime'), $baseUrl.'/outlook-addin/runtime.js']))
                : [],
        ], 200, [
            'Cache-Control' => 'public, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function icon(int $size): Response
    {
        abort_unless(in_array($size, [16, 25, 32, 48, 64, 80, 128], true), 404);

        $source = public_path('icons/app-icon-1024.png');
        abort_unless(is_file($source) && is_readable($source), 404);

        $manager = new ImageManager(new Driver);
        $png = (string) $manager->read($source)
            ->removeAnimation()
            ->removeProfile()
            ->resize($size, $size)
            ->toPng();

        return response($png, 200, [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function addinView(string $view, string $bundle): Response
    {
        $baseUrl = app(OutlookAddinConfiguration::class)->baseUrl(throwWhenInvalid: false);
        $resolvedBaseUrl = $baseUrl !== '' ? $baseUrl : rtrim(url('/'), '/');

        $response = response()->view($view, [
            'configUrl' => $resolvedBaseUrl.'/outlook-addin/config.json',
            'scriptUrl' => $this->versionedBundleUrl($resolvedBaseUrl, $bundle),
        ]);

        $response->headers->set('Cache-Control', 'public, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Content-Security-Policy',
            // Office.js can select this exact Microsoft AJAX fallback on iOS.
            // Keep the exception path-scoped; do not enable arbitrary scripts/eval.
            "default-src 'none'; script-src 'self' https://appsforoffice.microsoft.com https://ajax.aspnetcdn.com/ajax/3.5/MicrosoftAjax.js; connect-src 'self' https://login.microsoftonline.com; frame-src https://login.microsoftonline.com; img-src 'self' data:; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        );

        return $response;
    }

    private function versionedBundleUrl(string $baseUrl, string $bundle): string
    {
        return app(OutlookAddinBuild::class)->bundleUrl($baseUrl, $bundle);
    }
}

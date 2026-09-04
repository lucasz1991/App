<?php

namespace App\Http\Controllers\OutlookAddin;

use App\Http\Controllers\Controller;
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

        return response($javascript, 200, [
            'Cache-Control' => 'public, max-age=3600',
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function allowed(OutlookAddinConfiguration $configuration): JsonResponse
    {
        $baseUrl = $configuration->baseUrl(throwWhenInvalid: false);

        return response()->json([
            'allowed' => $baseUrl !== ''
                ? [$baseUrl.'/outlook-addin/runtime.js']
                : [],
        ], 200, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function icon(int $size): Response
    {
        abort_unless(in_array($size, [16, 32, 64, 80, 128], true), 404);

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
            "default-src 'none'; script-src 'self' https://appsforoffice.microsoft.com; connect-src 'self' https://login.microsoftonline.com; frame-src https://login.microsoftonline.com; img-src 'self' data:; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        );

        return $response;
    }

    private function versionedBundleUrl(string $baseUrl, string $bundle): string
    {
        $url = $baseUrl."/outlook-addin/{$bundle}.js";
        $path = public_path("outlook-addin/{$bundle}.js");

        if (! is_file($path) || ! is_readable($path)) {
            return $url;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) && $hash !== ''
            ? $url.'?v='.substr($hash, 0, 16)
            : $url;
    }
}

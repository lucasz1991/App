<?php

namespace App\Http\Controllers\EmployeeLocation;

use App\Http\Controllers\Controller;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class EmployeeLocationLinksController extends Controller
{
    public function android(): JsonResponse
    {
        $settings = EmployeeLocationSettings::read();
        $entries = $settings['android_certificate_sha256'] !== '' ? [['relation' => ['delegate_permission/common.handle_all_urls'], 'target' => ['namespace' => 'android_app', 'package_name' => $settings['android_package'], 'sha256_cert_fingerprints' => [$settings['android_certificate_sha256']]]]] : [];

        return response()->json($entries)->header('Cache-Control', 'public, max-age=300');
    }

    public function apple(): JsonResponse
    {
        $settings = EmployeeLocationSettings::read();
        $details = $settings['ios_app_id'] !== '' ? [['appID' => $settings['ios_app_id'], 'paths' => ['/employee-location/open']]] : [];

        return response()->json(['applinks' => ['apps' => [], 'details' => $details]])->header('Cache-Control', 'public, max-age=300');
    }

    public function open(): Response
    {
        return response()->view('employee-location.open', ['settings' => EmployeeLocationSettings::read()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }
}

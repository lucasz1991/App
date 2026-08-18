<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceInventoryTemplateController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        Gate::authorize('devices.manage');

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            if (! is_resource($output)) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Inventarnummer', 'Seriennummer', 'Gerätename', 'Hostname',
                'Plattform', 'Gerätetyp', 'Eigentum', 'Hersteller', 'Modell',
                'Betriebssystem', 'Standort', 'Mitarbeiter_E-Mail',
            ], ';');
            fputcsv($output, [
                'RT-WIN-0142', 'ABC123456', 'Dienstlaptop Windows', 'RT-NB-0142',
                'windows', 'laptop', 'corporate', 'Dell', 'Latitude 5440',
                'Windows 11 23H2', 'Köln Hbf', 'max.mustermann@rail-time.de',
            ], ';');
            fclose($output);
        }, 'railtime-geraete-importvorlage.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

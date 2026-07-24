<?php

namespace App\Http\Controllers;

use App\Support\WagonListWorkbookExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WagonListExportController extends Controller
{
    public function __invoke(Request $request, WagonListWorkbookExporter $exporter): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user && in_array($user->dashboardAudience(), [
            'admin',
            'administration',
            'management',
            'employee',
        ], true), 403);

        $payload = $request->validate([
            'meta' => ['required', 'array'],
            'meta.trainNumber' => ['nullable', 'string', 'max:80'],
            'meta.date' => ['nullable', 'date'],
            'meta.origin' => ['nullable', 'string', 'max:120'],
            'meta.destination' => ['nullable', 'string', 'max:120'],
            'meta.reference' => ['nullable', 'string', 'max:160'],
            'wagons' => ['required', 'array', 'max:40'],
            'wagons.*' => ['array'],
            'wagons.*.number12' => ['nullable', 'string', 'max:2'],
            'wagons.*.number34' => ['nullable', 'string', 'max:2'],
            'wagons.*.number58' => ['nullable', 'string', 'max:4'],
            'wagons.*.number911' => ['nullable', 'string', 'max:3'],
            'wagons.*.checkDigit' => ['nullable', 'string', 'max:1'],
            'wagons.*.category' => ['nullable', 'string', 'max:80'],
            'wagons.*.axlesEmpty' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.axlesLoaded' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.length' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.wagonWeight' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.loadWeight' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.brakeG' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.brakeP' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.shippingStation' => ['nullable', 'string', 'max:120'],
            'wagons.*.destinationStation' => ['nullable', 'string', 'max:120'],
            'wagons.*.brakeType' => ['nullable', 'in:K,L,LL'],
            'wagons.*.discBrake' => ['nullable', 'boolean'],
            'wagons.*.parkingBrake' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.maxSpeed' => ['nullable', 'numeric', 'min:0'],
            'wagons.*.remark' => ['nullable', 'string', 'max:255'],
            'brakeSheet' => ['required', 'array'],
            'brakeSheet.tractionWeight' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.tractionBrakeWeight' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.tractionAxles' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.minimumBrakePercentage' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.brakedAxles' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.lowerVehicleSpeed' => ['nullable', 'numeric', 'min:0'],
            'brakeSheet.nbuepBrake' => ['nullable', 'in:yes,no'],
            'brakeSheet.emergencyBrakeBridge' => ['nullable', 'in:yes,no'],
            'brakeSheet.passengerFeatureHzee' => ['nullable', 'in:yes,no'],
            'brakeSheet.passengerFeatureNOe' => ['nullable', 'in:yes,no'],
            'brakeSheet.passengerFeatureTb0' => ['nullable', 'in:yes,no'],
            'brakeSheet.passengerFeatureOZub' => ['nullable', 'in:yes,no'],
            'brakeSheet.passengerFeatureOther' => ['nullable', 'in:yes,no'],
            'brakeSheet.dangerousGoods' => ['nullable', 'in:yes,no'],
            'brakeSheet.epBrake' => ['nullable', 'in:yes,no'],
            'brakeSheet.issuerName' => ['nullable', 'string', 'max:160'],
        ]);

        $path = $exporter->export($payload);
        $trainNumber = Str::slug((string) data_get($payload, 'meta.trainNumber'));
        $date = data_get($payload, 'meta.date') ?: now()->format('Y-m-d');
        $filename = 'RailTime_Wagenliste'
            .($trainNumber !== '' ? '_'.$trainNumber : '')
            .'_'.$date.'.xlsx';

        return response()
            ->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}

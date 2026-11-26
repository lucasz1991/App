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

        $payload = $this->validatedPayload($request);
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

    public function attach(Request $request, int $proof, \App\Services\Operations\WagonDutyProofService $service)
    {
        $actor = $request->user();
        $record = \App\Models\OperationWorkflow::findOrFail($proof);
        $shift = app(\App\Services\Operations\CustomerProofService::class)->authorizeWagon($record, $actor);
        $revision = $request->validate(['revision' => 'required|integer|min:1'])['revision'];
        $data = $this->validatedPayload($request);
        $service->store($record, (int) $revision, $data, $actor);

        return response()->json(['redirect' => route('operations.duty-proof', ['duty' => $shift->public_id, 'section' => $record->payload['duty']['section_id'] ?? null])]);
    }

    private function validatedPayload(Request $request): array
    {
        // Felder und Grenzen folgen der Excel-Wagenliste v17. Zahlen dürfen
        // mit Komma kommen (deutsche Eingabe), deshalb keine numeric-Regel.
        $number = ['nullable', 'regex:/\A\d{1,6}(?:[.,]\d{1,3})?\z/'];
        $yesNo = ['nullable', 'in:yes,no'];

        return $request->validate([
            'meta' => ['required', 'array'],
            'meta.trainNumber' => ['nullable', 'string', 'max:80'],
            'meta.date' => ['nullable', 'date_format:Y-m-d'],
            'meta.origin' => ['nullable', 'string', 'max:120'],
            'meta.destination' => ['nullable', 'string', 'max:120'],
            'meta.reference' => ['nullable', 'string', 'max:160'],
            'meta.customer' => ['nullable', 'string', 'max:120'],
            'meta.track' => ['nullable', 'string', 'max:40'],
            'meta.preparedBy' => ['nullable', 'string', 'max:160'],
            'meta.preparedTime' => ['nullable', 'regex:/\A(?:[01]?\d|2[0-3]):[0-5]\d\z/'],
            'wagons' => ['required', 'array', 'max:40'],
            'wagons.*' => ['array'],
            'wagons.*.number12' => ['nullable', 'regex:/\A\d{1,2}\z/'],
            'wagons.*.number34' => ['nullable', 'regex:/\A\d{1,2}\z/'],
            'wagons.*.number58' => ['nullable', 'regex:/\A\d{1,4}\z/'],
            'wagons.*.number911' => ['nullable', 'regex:/\A\d{1,3}\z/'],
            'wagons.*.checkDigit' => ['nullable', 'regex:/\A\d\z/'],
            'wagons.*.category' => ['nullable', 'string', 'max:80'],
            'wagons.*.axles' => $number,
            'wagons.*.length' => $number,
            'wagons.*.wagonWeight' => $number,
            'wagons.*.loadWeight' => $number,
            'wagons.*.brakeG' => $number,
            'wagons.*.brakeP' => $number,
            'wagons.*.shippingStation' => ['nullable', 'string', 'max:120'],
            'wagons.*.destinationStation' => ['nullable', 'string', 'max:120'],
            'wagons.*.brakeType' => ['nullable', 'in:K,L,LL,D,F'],
            'wagons.*.parkingBrake' => $number,
            'wagons.*.maxSpeed' => $number,
            'wagons.*.unNumber' => ['nullable', 'regex:/\A\d{4}(?:\+\d{4}){0,7}\z/'],
            'wagons.*.hazardLabel' => ['nullable', 'string', 'max:20'],
            'wagons.*.remark' => ['nullable', 'string', 'max:255'],
            // Entwürfe der früheren Liste: zwei Achsspalten und Scheibenbremse als Kästchen.
            'wagons.*.axlesEmpty' => $number,
            'wagons.*.axlesLoaded' => $number,
            'wagons.*.discBrake' => ['nullable', 'boolean'],
            'brakeSheet' => ['required', 'array'],
            'brakeSheet.tractionWeight' => $number,
            'brakeSheet.tractionBrakeWeight' => $number,
            'brakeSheet.tractionAxles' => $number,
            'brakeSheet.tractionLength' => $number,
            'brakeSheet.minimumBrakePercentage' => $number,
            'brakeSheet.brakePosition' => ['nullable', 'in:R/P,G'],
            'brakeSheet.listOrder' => ['nullable', 'in:head,tail'],
            'brakeSheet.ninetyPercentRule' => ['nullable', 'in:no,yes,yes800'],
            'brakeSheet.timetableLoad' => $number,
            'brakeSheet.timetableLength' => $number,
            'brakeSheet.timetableSpeed' => $number,
            'brakeSheet.routeClass' => ['nullable', 'in:A,B1,B2,C2,C3,C4,CE,D2,D3,D4,E4,E5'],
            'brakeSheet.singleReleaseBrakes' => $number,
            'brakeSheet.matrossowBrakes' => $number,
            'brakeSheet.nbuepBrake' => $yesNo,
            'brakeSheet.emergencyBrakeBridge' => $yesNo,
            'brakeSheet.passengerFeatureHzee' => $yesNo,
            'brakeSheet.passengerFeatureNOe' => $yesNo,
            'brakeSheet.passengerFeatureTb0' => $yesNo,
            'brakeSheet.passengerFeatureOZub' => $yesNo,
            'brakeSheet.passengerFeatureOther' => $yesNo,
            'brakeSheet.dangerousGoods' => $yesNo,
            'brakeSheet.epBrake' => $yesNo,
            'brakeSheet.issuerName' => ['nullable', 'string', 'max:160'],
            'report' => ['nullable', 'array'],
            // Neben den Werten der v17 auch die der früheren, längeren Fassung,
            // damit ein vor dem Update geöffneter Entwurf noch exportiert.
            'report.papers' => ['nullable', 'in:complete,none,electronic,paper,both'],
            'report.transportOrder' => ['nullable', 'in:complete,standingOrNone,standing,none'],
            'report.tasks' => ['nullable', 'array'],
            'report.tasks.*' => ['boolean'],
            'report.wagonInspectionLevel' => ['nullable', 'in:1,2,3,4'],
            'report.extraWork' => ['nullable', 'array', 'max:5'],
            'report.extraWork.*' => ['nullable', 'string', 'max:160'],
            'report.remarks' => ['nullable', 'string', 'max:600'],
        ]);
    }
}

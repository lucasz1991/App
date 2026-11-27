<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Arr;
use RuntimeException;
use ZipArchive;

/**
 * Füllt die Excel-Wagenliste v17_3 (Blätter Wagenliste, Bremszettel,
 * Meldezettel) mit einem Entwurf aus dem Browser.
 *
 * Die Vorlage rechnet selbst: Geschrieben werden nur Eingabefelder, alle
 * Formeln bleiben stehen und Excel rechnet beim Öffnen neu. Damit Vorschauen
 * ohne Rechenkern (Telefon, Mail-Anhang) trotzdem Summen zeigen, bekommen die
 * wichtigsten Formelzellen den hier berechneten Wert als Zwischenstand. Die
 * Rechenregeln entsprechen resources/js/wagon-list-rules.js.
 */
class WagonListWorkbookExporter
{
    public const TEMPLATE = 'templates/RT-WAGENLISTE_v17_3.xlsx';

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const FIRST_ROW = 7;

    private const MAX_WAGONS = 40;

    private const MAX_CALCULATION_LENGTH = 815;

    private const SOLE_TYPES = ['K', 'L', 'LL', 'D', 'F'];

    private const COMPOSITE_SOLES = ['K', 'L', 'LL'];

    /** @var array<int, string>|null */
    private ?array $sharedStrings = null;

    /** @var \WeakMap<DOMDocument, array<string, DOMElement>>|null Zellen je Blatt nach Bezug */
    private ?\WeakMap $cells = null;

    public function export(array $payload): string
    {
        $template = resource_path(self::TEMPLATE);

        if (! is_file($template)) {
            throw new RuntimeException('Die Wagenlisten-Vorlage wurde nicht gefunden.');
        }

        $exportDirectory = storage_path('app/private/exports');
        if (! is_dir($exportDirectory) && ! mkdir($exportDirectory, 0755, true) && ! is_dir($exportDirectory)) {
            throw new RuntimeException('Das Exportverzeichnis konnte nicht angelegt werden.');
        }

        $target = $exportDirectory.DIRECTORY_SEPARATOR.'wagon-list-'.bin2hex(random_bytes(10)).'.xlsx';
        if (! copy($template, $target)) {
            throw new RuntimeException('Die Wagenlisten-Vorlage konnte nicht kopiert werden.');
        }

        $zip = new ZipArchive;
        if ($zip->open($target) !== true) {
            @unlink($target);
            throw new RuntimeException('Die Wagenlisten-Vorlage konnte nicht geöffnet werden.');
        }

        try {
            $this->sharedStrings = null;

            $meta = (array) ($payload['meta'] ?? []);
            $brakeSheet = (array) ($payload['brakeSheet'] ?? []);
            $report = (array) ($payload['report'] ?? []);
            $wagons = array_map(
                fn (mixed $wagon): array => $this->normalizeWagon((array) $wagon),
                array_values(array_slice((array) ($payload['wagons'] ?? []), 0, self::MAX_WAGONS))
            );

            $totals = $this->totals($wagons, $brakeSheet);
            $sheet = $this->brakeSheetTotals($totals, $wagons, $brakeSheet);
            $paths = $this->sheetPaths($zip);
            // Auswahltexte lesen, bevor ein Blatt verändert wird.
            $choices = $this->templateChoices($zip, $this->loadXml($zip, $paths['Wagenliste'])[1]);

            $this->writeWagonSheet($zip, $paths['Wagenliste'], $choices, $meta, $wagons, $brakeSheet, $totals, $sheet);
            $this->writeBrakeSheet($zip, $paths['Bremszettel'], $choices, $meta, $brakeSheet, $totals, $sheet);
            $this->writeReportSheet($zip, $paths['Meldezettel'], $choices, $meta, $report, $sheet);
            $this->removeCalculationChain($zip);
            $this->enableFullCalculation($zip);
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($target);

            throw $exception;
        }

        $zip->close();

        return $target;
    }

    // ---- Blatt Wagenliste ------------------------------------------------

    private function writeWagonSheet(
        ZipArchive $zip,
        string $path,
        array $choices,
        array $meta,
        array $wagons,
        array $brakeSheet,
        array $totals,
        array $sheet
    ): void {
        [$document, $xpath] = $this->loadXml($zip, $path);
        $this->clearFormulaResults($xpath);

        $this->setTextOrNumber($document, $xpath, 'D2', Arr::get($meta, 'trainNumber'));
        $this->setNumber($document, $xpath, 'K2', $this->excelDate(Arr::get($meta, 'date')));
        $this->setString($document, $xpath, 'D3', Arr::get($meta, 'origin'));
        $this->setString($document, $xpath, 'K3', Arr::get($meta, 'destination'));
        $this->setString($document, $xpath, 'T2', Arr::get($meta, 'reference'));

        foreach ($wagons as $index => $wagon) {
            if (! $this->isWagonFilled($wagon)) {
                // Leere Zeilen bleiben unberührt: dort stehen die Formeln der
                // Vorlage (Nummernvorbelegung, Gattung, Gesamtgewicht).
                continue;
            }

            $row = self::FIRST_ROW + $index;

            foreach (['C' => 'number12', 'D' => 'number34', 'E' => 'number58', 'F' => 'number911', 'G' => 'checkDigit'] as $column => $field) {
                $digits = preg_replace('/\D+/', '', (string) Arr::get($wagon, $field, ''));
                if ($digits !== '') {
                    $this->setNumber($document, $xpath, $column.$row, (float) $digits);
                } elseif ($index === 0 || in_array($column, ['F', 'G'], true)) {
                    $this->setNumber($document, $xpath, $column.$row, null);
                }
            }

            if (trim((string) Arr::get($wagon, 'category', '')) !== '') {
                $this->setString($document, $xpath, 'H'.$row, Arr::get($wagon, 'category'));
            }

            foreach ([
                'I' => 'axles',
                'J' => 'length',
                'K' => 'wagonWeight',
                'L' => 'loadWeight',
                'N' => 'brakeG',
                'O' => 'brakeP',
                'T' => 'parkingBrake',
                'U' => 'maxSpeed',
            ] as $column => $field) {
                $this->setNumber($document, $xpath, $column.$row, $this->nullableNumber(Arr::get($wagon, $field)));
            }

            foreach ([
                'P' => 'shippingStation',
                'Q' => 'destinationStation',
                'R' => 'brakeType',
                'V' => 'unNumber',
                'W' => 'hazardLabel',
                'X' => 'remark',
            ] as $column => $field) {
                $this->setString($document, $xpath, $column.$row, Arr::get($wagon, $field));
            }

            $this->setFormulaCachedValue($document, $xpath, 'M'.$row, $this->wagonWeightOrNull($wagon));
        }

        $this->setNumber($document, $xpath, 'U50', $this->excelTime(Arr::get($meta, 'preparedTime')));
        $this->setString($document, $xpath, 'X50', Arr::get($meta, 'preparedBy'));
        $this->setString($document, $xpath, 'Q55', $totals['brakePosition']);
        $this->setString($document, $xpath, 'R59', $choices['order'][Arr::get($brakeSheet, 'listOrder') === 'tail' ? 1 : 0]);
        $this->setString($document, $xpath, 'R60', $choices['ninety'][match ((string) Arr::get($brakeSheet, 'ninetyPercentRule')) {
            'yes' => 1,
            'yes800' => 2,
            default => 0,
        }]);
        $this->setNumber($document, $xpath, 'A60', $this->nullableNumber(Arr::get($brakeSheet, 'timetableLoad')));
        $this->setNumber($document, $xpath, 'F60', $this->nullableNumber(Arr::get($brakeSheet, 'timetableLength')));
        $this->setNumber($document, $xpath, 'K60', $this->nullableNumber(Arr::get($brakeSheet, 'minimumBrakePercentage')));
        $this->setNumber($document, $xpath, 'A62', $this->nullableNumber(Arr::get($brakeSheet, 'timetableSpeed')));
        $this->setString($document, $xpath, 'F62', Arr::get($brakeSheet, 'routeClass'));

        foreach ([
            'I47' => $totals['axles'],
            'J47' => $totals['length'],
            'K47' => $totals['wagonWeight'],
            'L47' => $totals['loadWeight'],
            'M47' => $totals['totalWeight'],
            'N47' => $totals['brakeG'],
            'O47' => $totals['brakeP'],
            'F49' => $totals['axles'],
            'K49' => $totals['lengthRounded'] > 0 ? $totals['lengthRounded'] : null,
            'N49' => $totals['deductionG'],
            'F50' => $totals['brakeCount'],
            'O50' => $totals['deductionP19'],
            'F51' => $totals['compositeBrakes'],
            'K51' => $totals['weightRounded'] > 0 ? $totals['weightRounded'] : null,
            'O51' => $totals['deductionP10'],
            'F52' => $totals['discBrakes'],
            'K52' => $totals['usableBrakeWeight'],
            'O52' => $totals['deductionP5'],
            'O53' => $totals['deductionGLength'],
            'K62' => match ($sheet['dangerousGoodsAuto']) {
                true => 'ja',
                false => 'nein',
                default => null,
            },
        ] as $reference => $value) {
            $this->setFormulaCachedValue($document, $xpath, $reference, $value);
        }

        $zip->addFromString($path, $document->saveXML());
    }

    // ---- Blatt Bremszettel -----------------------------------------------

    private function writeBrakeSheet(
        ZipArchive $zip,
        string $path,
        array $choices,
        array $meta,
        array $brakeSheet,
        array $totals,
        array $sheet
    ): void {
        [$document, $xpath] = $this->loadXml($zip, $path);
        $this->clearFormulaResults($xpath);

        $this->setNumber($document, $xpath, 'F12', $this->nullableNumber(Arr::get($brakeSheet, 'tractionWeight')));
        $this->setNumber($document, $xpath, 'F13', $this->nullableNumber(Arr::get($brakeSheet, 'tractionBrakeWeight')));
        $this->setNumber($document, $xpath, 'F14', $this->nullableNumber(Arr::get($brakeSheet, 'tractionAxles')));
        $this->setNumber($document, $xpath, 'F24', $this->nullableNumber(Arr::get($brakeSheet, 'tractionLength')));

        foreach (['E19' => 'singleReleaseBrakes', 'E23' => 'matrossowBrakes'] as $reference => $field) {
            $value = $this->nullableNumber(Arr::get($brakeSheet, $field));
            if ($value !== null) {
                $this->setNumber($document, $xpath, $reference, $value);
            }
        }

        foreach ([
            'E29' => 'nbuepBrake',
            'E30' => 'emergencyBrakeBridge',
            'E31' => 'passengerFeatureHzee',
            'E32' => 'passengerFeatureNOe',
            'E33' => 'passengerFeatureTb0',
            'E34' => 'passengerFeatureOZub',
            'E35' => 'passengerFeatureOther',
        ] as $reference => $field) {
            $this->setString($document, $xpath, $reference, $this->strikeChoice($choices['yesNo'], Arr::get($brakeSheet, $field)));
        }

        // ep-Bremse: Die Vorlage gibt seit v16_6 „nein“ vor; nur eine Angabe
        // aus der App ersetzt die Vorgabe.
        if (in_array(Arr::get($brakeSheet, 'epBrake'), ['yes', 'no'], true)) {
            $this->setString($document, $xpath, 'E37', $this->strikeChoice($choices['yesNo'], Arr::get($brakeSheet, 'epBrake')));
        }

        // Gefahrgut leitet die Vorlage aus UN-Nummer und Gefahrzettel ab;
        // nur eine bewusste Übersteuerung ersetzt die Formel.
        if (in_array(Arr::get($brakeSheet, 'dangerousGoods'), ['yes', 'no'], true)) {
            $this->setString($document, $xpath, 'E36', $this->strikeChoice($choices['yesNo'], Arr::get($brakeSheet, 'dangerousGoods')));
        }

        $this->setString($document, $xpath, 'E38', Arr::get($brakeSheet, 'issuerName'));

        $date = $this->excelDate(Arr::get($meta, 'date'));

        foreach ([
            'E4' => $date,
            'A7' => $this->textOrNumber(Arr::get($meta, 'trainNumber')),
            'E7' => $this->text(Arr::get($meta, 'origin')),
            'E12' => $totals['weightRounded'] > 0 ? $totals['weightRounded'] : null,
            'G12' => $sheet['trainWeight'] > 0 ? $sheet['trainWeight'] : null,
            'E13' => $totals['usableBrakeWeight'],
            'G13' => $sheet['brakeWeight'],
            'E14' => $totals['axles'],
            'G14' => $sheet['axles'],
            'G15' => $sheet['minimumPercentage'] > 0 ? $sheet['minimumPercentage'] : null,
            'G16' => $sheet['availablePercentage'],
            'G17' => $sheet['missingPercentage'],
            'E18' => $sheet['lastVehicle'],
            'E20' => $totals['brakeCount'] > 0 ? $sheet['multiReleaseBrakes'] : null,
            'E21' => $totals['discBrakes'],
            'E22' => $totals['compositeBrakes'],
            'E24' => $totals['lengthRounded'] > 0 ? $totals['lengthRounded'] : null,
            'G24' => $sheet['trainLength'] > 0 ? $sheet['trainLength'] : null,
            'E25' => $totals['wagons'] > 0 ? $totals['brakedAxles'] : null,
            'E26' => $totals['axles'] > 0 ? $totals['requiredBrakedAxles'] : null,
        ] as $reference => $value) {
            $this->setFormulaCachedValue($document, $xpath, $reference, $value);
        }

        $zip->addFromString($path, $document->saveXML());
    }

    // ---- Blatt Meldezettel -----------------------------------------------

    private function writeReportSheet(
        ZipArchive $zip,
        string $path,
        array $choices,
        array $meta,
        array $report,
        array $sheet
    ): void {
        [$document, $xpath] = $this->loadXml($zip, $path);
        $mark = $choices['mark'];
        $this->clearFormulaResults($xpath);

        $this->setString($document, $xpath, 'AF12', Arr::get($meta, 'track'));
        $this->setString($document, $xpath, 'AF14', Arr::get($meta, 'customer'));

        // Angaben zum Zug (Zeilen 17/18, Vordruck VDV). Werte der früheren,
        // längeren Fassung des Blocks werden wie im Browser abgebildet.
        $papers = match ((string) Arr::get($report, 'papers', '')) {
            'complete', 'electronic', 'paper', 'both' => 'complete',
            'none' => 'none',
            default => '',
        };
        $transportOrder = match ((string) Arr::get($report, 'transportOrder', '')) {
            'complete' => 'complete',
            'standingOrNone', 'standing', 'none' => 'standingOrNone',
            default => '',
        };
        $this->markChoice($document, $xpath, $mark, $papers, [
            'complete' => 'C17',
            'none' => 'C18',
        ]);
        $this->markChoice($document, $xpath, $mark, $transportOrder, [
            'complete' => 'U17',
            'standingOrNone' => 'U18',
        ]);

        foreach ([
            'coupling' => 'V32',
            'wagonInspection' => 'V33',
            'dangerousGoodsCheck' => 'V34',
            'fullBrakeTest' => 'V35',
            'tailSignal' => 'V36',
            'securingRemoved' => 'V37',
            'simpleBrakeTest' => 'V38',
        ] as $task => $reference) {
            $this->setString($document, $xpath, $reference, Arr::get($report, 'tasks.'.$task) ? $mark : '');
        }

        $level = (string) Arr::get($report, 'wagonInspectionLevel', '');
        $this->setNumber($document, $xpath, 'P33', in_array($level, ['1', '2', '3', '4'], true) ? (float) $level : null);

        $extraWork = array_values(array_filter(
            array_map(fn (mixed $entry): string => $this->text($entry), (array) Arr::get($report, 'extraWork', [])),
            fn (string $entry): bool => $entry !== ''
        ));
        for ($index = 0; $index < 5; $index++) {
            $row = 42 + $index;
            $entry = $extraWork[$index] ?? '';
            $this->setNumber($document, $xpath, 'C'.$row, $entry === '' ? null : (float) ($index + 1));
            $this->setString($document, $xpath, 'F'.$row, $entry);
        }

        $remarks = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) Arr::get($report, 'remarks', '')) ?: []),
            fn (string $line): bool => $line !== ''
        ));
        if (count($remarks) > 3) {
            $remarks = [$remarks[0], $remarks[1], implode(' · ', array_slice($remarks, 2))];
        }
        foreach (['C49', 'C50', 'C51'] as $index => $reference) {
            $this->setString($document, $xpath, $reference, $remarks[$index] ?? '');
        }

        $date = $this->excelDate(Arr::get($meta, 'date'));

        foreach ([
            'H10' => $this->textOrNumber(Arr::get($meta, 'trainNumber')),
            'T10' => $this->text(Arr::get($meta, 'reference')),
            'AF10' => $date,
            'H12' => $this->text(Arr::get($meta, 'origin')),
            'T12' => $this->text(Arr::get($meta, 'destination')),
            'H14' => $sheet['firstVehicle'],
            'T14' => $sheet['lastVehicle'],
            'C25' => $sheet['firstVehicle'],
            'V25' => $sheet['lastVehicle'],
        ] as $reference => $value) {
            $this->setFormulaCachedValue($document, $xpath, $reference, $value);
        }

        $zip->addFromString($path, $document->saveXML());
    }

    private function markChoice(DOMDocument $document, DOMXPath $xpath, string $mark, mixed $value, array $cells): void
    {
        foreach ($cells as $option => $reference) {
            $this->setString($document, $xpath, $reference, (string) $value === (string) $option ? $mark : '');
        }
    }

    // ---- Rechenregeln (wie resources/js/wagon-list-rules.js) --------------

    private function totals(array $wagons, array $brakeSheet): array
    {
        $active = array_values(array_filter($wagons, fn (array $wagon): bool => $this->isWagonFilled($wagon)));

        $sum = fn (string $field): float => array_reduce(
            $active,
            fn (float $total, array $wagon): float => $total + $this->number(Arr::get($wagon, $field)),
            0.0
        );
        $brakeWeight = fn (array $wagon): float => $this->number(Arr::get($wagon, 'brakeG')) + $this->number(Arr::get($wagon, 'brakeP'));
        $braked = array_values(array_filter($active, fn (array $wagon): bool => $brakeWeight($wagon) > 0));

        $length = $sum('length');
        $axles = $sum('axles');
        $brakeG = $sum('brakeG');
        $brakeP = $sum('brakeP');
        $totalWeight = array_reduce(
            $active,
            fn (float $total, array $wagon): float => $total + ($this->wagonWeightOrNull($wagon) ?? 0.0),
            0.0
        );

        $position = in_array(Arr::get($brakeSheet, 'brakePosition'), ['R/P', 'G'], true) ? (string) $brakeSheet['brakePosition'] : '';
        $both = count(array_filter(
            $active,
            fn (array $wagon): bool => $this->number(Arr::get($wagon, 'brakeG')) > 0 && $this->number(Arr::get($wagon, 'brakeP')) > 0
        ));

        $valid = $length > 0
            && $length <= self::MAX_CALCULATION_LENGTH
            && $both === 0
            && ($position !== 'G' || $brakeP <= 0);

        $pRate = $valid && $position !== 'G'
            ? ($length > 700 ? 0.19 : ($length > 600 ? 0.10 : ($length > 500 ? 0.05 : 0.0)))
            : 0.0;
        $gBaseRate = $valid && $position !== 'G' ? 0.25 : 0.0;
        $gLengthRate = $valid && $length > 700 ? 0.05 : 0.0;

        $deductionG = $brakeG * $gBaseRate;
        $deductionP = $brakeP * $pRate;
        // Einstellung der Vorlage: Zusatzabzug für G über 700 m additiv (70 %).
        $deductionGLength = $brakeG * $gLengthRate;

        $speeds = array_values(array_filter(
            array_map(fn (array $wagon): float => $this->number(Arr::get($wagon, 'maxSpeed')), $active),
            fn (float $speed): bool => $speed > 0
        ));

        return [
            'wagons' => count($active),
            'axles' => $axles,
            'length' => $length,
            'lengthRounded' => $length > 0 ? $this->roundUp($length) : 0,
            'wagonWeight' => $sum('wagonWeight'),
            'loadWeight' => $sum('loadWeight'),
            'totalWeight' => $totalWeight,
            'weightRounded' => $totalWeight > 0 ? $this->roundUp($totalWeight) : 0,
            'brakeG' => $brakeG,
            'brakeP' => $brakeP,
            'brakePosition' => $position,
            'calculationValid' => $valid,
            'deductionG' => $deductionG,
            'deductionP19' => abs($pRate - 0.19) < 1e-9 ? $deductionP : 0.0,
            'deductionP10' => abs($pRate - 0.10) < 1e-9 ? $deductionP : 0.0,
            'deductionP5' => abs($pRate - 0.05) < 1e-9 ? $deductionP : 0.0,
            'deductionGLength' => $deductionGLength,
            'usableBrakeWeight' => $valid
                ? max(0, $this->roundDown($brakeG - $deductionG - $deductionGLength + $brakeP - $deductionP))
                : null,
            'brakeCount' => count($braked),
            'compositeBrakes' => count(array_filter(
                $braked,
                fn (array $wagon): bool => in_array(Arr::get($wagon, 'brakeType'), self::COMPOSITE_SOLES, true)
            )),
            'discBrakes' => count(array_filter($braked, fn (array $wagon): bool => Arr::get($wagon, 'brakeType') === 'D')),
            'brakedAxles' => array_reduce(
                $braked,
                fn (float $total, array $wagon): float => $total + $this->number(Arr::get($wagon, 'axles')),
                0.0
            ),
            'requiredBrakedAxles' => $axles > 0 ? $this->roundUp(($axles * 9) / 10) : 0,
            'minWagonSpeed' => $speeds === [] ? 0.0 : min($speeds),
            'dangerousGoodsWagons' => count(array_filter(
                $active,
                fn (array $wagon): bool => $this->text(Arr::get($wagon, 'unNumber')) !== '' || $this->text(Arr::get($wagon, 'hazardLabel')) !== ''
            )),
        ];
    }

    private function brakeSheetTotals(array $totals, array $wagons, array $brakeSheet): array
    {
        $tractionWeight = $this->number(Arr::get($brakeSheet, 'tractionWeight'));
        $tractionBrakeWeight = $this->number(Arr::get($brakeSheet, 'tractionBrakeWeight'));
        $tractionKnown = $this->nullableNumber(Arr::get($brakeSheet, 'tractionWeight')) !== null
            && $tractionWeight > 0
            && $this->nullableNumber(Arr::get($brakeSheet, 'tractionBrakeWeight')) !== null;

        $trainWeight = $totals['weightRounded'] + $tractionWeight;
        $brakeWeight = $totals['usableBrakeWeight'] === null ? null : $totals['usableBrakeWeight'] + $tractionBrakeWeight;
        $minimum = $this->number(Arr::get($brakeSheet, 'minimumBrakePercentage'));

        $available = $tractionKnown && $trainWeight > 0 && $brakeWeight !== null
            ? $this->roundDown(($brakeWeight * 100) / $trainWeight)
            : null;

        $rule = (string) Arr::get($brakeSheet, 'ninetyPercentRule', 'no');
        $ninetyApplies = $rule === 'yes' || ($rule === 'yes800' && $totals['weightRounded'] > 0 && $totals['weightRounded'] <= 800);
        $ninetySatisfied = $ninetyApplies && $totals['axles'] > 0 && $totals['brakedAxles'] >= $totals['requiredBrakedAxles'];

        $numbered = array_values(array_filter($wagons, fn (array $wagon): bool => $this->hasWagonNumber($wagon)));
        if (Arr::get($brakeSheet, 'listOrder') === 'tail') {
            $numbered = array_reverse($numbered);
        }

        return [
            'trainWeight' => $trainWeight,
            'brakeWeight' => $brakeWeight,
            'axles' => $totals['axles'] + $this->number(Arr::get($brakeSheet, 'tractionAxles')),
            'trainLength' => $totals['lengthRounded'] + $this->number(Arr::get($brakeSheet, 'tractionLength')),
            'minimumPercentage' => $minimum,
            'availablePercentage' => $available,
            'missingPercentage' => $minimum > 0 && $available !== null && ! $ninetySatisfied
                ? max(0, $minimum - $available)
                : null,
            'multiReleaseBrakes' => max(0, $totals['brakeCount'] - $this->number(Arr::get($brakeSheet, 'singleReleaseBrakes'))),
            'dangerousGoodsAuto' => $totals['wagons'] === 0 ? null : $totals['dangerousGoodsWagons'] > 0,
            'firstVehicle' => $numbered === [] ? '' : $this->vehicleLabel($numbered[0]),
            'lastVehicle' => $numbered === [] ? '' : $this->vehicleLabel($numbered[count($numbered) - 1]),
        ];
    }

    /** Wagen aus früheren Entwürfen (zwei Achsspalten, Scheibenbremse als Kästchen) auf die v16_5 bringen. */
    private function normalizeWagon(array $wagon): array
    {
        if ($this->text(Arr::get($wagon, 'axles')) === ''
            && ($this->text(Arr::get($wagon, 'axlesEmpty')) !== '' || $this->text(Arr::get($wagon, 'axlesLoaded')) !== '')) {
            $axles = $this->number(Arr::get($wagon, 'axlesEmpty')) + $this->number(Arr::get($wagon, 'axlesLoaded'));
            $wagon['axles'] = $axles > 0 ? $axles : '';
        }

        $sole = strtoupper($this->text(Arr::get($wagon, 'brakeType')));
        $wagon['brakeType'] = in_array($sole, self::SOLE_TYPES, true) ? $sole : '';
        if (Arr::get($wagon, 'discBrake') === true) {
            $wagon['brakeType'] = 'D';
        }

        return $wagon;
    }

    private function isWagonFilled(array $wagon): bool
    {
        foreach ([
            'number12', 'number34', 'number58', 'number911', 'checkDigit', 'category', 'axles', 'length',
            'wagonWeight', 'loadWeight', 'brakeG', 'brakeP', 'shippingStation', 'destinationStation',
            'brakeType', 'parkingBrake', 'maxSpeed', 'unNumber', 'hazardLabel', 'remark',
        ] as $field) {
            if ($this->text(Arr::get($wagon, $field)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasWagonNumber(array $wagon): bool
    {
        foreach (['number12', 'number34', 'number58', 'number911', 'checkDigit'] as $field) {
            if ($this->text(Arr::get($wagon, $field)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function wagonWeightOrNull(array $wagon): ?float
    {
        if ($this->nullableNumber(Arr::get($wagon, 'wagonWeight')) === null
            && $this->nullableNumber(Arr::get($wagon, 'loadWeight')) === null) {
            return null;
        }

        return $this->number(Arr::get($wagon, 'wagonWeight')) + $this->number(Arr::get($wagon, 'loadWeight'));
    }

    /** Anzeige wie in der Vorlage: „12 34 5678 901-2“ oder der Hinweis auf eine unvollständige Nummer. */
    private function vehicleLabel(array $wagon): string
    {
        $parts = [];
        foreach (['number12' => 2, 'number34' => 2, 'number58' => 4, 'number911' => 3, 'checkDigit' => 1] as $field => $length) {
            $digits = preg_replace('/\D+/', '', (string) Arr::get($wagon, $field, ''));
            if ($digits === '' || strlen($digits) > $length) {
                return 'Nummer unvollständig / ungültig';
            }
            $parts[] = str_pad($digits, $length, '0', STR_PAD_LEFT);
        }

        $label = $parts[0].' '.$parts[1].' '.$parts[2].' '.$parts[3].'-'.$parts[4];

        return $this->expectedCheckDigit($parts[0].$parts[1].$parts[2].$parts[3]) === (int) $parts[4]
            ? $label
            : $label.' (PZ falsch)';
    }

    private function expectedCheckDigit(string $elevenDigits): int
    {
        $sum = 0;
        foreach (str_split($elevenDigits) as $index => $digit) {
            $product = (int) $digit * ($index % 2 === 0 ? 2 : 1);
            $sum += intdiv($product, 10) + ($product % 10);
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function roundUp(float $value): int
    {
        return (int) ceil($value - 1e-9);
    }

    private function roundDown(float $value): int
    {
        return (int) floor($value + 1e-9);
    }

    // ---- Vorlage lesen und schreiben -------------------------------------

    /** @return array<string, string> Blattname => Pfad im Paket */
    private function sheetPaths(ZipArchive $zip): array
    {
        [, $workbook] = $this->loadXml($zip, 'xl/workbook.xml');
        $workbook->registerNamespace('r', self::REL_NS);

        $relations = new DOMDocument('1.0', 'UTF-8');
        $xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($xml === false || ! $relations->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Die Blattzuordnung der Wagenlisten-Vorlage fehlt.');
        }
        $relationPath = new DOMXPath($relations);
        $relationPath->registerNamespace('p', self::PACKAGE_REL_NS);

        $paths = [];
        foreach ($workbook->query('/x:workbook/x:sheets/x:sheet') as $sheet) {
            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $id = $sheet->getAttributeNS(self::REL_NS, 'id');
            $relation = $relationPath->query('/p:Relationships/p:Relationship[@Id="'.$id.'"]')->item(0);
            if ($relation instanceof DOMElement) {
                $paths[$sheet->getAttribute('name')] = 'xl/'.ltrim($relation->getAttribute('Target'), '/');
            }
        }

        foreach (['Wagenliste', 'Bremszettel', 'Meldezettel'] as $name) {
            if (! isset($paths[$name])) {
                throw new RuntimeException("Das Blatt {$name} fehlt in der Wagenlisten-Vorlage.");
            }
        }

        return $paths;
    }

    /**
     * Auswahltexte der Vorlage. Sie stehen im Hilfsbereich der Wagenliste und
     * werden von dort gelesen, damit Export und Gültigkeitslisten der Datei
     * zeichengenau übereinstimmen (durchgestrichenes „ja“/„nein“).
     *
     * @return array{yesNo: array<int, string>, ninety: array<int, string>, order: array<int, string>, mark: string}
     */
    private function templateChoices(ZipArchive $zip, DOMXPath $wagonSheet): array
    {
        $read = fn (string $reference): string => $this->cellText($zip, $wagonSheet, $reference);

        $choices = [
            'yesNo' => [$read('AA251'), $read('AA252'), $read('AA253')],
            'ninety' => [$read('BM251'), $read('BM252'), $read('BM253')],
            'order' => [$read('AE251'), $read('AE252')],
            'mark' => $read('Z251'),
        ];

        foreach ([...$choices['yesNo'], ...$choices['ninety'], ...$choices['order'], $choices['mark']] as $text) {
            if ($text === '') {
                throw new RuntimeException('Die Auswahllisten der Wagenlisten-Vorlage sind unvollständig.');
            }
        }

        return $choices;
    }

    /** „nein / ja“ mit durchgestrichener nicht zutreffender Antwort; leer bleibt die neutrale Vorgabe. */
    private function strikeChoice(array $yesNo, mixed $value): string
    {
        return match ((string) $value) {
            'no' => $yesNo[1],
            'yes' => $yesNo[2],
            default => $yesNo[0],
        };
    }

    private function cellText(ZipArchive $zip, DOMXPath $xpath, string $reference): string
    {
        $cell = $xpath->query('//x:c[@r="'.$reference.'"]')->item(0);
        if (! $cell instanceof DOMElement) {
            return '';
        }

        if ($cell->getAttribute('t') === 'inlineStr') {
            return trim((string) $xpath->evaluate('string(./x:is)', $cell));
        }

        $value = $xpath->query('./x:v', $cell)->item(0)?->textContent ?? '';
        if ($cell->getAttribute('t') !== 's') {
            return trim($value);
        }

        return trim($this->sharedStrings($zip)[(int) $value] ?? '');
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        [, $xpath] = $this->loadXml($zip, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xpath->query('/x:sst/x:si') as $item) {
            $text = '';
            foreach ($xpath->query('./x:t | ./x:r/x:t', $item) as $node) {
                $text .= $node->textContent;
            }
            $strings[] = $text;
        }

        return $this->sharedStrings = $strings;
    }

    private function loadXml(ZipArchive $zip, string $path): array
    {
        $xml = $zip->getFromName($path);
        if ($xml === false) {
            throw new RuntimeException("Die Excel-Datei enthält {$path} nicht.");
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Die Excel-Struktur {$path} ist ungültig.");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::MAIN_NS);

        return [$document, $xpath];
    }

    /**
     * Zwischenstände aller Formeln entfernen. Sie stammen aus der leeren
     * Vorlage und wären nach dem Füllen falsch; Excel rechnet beim Öffnen neu.
     */
    private function clearFormulaResults(DOMXPath $xpath): void
    {
        foreach (iterator_to_array($xpath->query('//x:sheetData/x:row/x:c[x:f]')) as $cell) {
            if (! $cell instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($cell->childNodes) as $child) {
                if ($child->localName === 'v') {
                    $cell->removeChild($child);
                }
            }

            $cell->removeAttribute('t');
        }
    }

    private function setString(DOMDocument $document, DOMXPath $xpath, string $reference, mixed $value): void
    {
        $cell = $this->cell($document, $xpath, $reference);
        $this->releaseSharedFormula($xpath, $cell);
        $this->clearCell($cell);

        $text = $this->text($value);
        if ($text === '') {
            $cell->removeAttribute('t');

            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $inline = $document->createElementNS(self::MAIN_NS, 'is');
        $textNode = $document->createElementNS(self::MAIN_NS, 't');
        $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textNode->appendChild($document->createTextNode($text));
        $inline->appendChild($textNode);
        $cell->appendChild($inline);
    }

    private function setNumber(DOMDocument $document, DOMXPath $xpath, string $reference, ?float $value): void
    {
        $cell = $this->cell($document, $xpath, $reference);
        $this->releaseSharedFormula($xpath, $cell);
        $this->clearCell($cell);
        $cell->removeAttribute('t');

        if ($value === null) {
            return;
        }

        $node = $document->createElementNS(self::MAIN_NS, 'v');
        $node->appendChild($document->createTextNode($this->plainNumber($value)));
        $cell->appendChild($node);
    }

    /** Zugnummern sind meist Zahlen, dürfen aber Buchstaben enthalten. */
    private function setTextOrNumber(DOMDocument $document, DOMXPath $xpath, string $reference, mixed $value): void
    {
        $resolved = $this->textOrNumber($value);

        if (is_float($resolved)) {
            $this->setNumber($document, $xpath, $reference, $resolved);

            return;
        }

        $this->setString($document, $xpath, $reference, $resolved);
    }

    private function textOrNumber(mixed $value): float|string
    {
        $text = $this->text($value);

        return preg_match('/\A[1-9]\d{0,14}\z/', $text) === 1 ? (float) $text : $text;
    }

    private function setFormulaCachedValue(
        DOMDocument $document,
        DOMXPath $xpath,
        string $reference,
        float|int|string|null $value
    ): void {
        $cell = $this->cell($document, $xpath, $reference);

        foreach (iterator_to_array($cell->childNodes) as $child) {
            if ($child->localName !== 'f') {
                $cell->removeChild($child);
            }
        }

        if ($value === null || $value === '') {
            $cell->removeAttribute('t');

            return;
        }

        if (is_string($value)) {
            $cell->setAttribute('t', 'str');
            $text = $value;
        } else {
            $cell->removeAttribute('t');
            $text = $this->plainNumber((float) $value);
        }

        $node = $document->createElementNS(self::MAIN_NS, 'v');
        $node->appendChild($document->createTextNode($text));
        $cell->appendChild($node);
    }

    private function cell(DOMDocument $document, DOMXPath $xpath, string $reference): DOMElement
    {
        $this->cells ??= new \WeakMap;
        if (! isset($this->cells[$document])) {
            $index = [];
            foreach ($xpath->query('//x:sheetData/x:row/x:c') as $existing) {
                if ($existing instanceof DOMElement) {
                    $index[$existing->getAttribute('r')] = $existing;
                }
            }
            $this->cells[$document] = $index;
        }

        $cell = $this->cells[$document][$reference] ?? null;
        if ($cell instanceof DOMElement) {
            return $cell;
        }

        if (preg_match('/\A([A-Z]{1,3})(\d{1,7})\z/', $reference, $match) !== 1) {
            throw new RuntimeException("Ungültiger Zellbezug {$reference}.");
        }

        $rowNumber = (int) $match[2];
        $row = $xpath->query('//x:sheetData/x:row[@r="'.$rowNumber.'"]')->item(0);
        if (! $row instanceof DOMElement) {
            throw new RuntimeException("Die Zeile {$rowNumber} fehlt in der Wagenlisten-Vorlage.");
        }

        // Zelle an der richtigen Spaltenposition einfügen und das Format der
        // linken Nachbarzelle übernehmen (zusammengeführte Eingabefelder).
        $cell = $document->createElementNS(self::MAIN_NS, 'c');
        $cell->setAttribute('r', $reference);
        $target = $this->columnIndex($match[1]);
        $before = null;
        $style = null;

        foreach ($xpath->query('./x:c', $row) as $existing) {
            if (! $existing instanceof DOMElement) {
                continue;
            }

            $column = $this->columnIndex((string) preg_replace('/\d+/', '', $existing->getAttribute('r')));
            if ($column > $target) {
                $before = $existing;
                break;
            }

            $style = $existing->getAttribute('s') !== '' ? $existing->getAttribute('s') : $style;
        }

        if ($style !== null) {
            $cell->setAttribute('s', $style);
        }

        $before instanceof DOMElement ? $row->insertBefore($cell, $before) : $row->appendChild($cell);

        $index = $this->cells[$document];
        $index[$reference] = $cell;
        $this->cells[$document] = $index;

        return $cell;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function clearCell(DOMElement $cell): void
    {
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
    }

    /**
     * Excel speichert gleiche Formeln einer Spalte als gemeinsame Formel: Nur
     * die erste Zelle (z. B. H7 für H7:H46, Gattung) trägt den Formeltext,
     * die übrigen verweisen auf sie. Überschreibt der Export diese erste Zelle
     * mit einem Wert, fehlt den übrigen die Formel und Excel meldet die Datei
     * als beschädigt. Deshalb bekommen vorher alle abhängigen Zellen ihre
     * eigene, auf ihre Position verschobene Formel.
     */
    private function releaseSharedFormula(DOMXPath $xpath, DOMElement $cell): void
    {
        $formula = $xpath->query('./x:f[@t="shared"][@ref]', $cell)->item(0);
        if (! $formula instanceof DOMElement || $formula->textContent === '') {
            return;
        }

        $group = $formula->getAttribute('si');
        [$masterColumn, $masterRow] = $this->splitReference($cell->getAttribute('r'));

        foreach (iterator_to_array($xpath->query('//x:sheetData/x:row/x:c/x:f[@t="shared"][@si="'.$group.'"]')) as $dependent) {
            if (! $dependent instanceof DOMElement || $dependent === $formula) {
                continue;
            }

            $owner = $dependent->parentNode;
            if (! $owner instanceof DOMElement) {
                continue;
            }

            [$column, $row] = $this->splitReference($owner->getAttribute('r'));
            $dependent->removeAttribute('t');
            $dependent->removeAttribute('si');
            $dependent->removeAttribute('ref');
            $dependent->textContent = $this->shiftFormula(
                $formula->textContent,
                $row - $masterRow,
                $column - $masterColumn
            );
        }
    }

    /** @return array{0: int, 1: int} Spalte und Zeile eines Zellbezugs */
    private function splitReference(string $reference): array
    {
        preg_match('/\A([A-Z]{1,3})(\d{1,7})\z/', $reference, $match);

        return [$this->columnIndex($match[1] ?? 'A'), (int) ($match[2] ?? 1)];
    }

    /** Relative Bezüge einer Formel verschieben (wie beim Kopieren in Excel); Texte in Anführungszeichen bleiben unberührt. */
    private function shiftFormula(string $formula, int $rows, int $columns): string
    {
        $parts = preg_split('/("(?:[^"]|"")*")/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$formula];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue;
            }

            $parts[$index] = (string) preg_replace_callback(
                '/(?<![A-Za-z0-9_.])(\$?)([A-Z]{1,3})(\$?)(\d{1,7})(?![A-Za-z0-9_(.])/',
                function (array $match) use ($rows, $columns): string {
                    $column = $match[1] === '' ? $this->columnLetters($this->columnIndex($match[2]) + $columns) : $match[2];
                    $row = $match[3] === '' ? (int) $match[4] + $rows : (int) $match[4];

                    return $match[1].$column.$match[3].$row;
                },
                $part
            );
        }

        return implode('', $parts);
    }

    private function columnLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /**
     * Die Berechnungskette der Vorlage verweist auf Formelzellen, die der
     * Export mit festen Werten überschreibt (Gattung, Nummernvorbelegung).
     * Excel würde die Datei sonst als beschädigt melden; ohne Kette baut es
     * sie beim Öffnen neu auf.
     */
    private function removeCalculationChain(ZipArchive $zip): void
    {
        if ($zip->locateName('xl/calcChain.xml') !== false) {
            $zip->deleteName('xl/calcChain.xml');
        }

        foreach ([
            '[Content_Types].xml' => '//*[local-name()="Override"][contains(@PartName, "calcChain")]',
            'xl/_rels/workbook.xml.rels' => '//*[local-name()="Relationship"][contains(@Target, "calcChain")]',
        ] as $path => $query) {
            $xml = $zip->getFromName($path);
            if ($xml === false) {
                continue;
            }

            $document = new DOMDocument('1.0', 'UTF-8');
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                continue;
            }

            $changed = false;
            foreach (iterator_to_array((new DOMXPath($document))->query($query)) as $node) {
                $node->parentNode?->removeChild($node);
                $changed = true;
            }

            if ($changed) {
                $zip->addFromString($path, $document->saveXML());
            }
        }
    }

    private function enableFullCalculation(ZipArchive $zip): void
    {
        [$document, $xpath] = $this->loadXml($zip, 'xl/workbook.xml');
        $calculation = $xpath->query('/x:workbook/x:calcPr')->item(0);

        if (! $calculation instanceof DOMElement) {
            $calculation = $document->createElementNS(self::MAIN_NS, 'calcPr');
            $document->documentElement->appendChild($calculation);
        }

        $calculation->setAttribute('calcMode', 'auto');
        $calculation->setAttribute('fullCalcOnLoad', '1');
        $calculation->setAttribute('forceFullCalc', '1');
        $calculation->setAttribute('calcId', '0');

        $zip->addFromString('xl/workbook.xml', $document->saveXML());
    }

    // ---- Werte -------------------------------------------------------------

    private function text(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        // Steuerzeichen sind in XML 1.0 unzulässig und würden die Datei unlesbar machen.
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($value ?? '')));
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || is_array($value) || trim((string) $value) === '') {
            return null;
        }

        return $this->number($value);
    }

    private function number(mixed $value): float
    {
        if (is_array($value) || is_object($value)) {
            return 0.0;
        }

        $normalized = str_replace(',', '.', trim((string) ($value ?? '')));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function plainNumber(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private function excelDate(mixed $date): ?float
    {
        $calendarDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            substr((string) $date, 0, 10),
            new \DateTimeZone('UTC')
        );

        if (! $calendarDate) {
            return null;
        }

        return ($calendarDate->getTimestamp() / 86400) + 25569;
    }

    /** „HH:MM“ als Bruchteil eines Tages (Zellformat h:mm). */
    private function excelTime(mixed $time): ?float
    {
        if (preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim((string) $time), $match) !== 1) {
            return null;
        }

        return (((int) $match[1]) * 60 + (int) $match[2]) / 1440;
    }
}

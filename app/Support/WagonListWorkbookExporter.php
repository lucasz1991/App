<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Arr;
use RuntimeException;
use ZipArchive;

class WagonListWorkbookExporter
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function export(array $payload): string
    {
        $template = resource_path('templates/RT-WAGENLISTE.xlsx');

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

        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            @unlink($target);
            throw new RuntimeException('Die Wagenlisten-Vorlage konnte nicht geöffnet werden.');
        }

        try {
            $wagons = array_values(array_slice((array) ($payload['wagons'] ?? []), 0, 40));
            $totals = $this->totals($wagons);

            $this->writeWagonSheet($zip, (array) ($payload['meta'] ?? []), $wagons, $totals);
            $this->writeBrakeSheet($zip, (array) ($payload['brakeSheet'] ?? []), $totals);
            $this->enableFullCalculation($zip);
        } finally {
            $zip->close();
        }

        return $target;
    }

    private function writeWagonSheet(ZipArchive $zip, array $meta, array $wagons, array $totals): void
    {
        [$document, $xpath] = $this->loadXml($zip, 'xl/worksheets/sheet1.xml');

        $this->setString($document, $xpath, 'C2', Arr::get($meta, 'trainNumber'));
        $this->setNumber($document, $xpath, 'K2', $this->excelDate(Arr::get($meta, 'date')));
        $this->setString($document, $xpath, 'C3', Arr::get($meta, 'origin'));
        $this->setString($document, $xpath, 'K3', Arr::get($meta, 'destination'));

        $reference = trim((string) Arr::get($meta, 'reference', ''));
        $this->setString($document, $xpath, 'P2', trim('Referenz: '.$reference));

        for ($index = 0; $index < 40; $index++) {
            $row = $index + 7;
            $wagon = (array) ($wagons[$index] ?? []);

            foreach ([
                'B' => 'number12',
                'C' => 'number34',
                'D' => 'number58',
                'E' => 'number911',
                'F' => 'checkDigit',
                'G' => 'category',
                'P' => 'shippingStation',
                'Q' => 'destinationStation',
                'R' => 'brakeType',
                'V' => 'remark',
            ] as $column => $field) {
                $this->setString($document, $xpath, $column.$row, Arr::get($wagon, $field));
            }

            foreach ([
                'H' => 'axlesEmpty',
                'I' => 'axlesLoaded',
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

            $this->setString($document, $xpath, 'S'.$row, Arr::get($wagon, 'discBrake') ? 'D' : '');
            $this->setFormulaCachedValue(
                $document,
                $xpath,
                'M'.$row,
                $this->isWagonFilled($wagon)
                    ? $this->number(Arr::get($wagon, 'wagonWeight')) + $this->number(Arr::get($wagon, 'loadWeight'))
                    : null
            );
        }

        foreach ([
            'H48' => $totals['axlesEmpty'],
            'I48' => $totals['axlesLoaded'],
            'J48' => $totals['length'],
            'K48' => $totals['wagonWeight'],
            'L48' => $totals['loadWeight'],
            'M48' => $totals['totalWeight'],
            'N48' => $totals['brakeG'],
            'O48' => $totals['brakeP'],
            'I49' => $totals['axles'],
            'N49' => $totals['deductionG'],
            'I50' => $totals['brakeCount'],
            'O50' => $totals['deductionP19'],
            'O51' => $totals['deductionP10'],
            'I52' => $totals['plasticBrakes'],
            'O52' => $totals['deductionP5'],
            'I53' => $totals['length'],
            'I54' => $totals['usableBrakeWeight'],
            'I55' => $totals['totalWeight'],
        ] as $reference => $value) {
            $this->setFormulaCachedValue($document, $xpath, $reference, $value);
        }

        // Die Originalvorlage referenziert diesen Wert im Bremszettel,
        // enthält an dieser Stelle aber keine Formel.
        $this->setNumber($document, $xpath, 'I51', $totals['discBrakes']);

        $zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML());
    }

    private function writeBrakeSheet(ZipArchive $zip, array $brakeSheet, array $totals): void
    {
        [$document, $xpath] = $this->loadXml($zip, 'xl/worksheets/sheet2.xml');

        $tractionWeight = $this->number(Arr::get($brakeSheet, 'tractionWeight'));
        $tractionBrakeWeight = $this->number(Arr::get($brakeSheet, 'tractionBrakeWeight'));
        $tractionAxles = $this->number(Arr::get($brakeSheet, 'tractionAxles'));
        $trainWeight = $totals['totalWeight'] + $tractionWeight;
        $brakeWeight = $totals['usableBrakeWeight'] + $tractionBrakeWeight;
        $axles = $totals['axles'] + $tractionAxles;
        $availablePercentage = $trainWeight > 0 ? round(($brakeWeight * 100) / $trainWeight) : 0;
        $minimumPercentage = $this->nullableNumber(Arr::get($brakeSheet, 'minimumBrakePercentage'));
        $missingPercentage = max(0, ($minimumPercentage ?? 0) - $availablePercentage);

        $this->setNumber($document, $xpath, 'F12', $this->nullableNumber(Arr::get($brakeSheet, 'tractionWeight')));
        $this->setNumber($document, $xpath, 'F13', $this->nullableNumber(Arr::get($brakeSheet, 'tractionBrakeWeight')));
        $this->setNumber($document, $xpath, 'F14', $this->nullableNumber(Arr::get($brakeSheet, 'tractionAxles')));
        $this->setNumber($document, $xpath, 'G15', $minimumPercentage);
        $this->setNumber($document, $xpath, 'G17', $missingPercentage);
        $this->setNumber($document, $xpath, 'E25', $this->nullableNumber(Arr::get($brakeSheet, 'brakedAxles')));

        $lowerVehicleSpeed = $this->nullableNumber(Arr::get($brakeSheet, 'lowerVehicleSpeed'));
        $this->setString($document, $xpath, 'E27', $lowerVehicleSpeed === null ? 'nein' : 'ja');
        $this->setString(
            $document,
            $xpath,
            'E28',
            $lowerVehicleSpeed === null ? '' : $this->plainNumber($lowerVehicleSpeed).' km/h'
        );

        foreach ([
            'E29' => 'nbuepBrake',
            'E30' => 'emergencyBrakeBridge',
            'E31' => 'passengerFeatureHzee',
            'E32' => 'passengerFeatureNOe',
            'E33' => 'passengerFeatureTb0',
            'E34' => 'passengerFeatureOZub',
            'E35' => 'passengerFeatureOther',
            'E36' => 'dangerousGoods',
            'E37' => 'epBrake',
        ] as $reference => $field) {
            $this->setString($document, $xpath, $reference, $this->yesNo(Arr::get($brakeSheet, $field)));
        }

        $issuerName = trim((string) Arr::get($brakeSheet, 'issuerName', ''));
        $this->setString(
            $document,
            $xpath,
            'A38',
            trim('Bremszettel ausgefertigt (Name) '.$issuerName)
        );

        foreach ([
            'E12' => $totals['totalWeight'],
            'G12' => $trainWeight,
            'E13' => $totals['usableBrakeWeight'],
            'G13' => $brakeWeight,
            'E14' => $totals['axles'],
            'G14' => $axles,
            'G16' => $availablePercentage,
            'E18' => $totals['lastVehicle'],
            'E20' => $totals['brakeCount'],
            'E21' => $totals['discBrakes'],
            'E22' => $totals['plasticBrakes'],
            'E24' => $totals['length'],
        ] as $reference => $value) {
            $this->setFormulaCachedValue($document, $xpath, $reference, $value);
        }

        $zip->addFromString('xl/worksheets/sheet2.xml', $document->saveXML());
    }

    private function totals(array $wagons): array
    {
        $activeWagons = array_values(array_filter($wagons, fn (array $wagon): bool => $this->isWagonFilled($wagon)));

        $sum = function (string $field) use ($activeWagons): float {
            return array_reduce(
                $activeWagons,
                fn (float $total, array $wagon): float => $total + $this->number(Arr::get($wagon, $field)),
                0.0
            );
        };

        $length = $sum('length');
        $brakeG = $sum('brakeG');
        $brakeP = $sum('brakeP');
        $deductionG = $brakeG > 0 ? $brakeG * 0.25 : 0;
        $deductionP19 = $length >= 701.1 ? $brakeP * 0.19 : 0;
        $deductionP10 = $length > 601.1 ? $brakeP * 0.10 : 0;
        $deductionP5 = $length > 500 && $length <= 601 ? $brakeP * 0.05 : 0;

        $lastWagon = $activeWagons === [] ? null : $activeWagons[array_key_last($activeWagons)];

        return [
            'axlesEmpty' => $sum('axlesEmpty'),
            'axlesLoaded' => $sum('axlesLoaded'),
            'axles' => $sum('axlesEmpty') + $sum('axlesLoaded'),
            'length' => $length,
            'wagonWeight' => $sum('wagonWeight'),
            'loadWeight' => $sum('loadWeight'),
            'totalWeight' => $sum('wagonWeight') + $sum('loadWeight'),
            'brakeG' => $brakeG,
            'brakeP' => $brakeP,
            'brakeCount' => count(array_filter(
                $activeWagons,
                fn (array $wagon): bool => $this->number(Arr::get($wagon, 'brakeG')) > 0
                    || $this->number(Arr::get($wagon, 'brakeP')) > 0
            )),
            'discBrakes' => count(array_filter(
                $activeWagons,
                fn (array $wagon): bool => (bool) Arr::get($wagon, 'discBrake')
            )),
            'plasticBrakes' => count(array_filter(
                $activeWagons,
                fn (array $wagon): bool => in_array(Arr::get($wagon, 'brakeType'), ['K', 'L', 'LL'], true)
            )),
            'deductionG' => $deductionG,
            'deductionP19' => $deductionP19,
            'deductionP10' => $deductionP10,
            'deductionP5' => $deductionP5,
            'usableBrakeWeight' => max(0, $brakeG + $brakeP - $deductionG - $deductionP19 - $deductionP10 - $deductionP5),
            'lastVehicle' => $lastWagon ? $this->wagonNumber($lastWagon) : '',
        ];
    }

    private function loadXml(ZipArchive $zip, string $path): array
    {
        $xml = $zip->getFromName($path);
        if ($xml === false) {
            throw new RuntimeException("Die Excel-Datei enthält {$path} nicht.");
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException("Die Excel-Struktur {$path} ist ungültig.");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::MAIN_NS);

        return [$document, $xpath];
    }

    private function setString(DOMDocument $document, DOMXPath $xpath, string $reference, mixed $value): void
    {
        $cell = $this->cell($xpath, $reference);
        $this->clearCell($cell);

        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            $cell->removeAttribute('t');
            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $inline = $document->createElementNS(self::MAIN_NS, 'x:is');
        $textNode = $document->createElementNS(self::MAIN_NS, 'x:t');
        $textNode->appendChild($document->createTextNode($text));
        $inline->appendChild($textNode);
        $cell->appendChild($inline);
    }

    private function setNumber(DOMDocument $document, DOMXPath $xpath, string $reference, ?float $value): void
    {
        $cell = $this->cell($xpath, $reference);
        $this->clearCell($cell);
        $cell->removeAttribute('t');

        if ($value === null) {
            return;
        }

        $node = $document->createElementNS(self::MAIN_NS, 'x:v');
        $node->appendChild($document->createTextNode($this->plainNumber($value)));
        $cell->appendChild($node);
    }

    private function setFormulaCachedValue(
        DOMDocument $document,
        DOMXPath $xpath,
        string $reference,
        float|int|string|null $value
    ): void {
        $cell = $this->cell($xpath, $reference);

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

        $node = $document->createElementNS(self::MAIN_NS, 'x:v');
        $node->appendChild($document->createTextNode($text));
        $cell->appendChild($node);
    }

    private function cell(DOMXPath $xpath, string $reference): DOMElement
    {
        $cell = $xpath->query('//x:c[@r="'.$reference.'"]')->item(0);

        if (! $cell instanceof DOMElement) {
            throw new RuntimeException("Die Zelle {$reference} fehlt in der Wagenlisten-Vorlage.");
        }

        return $cell;
    }

    private function clearCell(DOMElement $cell): void
    {
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
    }

    private function enableFullCalculation(ZipArchive $zip): void
    {
        [$document, $xpath] = $this->loadXml($zip, 'xl/workbook.xml');
        $calculation = $xpath->query('/x:workbook/x:calcPr')->item(0);

        if (! $calculation instanceof DOMElement) {
            $calculation = $document->createElementNS(self::MAIN_NS, 'x:calcPr');
            $document->documentElement->appendChild($calculation);
        }

        $calculation->setAttribute('calcMode', 'auto');
        $calculation->setAttribute('fullCalcOnLoad', '1');
        $calculation->setAttribute('forceFullCalc', '1');
        $calculation->setAttribute('calcId', '0');

        $zip->addFromString('xl/workbook.xml', $document->saveXML());
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->number($value);
    }

    private function number(mixed $value): float
    {
        $normalized = str_replace(',', '.', trim((string) ($value ?? '')));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function plainNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }

    private function excelDate(mixed $date): float
    {
        $calendarDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            substr((string) $date, 0, 10),
            new \DateTimeZone('UTC')
        );

        if (! $calendarDate) {
            $calendarDate = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        }

        return ($calendarDate->getTimestamp() / 86400) + 25569;
    }

    private function yesNo(mixed $value): string
    {
        return match ((string) $value) {
            'yes' => 'ja',
            'no' => 'nein',
            default => '',
        };
    }

    private function isWagonFilled(array $wagon): bool
    {
        return trim(implode('', [
            Arr::get($wagon, 'number12'),
            Arr::get($wagon, 'number34'),
            Arr::get($wagon, 'number58'),
            Arr::get($wagon, 'number911'),
        ])) !== ''
            || trim((string) Arr::get($wagon, 'category', '')) !== ''
            || $this->nullableNumber(Arr::get($wagon, 'wagonWeight')) !== null
            || $this->nullableNumber(Arr::get($wagon, 'loadWeight')) !== null;
    }

    private function wagonNumber(array $wagon): string
    {
        $parts = array_filter([
            trim((string) Arr::get($wagon, 'number12', '')),
            trim((string) Arr::get($wagon, 'number34', '')),
            trim((string) Arr::get($wagon, 'number58', '')),
            trim((string) Arr::get($wagon, 'number911', '')),
        ], fn (string $part): bool => $part !== '');

        $number = implode(' ', $parts);
        $checkDigit = trim((string) Arr::get($wagon, 'checkDigit', ''));

        return $number.($checkDigit !== '' ? '-'.$checkDigit : '');
    }
}

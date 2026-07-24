<?php

use App\Support\WagonListWorkbookExporter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = [
    'meta' => [
        'trainNumber' => 'RT 4711',
        'date' => '2026-07-24',
        'origin' => 'Hamburg',
        'destination' => 'Berlin',
        'reference' => 'QA-EXPORT',
    ],
    'wagons' => [
        [
            'number12' => '31',
            'number34' => '80',
            'number58' => '0691',
            'number911' => '235',
            'checkDigit' => '7',
            'category' => 'Habbiins',
            'axlesEmpty' => 4,
            'axlesLoaded' => 0,
            'length' => 23.6,
            'wagonWeight' => 24.5,
            'loadWeight' => 48.2,
            'brakeG' => 52,
            'brakeP' => 60,
            'shippingStation' => 'Hamburg',
            'destinationStation' => 'Berlin',
            'brakeType' => 'K',
            'discBrake' => true,
            'parkingBrake' => 16,
            'maxSpeed' => 120,
            'remark' => 'Exportprüfung',
        ],
        [
            'number12' => '33',
            'number34' => '80',
            'number58' => '7921',
            'number911' => '114',
            'checkDigit' => '2',
            'category' => 'Sggmrss',
            'axlesEmpty' => 6,
            'axlesLoaded' => 0,
            'length' => 34.2,
            'wagonWeight' => 29.6,
            'loadWeight' => 61.4,
            'brakeG' => 64,
            'brakeP' => 72,
            'shippingStation' => 'Hamburg',
            'destinationStation' => 'Berlin',
            'brakeType' => 'LL',
            'discBrake' => false,
            'parkingBrake' => 20,
            'maxSpeed' => 100,
            'remark' => 'Zweiter Wagen',
        ],
    ],
    'brakeSheet' => [
        'tractionWeight' => 84,
        'tractionBrakeWeight' => 90,
        'tractionAxles' => 4,
        'minimumBrakePercentage' => 72,
        'brakedAxles' => 10,
        'lowerVehicleSpeed' => 80,
        'dangerousGoods' => 'no',
        'epBrake' => 'yes',
        'issuerName' => 'Codex QA',
    ],
];

$generated = $app->make(WagonListWorkbookExporter::class)->export($payload);
$target = __DIR__.'/output/app-export-sample.xlsx';

if (! copy($generated, $target)) {
    throw new RuntimeException('Die Exportdatei konnte nicht in den QA-Ordner kopiert werden.');
}

@unlink($generated);
fwrite(STDOUT, $target.PHP_EOL);

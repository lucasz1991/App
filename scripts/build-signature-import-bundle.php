<?php

declare(strict_types=1);

use App\Support\CompanyData;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\MailSignature;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sourcePath = $argv[1] ?? '';
$targetPath = $argv[2] ?? '';
if ($sourcePath === '' || $targetPath === '') {
    fwrite(STDERR, "Usage: php scripts/build-signature-import-bundle.php <source.json> <target.json>\n");
    exit(2);
}

$source = json_decode((string) file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);
$byId = [];
foreach ((array) ($source['media'] ?? []) as $entry) {
    if (is_array($entry) && is_string($entry['id'] ?? null)) {
        $byId[$entry['id']] = $entry;
    }
}

$requiredMedia = [
    'contact-email.png',
    'contact-location.png',
    'contact-mobile.png',
    'contact-phone.png',
    'contact-web.png',
    'wortmarke-signature-light.gif',
    'wortmarke-signature-light.png',
    'wortmarke-mail-dark.gif',
    'wortmarke-mail-dark.png',
    'zug-dampf-light.gif',
    'zug-dampf-light.png',
    'zug-dampf-dark.gif',
    'zug-dampf-dark.png',
    'zug-dampf-idle-light.gif',
    'zug-dampf-idle-dark.gif',
];

$media = [];
foreach ($requiredMedia as $id) {
    $entry = $byId[$id] ?? null;
    if (! is_array($entry)) {
        throw new RuntimeException("Das Quellbundle enthaelt {$id} nicht.");
    }

    $binary = base64_decode((string) ($entry['data'] ?? ''), true);
    if (! is_string($binary)
        || strlen($binary) !== (int) ($entry['bytes'] ?? 0)
        || ! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $binary))) {
        throw new RuntimeException("Das Medium {$id} ist nicht bytegenau verifiziert.");
    }

    $entry['required'] = true;
    $entry['included'] = true;
    $media[] = $entry;
}

$tokens = [];
foreach (array_keys(MailSignature::forCompany()->values([], CompanyData::defaults())) as $key) {
    $tokens[$key] = '{{'.$key.'}}';
}
$html = trim(view('emails.parts.signature', ['values' => $tokens])->render());
$html = app(EmailHtmlSanitizer::class)->assertClean($html)->html;
SignatureDocumentContract::assertValid($html);

$bundle = [
    'format' => 'railtime-mail-document',
    'version' => 2,
    'kind' => 'signature',
    'html' => $html,
    'css' => '',
    'media' => $media,
];

$json = json_encode(
    $bundle,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
if (file_put_contents($targetPath, $json) !== strlen($json)) {
    throw new RuntimeException('Das Importbundle konnte nicht vollstaendig geschrieben werden.');
}

fwrite(STDOUT, $targetPath."\n".strlen($json)." bytes\n".count($media)." media\n");

<?php

declare(strict_types=1);

$source = 'C:/Users/L. Zacharias/Documents/RailTime/railtime-signatur-v11-kompakte-abstaende.json';
$target = 'C:/Users/L. Zacharias/Documents/RailTime/railtime-signatur-v12-sofort-sichtbar.json';
$assetDirectory = dirname(__DIR__).'/resources/mail-templates/assets';

$bundle = json_decode((string) file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($bundle) || ($bundle['kind'] ?? null) !== 'signature') {
    throw new RuntimeException('V11-Quellbundle ist keine Signatur.');
}

$html = (string) ($bundle['html'] ?? '');
$html = str_replace('data-rt-artifact-version="v11"', 'data-rt-artifact-version="v12"', $html, $versionCount);
if ($versionCount !== 1) {
    throw new RuntimeException('Die V11-Releasekennung ist nicht eindeutig.');
}

// Explizite intrinsische Hoehe reserviert den Wortmarkenplatz schon vor dem
// GIF-Download. CSS height:auto bleibt erhalten und skaliert mobil weiterhin.
$html = preg_replace(
    '/(<img\b[^>]*\bclass="rt-logo"[^>]*\bwidth="200")(?![^>]*\bheight=)/i',
    '$1 height="34"',
    $html,
    -1,
    $logoCount,
) ?? $html;
if ($logoCount !== 2) {
    throw new RuntimeException('Die beiden Wortmarkenbilder wurden nicht eindeutig gefunden.');
}
$bundle['html'] = $html;

$mapped = 0;
foreach ($bundle['media'] as &$medium) {
    if (! is_array($medium) || ! str_starts_with((string) ($medium['id'] ?? ''), 'zug-dampf-v8-')) {
        continue;
    }

    $id = str_replace('zug-dampf-v8-', 'zug-dampf-v12-', (string) $medium['id']);
    $path = $assetDirectory.'/'.$id;
    $binary = file_get_contents($path);
    if ($binary === false) {
        throw new RuntimeException("V12-Medium fehlt: {$path}");
    }

    $medium['id'] = $id;
    $medium['name'] = $id;
    $medium['source'] = '/mail-assets/'.$id;
    $medium['mime_type'] = str_ends_with($id, '.gif') ? 'image/gif' : 'image/png';
    $medium['bytes'] = strlen($binary);
    $medium['sha256'] = hash('sha256', $binary);
    $medium['data'] = base64_encode($binary);
    $mapped++;
}
unset($medium);

if ($mapped !== 4) {
    throw new RuntimeException("Erwartet wurden vier V12-Zugmedien, ersetzt wurden {$mapped}.");
}

$encoded = json_encode(
    $bundle,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
)."\n";
file_put_contents($target, $encoded);

printf(
    "%s\n%d Bytes\n%s\n",
    $target,
    strlen($encoded),
    hash('sha256', $encoded),
);

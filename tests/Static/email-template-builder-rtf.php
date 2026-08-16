<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/app/Support/EmailTemplateBuilder.php';

$reflection = new ReflectionClass(App\Support\EmailTemplateBuilder::class);
$builder = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('buildOutlookRtf');
$method->setAccessible(true);

$rtf = $method->invoke($builder, "BMP é · Astral 😀 𝄞");
$expected = [
    '\\u233?',       // é
    '\\u183?',       // ·
    '\\u-10179?',    // U+1F600 high surrogate D83D
    '\\u-8704?',     // U+1F600 low surrogate DE00
    '\\u-10188?',    // U+1D11E high surrogate D834
    '\\u-8930?',     // U+1D11E low surrogate DD1E
];

foreach ($expected as $escape) {
    if (! str_contains($rtf, $escape)) {
        fwrite(STDERR, "Missing RTF escape: {$escape}\n");
        exit(1);
    }
}

if (str_contains($rtf, '\\u62976?') || str_contains($rtf, '\\u53534?')) {
    fwrite(STDERR, "Found invalid non-BMP codepoint escape.\n");
    exit(1);
}

fwrite(STDOUT, "RTF Unicode surrogate test passed.\n");

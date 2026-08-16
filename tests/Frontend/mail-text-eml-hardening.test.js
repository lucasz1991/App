import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const builder = readFileSync('app/Support/EmailTemplateBuilder.php', 'utf8');
const textLayout = readFileSync('resources/views/vendor/mail/text/layout.blade.php', 'utf8');

test('downloaded EML projects Outlook-critical images into CID MIME parts', () => {
    assert.match(builder, /cidOutlookImages:\s*true/);
    assert.match(builder, /'LOGO_STILL_SRC'\s*=>\s*'cid:railtime-logo-still'/);
    assert.match(builder, /'outlookTrainSrc'\s*=>\s*'cid:railtime-train'/);
    assert.match(builder, /'TRAIN_SRC'\s*=>\s*''/);

    for (const contentId of [
        'railtime-logo',
        'railtime-logo-still',
        'railtime-mark',
        'railtime-mark-still',
        'railtime-train',
        'railtime-signature-grid',
        'railtime-signature-watermark',
    ]) {
        assert.match(builder, new RegExp(`'${contentId}'\\s*=>\\s*\\[`));
    }
});

test('plain Laravel mail layout appends the canonical company signature', () => {
    assert.match(textLayout, /EmailTemplateBuilder::buildSystemMailTextSignature\(\)/);
    assert.match(builder, /public static function buildSystemMailTextSignature\(\): string/);
    assert.match(builder, /CompanyData::templateValues\(\)/);
    assert.match(builder, /self::telHref\(\$companyPhone\) === self::telHref\(\$emergencyPhone\)/);
    assert.match(builder, /Geschäftsführung:/);
    assert.match(builder, /Registergericht:/);
    assert.match(builder, /USt-IdNr\.:/);
    assert.match(builder, /Diese E-Mail kann vertrauliche Informationen enthalten\./);
});

test('RTF encoder emits signed UTF-16 surrogate pairs for non-BMP codepoints', () => {
    assert.match(builder, /\$supplementary = \$codepoint - 0x10000/);
    assert.match(builder, /0xD800 \+ \(\$supplementary >> 10\)/);
    assert.match(builder, /0xDC00 \+ \(\$supplementary & 0x3FF\)/);
    assert.match(builder, /\$codeUnit > 0x7FFF \? \$codeUnit - 0x10000 : \$codeUnit/);
});

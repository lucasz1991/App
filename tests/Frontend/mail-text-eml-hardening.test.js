import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const builder = readFileSync('app/Support/EmailTemplateBuilder.php', 'utf8');
const textLayout = readFileSync('resources/views/vendor/mail/text/layout.blade.php', 'utf8');
const emailMaster = readFileSync('resources/mail-templates/email-master.html', 'utf8');
const templateContract = readFileSync('app/Support/Mail/TemplateDocumentContract.php', 'utf8');

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
    assert.match(builder, /private static function formatPlainSignature\(array \$values\): string/);
    assert.match(builder, /\$directHref !== '' && \$directHref === \$mobileHref/);
    assert.match(builder, /! in_array\(\$companyHref, array_filter\(\[\$directHref, \$mobileHref\]\), true\)/);
    assert.match(builder, /\$signature = rtrim\(\$this->buildPlainSignature\(\)\)/);
    assert.match(builder, /W \{\$website\}/);
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

test('Classic Outlook receives a visible RT mark when GIF animation is disabled', () => {
    assert.equal((emailMaster.match(/\{\{ICON_RT_SRC\}\}/g) || []).length, 1);
    assert.equal((emailMaster.match(/\{\{ICON_RT_STILL_SRC\}\}/g) || []).length, 1);
    assert.match(emailMaster, /src="\{\{ICON_RT_SRC\}\}"[^>]*mso-hide:all/);
    assert.match(emailMaster, /<!--\[if mso\]><img class="rt-mark" src="\{\{ICON_RT_STILL_SRC\}\}"/);
    assert.match(emailMaster, /<!-- RT_TEMPLATE_MARK_START -->[\s\S]+?<!-- RT_TEMPLATE_MARK_END -->/);
    assert.match(templateContract, /private static function assertCanonicalMarkFragment\(string \$html\): void/);
    assert.match(templateContract, /substr_count\(\$html, '\{\{ICON_RT_STILL_SRC\}\}'\) !== 1/);
    assert.match(templateContract, /hash_equals\(\$masterFragment, \$documentFragment\)/);
});

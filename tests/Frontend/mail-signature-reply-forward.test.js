import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = async (relativePath) => readFile(new URL(relativePath, import.meta.url), 'utf8');
const occurrences = (value, pattern) => (value.match(pattern) || []).length;

test('reply and forward markup has one company contact DOM and one responsive wordmark', async () => {
    const [signature, contacts] = await Promise.all([
        source('../../resources/views/emails/parts/signature.blade.php'),
        source('../../resources/views/emails/parts/company-contact-table.blade.php'),
    ]);

    assert.equal(
        occurrences(signature, /@include\('emails\.parts\.company-contact-table'/g),
        1,
        'the company contact partial must be rendered exactly once',
    );
    assert.doesNotMatch(signature, /rt-firma-(?:breit|schmal)/);

    assert.equal(
        occurrences(contacts, /<table class="rt-contact rt-company-contact"/g),
        1,
        'all company rows must share one presentation table',
    );
    assert.doesNotMatch(contacts, /\$gruppen|@foreach\(\$gruppen/);
    assert.equal(occurrences(contacts, /RT_\{\{ \$zeile\['marker'\] \}\}_START/g), 1);
    assert.equal(occurrences(contacts, /RT_\{\{ \$zeile\['marker'\] \}\}_END/g), 1);
    for (const marker of ['COMPANY_PHONE', 'COMPANY_EMAIL', 'WEBSITE']) {
        assert.match(contacts, new RegExp(`'marker' => '${marker}'`));
    }

    const iconCell = contacts.indexOf('class="rt-contact-icon rt-company-contact-icon"');
    const textCell = contacts.indexOf('class="rt-contact-text rt-company-contact-text"');
    assert.ok(iconCell >= 0 && textCell > iconCell, 'icon-left/text-right order must not depend on CSS reordering');
    assert.match(signature, /<div align="right" style="text-align:right;">\s*@include\('emails\.parts\.company-contact-table'/);
    assert.match(contacts, /margin-left:\{\{ \$rechtsPositioniert \? 'auto' : '0' \}\}/);
    assert.doesNotMatch(contacts, /<table[^>]*\salign=/);
    assert.match(contacts, /align="left" class="rt-contact-text rt-company-contact-text"/);

    assert.doesNotMatch(signature, /rt-marke-mobil|class="rt-only-narrow/);
    assert.equal(
        occurrences(signature, /<img class="rt-logo" src="\{\{ \$values\['LOGO_SRC'\] \}\}"/g),
        1,
        'the animated wordmark must have one responsive DOM instance',
    );
    assert.equal(
        occurrences(signature, /<!--\[if mso\]><img class="rt-logo"/g),
        1,
        'Classic Outlook keeps one conditional still-image fallback',
    );
});

test('mobile rules restyle the same signature nodes without hide-and-show copies', async () => {
    const css = await source('../../resources/views/emails/parts/responsive-css.blade.php');
    const mobile = css.slice(css.indexOf('@media only screen and (max-width: 860px)'));

    assert.match(mobile, /tr\.rt-stack > td\s*\{[\s\S]*?display:\s*block !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-sign-logo\s*\{[\s\S]*?border-left:\s*0 !important;[\s\S]*?text-align:\s*left !important;/);
    assert.match(mobile, /img\.rt-logo\s*\{[\s\S]*?margin-left:\s*0 !important;/);
    assert.match(mobile, /\.rt-company-contact\s*\{[\s\S]*?float:\s*none !important;[\s\S]*?display:\s*table !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-company-contact td\.rt-company-contact-text\s*\{\s*text-align:\s*left !important;/);
    assert.doesNotMatch(css, /\.rt-firma-(?:breit|schmal)/);
    assert.doesNotMatch(css, /rt-marke-mobil|\.rt-sign-logo img\.rt-logo\s*\{[^}]*display:\s*none/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[\s\S]*?left:\s*0 !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="train"[^}]*left:\s*0 !important;[^}]*width:\s*100% !important;/);
    for (const crop of ['left', 'center', 'right']) {
        assert.match(mobile, new RegExp(`data-rt-layer-mobile="${crop}"[^}]*width:\\s*200% !important`));
    }
});

test('each mail client sees one train image through the modern stage or the single MSO fallback', async () => {
    const [signature, runtime, carrier, preview] = await Promise.all([
        source('../../resources/views/emails/parts/signature.blade.php'),
        source('../../app/Support/MailSignature.php'),
        source('../../app/Support/Mail/SignatureTrainCarrier.php'),
        source('../../app/Services/PageBuilder/PageBuilderPreviewService.php'),
    ]);

    assert.match(signature, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signature, /<div class="rt-sign-stage" style="position:relative;overflow:hidden;">/);
    assert.match(signature, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;[^"\r\n]*mso-hide:all;/);
    assert.match(signature, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}" width="720"[^>]*style="[^"]*position:absolute;[^\"]*display:block;width:100%;max-width:1815px;height:auto;[^\"]*mso-hide:all;/);
    assert.doesNotMatch(signature, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.doesNotMatch(signature, /data-rt-outlook-train/);
    assert.doesNotMatch(signature, /<td[^>]+background="\{\{[^}]*TRAIN/);

    assert.doesNotMatch(runtime, /data-rt-train-main-(?:image|layer)/);
    assert.doesNotMatch(runtime, /projectPublishedTrainAsRuntimeImage/);
    assert.match(runtime, /usesTokenizedTrainCarrier/);
    assert.match(runtime, /SignatureTrainCarrier::projectAsImage\(/);
    assert.equal(occurrences(runtime, /SignatureTrainCarrier::withMsoFallback\(/g), 1);
    assert.match(runtime, /outlookTrainFallbackSrc[\s\S]*?TRAIN_STILL_SRC/);
    assert.doesNotMatch(runtime, /appendClassicOutlookTrainFallback/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.match(carrier, /public static function withMsoFallback/);
    assert.match(carrier, /public static function withIdleOverlay/);
    assert.equal(occurrences(carrier, /<!--\[if mso\]><tr><td class="rt-sign-train-mso"/g), 1);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;overflow:hidden;">/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"/);
    assert.match(carrier, /<img src="'\.\$escapedSource\.'" width="720"/);
    assert.match(preview, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(runtime, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(carrier, /data-rt-train-idle-overlay/);
    assert.match(carrier, /data-rt-train-idle-image/);
    assert.doesNotMatch(runtime, /rt-classic-outlook-train/);
});

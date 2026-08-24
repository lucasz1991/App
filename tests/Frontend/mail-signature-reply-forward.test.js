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
    const logo = signature.indexOf('class="rt-sign-logo"');
    const identity = signature.indexOf('class="rt-sign-identity"');
    const company = signature.indexOf('class="rt-sign-company"');
    assert.ok(logo >= 0 && identity > logo && company > identity, 'mobile source order is logo, person, company');
    assert.match(signature, /<table class="rt-sign-layout" role="presentation" dir="rtl"/);
    assert.match(signature, /class="rt-sign-identity" dir="ltr" rowspan="2"/);
});

test('mobile rules restyle the same signature nodes without hide-and-show copies', async () => {
    const css = await source('../../resources/views/emails/parts/responsive-css.blade.php');
    const mobile = css.slice(css.indexOf('@media only screen and (max-width: 860px)'));

    assert.match(mobile, /tr\.rt-stack > td\s*\{[\s\S]*?display:\s*block !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-sign-logo\s*\{[\s\S]*?padding:\s*0 0 14px !important;[\s\S]*?border-bottom:\s*1px solid \{\{ \$border \}\} !important;[\s\S]*?text-align:\s*left !important;/);
    assert.match(mobile, /\.rt-sign-identity\s*\{\s*padding:\s*14px 0 0 !important;/);
    assert.match(mobile, /\.rt-sign-company\s*\{[\s\S]*?padding:\s*12px 0 0 !important;[\s\S]*?border-left:\s*0 !important;/);
    assert.match(mobile, /img\.rt-logo\s*\{[\s\S]*?margin-left:\s*0 !important;/);
    assert.match(mobile, /\.rt-company-contact\s*\{[\s\S]*?float:\s*none !important;[\s\S]*?display:\s*table !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-company-contact td\.rt-company-contact-text\s*\{\s*text-align:\s*left !important;/);
    assert.doesNotMatch(css, /\.rt-firma-(?:breit|schmal)/);
    assert.doesNotMatch(css, /rt-marke-mobil|\.rt-sign-logo img\.rt-logo\s*\{[^}]*display:\s*none/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[^}]*width:\s*100% !important;[^}]*max-width:\s*1815px !important;/s);
    assert.doesNotMatch(css, /\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0 !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*0 !important;/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*-25% !important;/);
    const phone = css.slice(css.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-content-frame,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v9"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-40% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="right"\]\[data-rt-layer-size\][\s\S]*?width:\s*200% !important;\s*max-width:\s*none !important;\s*margin-left:\s*-100% !important;/);
});

test('delivery keeps the fixed train pixel frame and its MSO fallback inside the bottom slot', async () => {
    const [signature, runtime, carrier, preview] = await Promise.all([
        source('../../resources/views/emails/parts/signature.blade.php'),
        source('../../app/Support/MailSignature.php'),
        source('../../app/Support/Mail/SignatureTrainCarrier.php'),
        source('../../app/Services/PageBuilder/PageBuilderPreviewService.php'),
    ]);
    const msoFallback = carrier.slice(
        carrier.indexOf('public static function withMsoFallback'),
        carrier.indexOf('public static function withIdleOverlay'),
    );

    assert.match(signature, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signature, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(signature, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="center" data-rt-layer-size="125" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;[^"\r\n]*overflow:hidden;[^"\r\n]*text-align:left;">/);
    assert.match(signature, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"/);
    assert.match(signature, /<td class="rt-sign-train-slot" height="200" valign="bottom"/);
    assert.match(signature, /<table class="rt-sign-content-frame" role="presentation" width="100%" height="200"/);
    assert.match(signature, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:125%;max-width:none;height:auto;margin:0 0 0 -12\.5%;[^\"]*vertical-align:bottom;[^\"]*mso-hide:all;">/);
    assert.doesNotMatch(signature, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.doesNotMatch(signature, /data-rt-outlook-train/);
    assert.doesNotMatch(signature, /<td[^>]+background="\{\{[^}]*TRAIN/);

    assert.doesNotMatch(runtime, /data-rt-train-main-(?:image|layer)/);
    assert.doesNotMatch(runtime, /projectPublishedTrainAsRuntimeImage/);
    assert.match(runtime, /usesTokenizedTrainCarrier/);
    assert.match(runtime, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(runtime, /SignatureTrainCarrier::projectAsRuntimeBackground\(/);
    assert.equal(occurrences(runtime, /SignatureTrainCarrier::withMsoFallback\(/g), 1);
    assert.match(runtime, /outlookTrainFallbackSrc[\s\S]*?TRAIN_STILL_SRC/);
    assert.doesNotMatch(runtime, /appendClassicOutlookTrainFallback/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.doesNotMatch(carrier, /public static function projectAsRuntimeBackground/);
    assert.match(carrier, /public static function withMsoFallback/);
    assert.match(carrier, /public static function withIdleOverlay/);
    assert.equal(occurrences(carrier, /<!--\[if mso\]><tr><td class="rt-sign-train-mso"/g), 0);
    assert.match(carrier, /<!--\[if mso\]><img class="rt-sign-train-mso"/);
    assert.match(msoFallback, /<img class="rt-sign-train-mso"[^>]*display:inline-block;[^>]*vertical-align:bottom;/);
    assert.match(msoFallback, /self::assertRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train[^>]*style="display:block;[^"\r\n]*height:200px;max-height:200px;[^"\r\n]*margin-bottom:-200px;/);
    assert.match(msoFallback, /substr_replace\(\$html, \$fallback, \$slots\[0\]\['endOffset'\] \+ 1, 0\)/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"[^>]*style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;/);
    assert.match(preview, /MailSignature::forCompany\(/);
    assert.match(preview, /->renderDocument\(/);
    assert.doesNotMatch(preview, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(runtime, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(carrier, /data-rt-train-idle-overlay/);
    assert.match(carrier, /<span class="rt-train-idle-overlay" data-rt-train-idle-overlay[\s\S]*?<img class="rt-train-idle-image" data-rt-train-idle-image src="'\.\$escapedSource\.'"[\s\S]*?<\/span>/);
    assert.match(carrier, /data-rt-train-idle-overlay[^>]*position:absolute;[^>]*display:block;[^>]*height:0;max-height:0;[^>]*overflow:hidden;/);
    assert.match(carrier, /data-rt-train-idle-overlay[^>]*top:auto;bottom:0;[^>]*max-width:none;[^>]*text-align:left/);
    assert.match(carrier, /rt-train-idle-image[^>]*position:absolute;[^>]*bottom:0;[^>]*display:inline-block;[^>]*vertical-align:bottom;/);
    assert.doesNotMatch(carrier, /background-image:[^;]*(?:TRAIN|train|\.gif)/);
    assert.doesNotMatch(runtime, /rt-classic-outlook-train/);
});

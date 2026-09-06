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
    assert.match(signature, /<table class="rt-sign-layout" role="presentation" width="100%"[^>]*style="width:100%;table-layout:fixed;/);
    assert.match(signature, /class="rt-sign-logo" colspan="2" width="100%"/);
    assert.match(signature, /<tr class="rt-stack rt-sign-top-row">[\s\S]*?class="rt-sign-identity"[\s\S]*?class="rt-sign-company"/);
    assert.doesNotMatch(signature, /<table class="rt-sign-layout"[^>]*\bdir="rtl"/);
    assert.doesNotMatch(signature, /\browspan=/);
    assert.doesNotMatch(signature, /rt-sign-company-row/);
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
    assert.doesNotMatch(css, /^\s*\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/ms);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*0 !important;/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*-25% !important;/);
    const phone = css.slice(css.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-content-frame,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v10"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-40% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v10"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*270px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v13"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*190px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*175px !important;/s);
    assert.match(css, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*145px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso\s*\{[^}]*width:\s*94% !important;[^}]*margin-left:\s*0 !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*max-height:\s*none !important;[^}]*min-height:\s*175px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\s*\{[^}]*position:\s*relative !important;[^}]*z-index:\s*0 !important;[^}]*height:\s*175px !important;[^}]*margin-bottom:\s*-175px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-content-frame\s*\{[^}]*position:\s*relative !important;[^}]*z-index:\s*1 !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*296px !important;/s);
    assert.match(mobile, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*215px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*296px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*215px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*264px !important;/s);
    assert.match(phone, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*190px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*264px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*190px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*108\.67% !important;[^}]*margin-left:\s*0 !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*135% !important;[^}]*margin-left:\s*-15\.75% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*175% !important;[^}]*margin-left:\s*-40% !important;/s);
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
    assert.match(runtime, /SignatureArtifactVersion::usesOptimizedMailAssets\(\$artifactVersion\)/);
    assert.match(runtime, /\$values\['TRAIN_IDLE_SRC'\] = '';/);
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
    assert.match(msoFallback, /\$fallbackStyle = \(\$aspectSafeTrain \|\| \$forwardSafeTrain\)[\s\S]*?display:inline-block;[\s\S]*?vertical-align:bottom;/);
    assert.match(msoFallback, /self::assertRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(carrier, /\$layerPosition = \$failOpenStage \? 'position:relative;z-index:0;' : '';/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train[^>]*style="'\.\$layerPosition\.'display:block;[^"\r\n]*height:200px;max-height:200px;[^"\r\n]*margin-bottom:-200px;/);
    assert.match(carrier, /\$imageHeight = \(\$forwardSafeTrain \|\| \(\$failOpenStage && ! \$aspectSafeTrain\)\) \? ' height="61"' : '';/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"'\.\$imageHeight\.' alt=""/);
    assert.match(carrier, /canonicalStageStartMarkup\(bool \$failOpenStage\)[\s\S]*?height:auto;min-height:200px;overflow:visible;/);
    assert.match(carrier, /canonicalContentFrameStartMarkup\(bool \$failOpenStage = false\)[\s\S]*?position:relative;z-index:1;/);
    assert.match(msoFallback, /substr_replace\(\$html, \$fallback, \$slots\[0\]\['endOffset'\] \+ 1, 0\)/);
    assert.match(msoFallback, /\$fallbackHeight = self::usesFailOpenStage\(\$html\) \? ' height="61"' : '';/);
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

test('outlook taskpane normalizes multiple templates and keeps the single-template fallback', async () => {
    const previousOffice = globalThis.Office;
    globalThis.Office = { onReady() {} };

    try {
        const taskpane = await import('../../resources/js/outlook-addin/taskpane.js');
        const templates = taskpane.normalizeTemplateChoices({
            templates: [
                {
                    id: 'slot-standard',
                    key: 'slot-standard',
                    name: 'Standardvorlage',
                    label: 'Standardvorlage',
                    active: true,
                    html: '<p>Standard</p>',
                    media: [],
                    version: '0123456789abcdef',
                    hash: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                },
                {
                    id: 'slot-offer',
                    key: 'slot-offer',
                    name: 'Angebot',
                    label: 'Angebot',
                    active: false,
                    isDefault: true,
                    html: '<p>Angebot</p>',
                    media: [],
                    version: 'fedcba9876543210',
                    hash: 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
                },
            ],
            template: { html: '<p>Legacy</p>', media: [] },
            version: {
                personal: '1111111111111111',
                signature: '2222222222222222',
                template: '3333333333333333',
            },
        });

        assert.deepEqual(
            templates.map(({ id, name, active, version }) => ({ id, name, active, version })),
            [
                {
                    id: 'slot-standard',
                    name: 'Standardvorlage',
                    active: true,
                    version: '0123456789abcdef',
                },
                {
                    id: 'slot-offer',
                    name: 'Angebot',
                    active: false,
                    version: 'fedcba9876543210',
                },
            ],
        );
        assert.equal(taskpane.hasCurrentSnapshot({
            version: { personal: '1111111111111111', signature: '2222222222222222' },
        }), true);
        assert.equal(taskpane.hasCurrentSnapshot({
            version: { signature: '2222222222222222' },
        }), false);
        assert.equal(templates[0].isDefault, false);
        assert.equal(templates[1].isDefault, true, 'Outlook default is independent of system-active');

        const fallback = taskpane.normalizeTemplateChoices({
            template: { html: '<p>Legacy</p>', media: [] },
            version: { template: '3333333333333333' },
        });
        assert.equal(fallback.length, 1);
        assert.equal(fallback[0].id, 'active-template');
        assert.equal(fallback[0].name, 'Standardvorlage');
        assert.equal(fallback[0].active, true);
        assert.equal(fallback[0].version, '3333333333333333');
        assert.deepEqual(
            taskpane.signatureVersionsFromBody(
                '<!-- RT-SIGNATURE-VERSION:2222222222222222 -->'
                + '<span>RT-SIGNATURE-VERSION:ABCDEF0123456789</span>',
            ),
            ['2222222222222222', 'abcdef0123456789'],
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Neu</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
                'newMail',
            ),
            '<p>Neu</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Antwort</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->'
                + '<div id="divRplyFwdMsg">Von:</div>'
                + '<!-- RT-SIGNATURE-VERSION:aaaaaaaaaaaaaaaa -->',
                'reply',
            ),
            '<p>Antwort</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Antwort ohne eindeutigen Trenner</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
                'reply',
            ),
            null,
        );
    } finally {
        if (previousOffice === undefined) {
            delete globalThis.Office;
        } else {
            globalThis.Office = previousOffice;
        }
    }
});

test('outlook taskpane keeps templates visible and hides resolved maintenance actions accessibly', async () => {
    const [view, taskpane] = await Promise.all([
        source('../../resources/views/outlook-addin/taskpane.blade.php'),
        source('../../resources/js/outlook-addin/taskpane.js'),
    ]);

    assert.match(view, /\[hidden\]\s*\{\s*display:\s*none !important;/);
    assert.match(view, /<label class="field-label" for="outlook-template-select">Vorlage auswählen<\/label>/);
    assert.match(view, /<select[\s\S]*?id="outlook-template-select"[\s\S]*?data-outlook-template-select/);
    assert.match(view, /data-outlook-template-name/);
    assert.match(view, /data-outlook-template-version/);
    assert.match(view, /data-outlook-maintenance-actions/);
    assert.match(view, /aria-live="polite"/);
    assert.match(view, /:focus-visible/);
    assert.match(view, /@media \(max-width: 340px\)/);

    assert.match(taskpane, /view\.login\.hidden = authenticatedBootstrap;/);
    assert.match(taskpane, /view\.signature\.hidden = authenticatedBootstrap\s*&& taskpaneState\.itemChangedMonitoringReady\s*&& taskpaneState\.signatureCurrent;/);
    assert.match(taskpane, /view\.maintenanceActions\.hidden = view\.login\.hidden && view\.signature\.hidden;/);
    assert.match(taskpane, /item\.body\.getAsync\(Office\.CoercionType\.Html/);
    assert.match(taskpane, /item\.getComposeTypeAsync/);
    assert.match(taskpane, /RT-SIGNATURE-VERSION:\(\[0-9a-f\]\{16\}\)/);
    assert.match(taskpane, /const currentBodyHtml = currentComposeBodyHtml\(bodyHtml, composeType\);[\s\S]*?return signatureVersionsFromBody\(currentBodyHtml\)\.includes\(expectedVersion\);/);
    assert.match(taskpane, /currentBootstrap = payload;[\s\S]*?await refreshSignatureCurrentState\(\);/);
    assert.match(taskpane, /window\.addEventListener\('focus', requestSilentBootstrapRefresh\);/);
    assert.match(taskpane, /document\.addEventListener\('visibilitychange',/);
    assert.match(taskpane, /Date\.now\(\) - lastBootstrapRefreshAt < SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS/);
    assert.match(taskpane, /failOpenSignatureCurrentState\(\);\s*silentBootstrapRefreshPromise = \(async \(\) =>/);
    assert.match(taskpane, /const bootstrap = await loadBootstrap\(currentConfig, accessToken\);[\s\S]*?await acceptBootstrap\(bootstrap\);/);
    assert.match(taskpane, /const expectedRevision = bootstrapStateRevision;[\s\S]*?expectedRevision !== bootstrapStateRevision[\s\S]*?await acceptBootstrap\(bootstrap\);[\s\S]*?catch \(error\) \{\s*if \(expectedRevision !== bootstrapStateRevision \|\| taskpaneState\.busy\) \{\s*return;/);
    assert.match(taskpane, /if \(!lastActionError && !insertionBlocked\(\)\) setStatus\('success', 'Mit RailTime verbunden'/);
    assert.match(taskpane, /authenticationWasLost\(error\)[\s\S]*?invalidateBootstrap\('locked', true\);[\s\S]*?failOpenSignatureCurrentState\(\);/);
    assert.match(taskpane, /Office\.EventType\?\.ItemChanged/);
    assert.match(taskpane, /signatureStateRefreshPromise = refreshSignatureCurrentState\(\)\.catch\(\(error\) =>/);
    assert.match(taskpane, /function handleMailboxItemChanged\(\) \{[\s\S]*?invalidateBootstrap\('locked'\);/);
    assert.match(taskpane, /Office\.EventType\.ItemChanged,\s*handleMailboxItemChanged,\s*\(result\) =>/);
    assert.match(taskpane, /mailboxItemRevision \+= 1;[\s\S]*?taskpaneState\.templatePresent = false;\s*invalidateBootstrap\('locked'\);/);
    assert.match(taskpane, /const target = captureComposeTarget\(\);[\s\S]*?await callback\(bootstrap, target\);/);
    assert.match(taskpane, /function assertComposeTarget\(target\) \{[\s\S]*?target\.revision !== mailboxItemRevision[\s\S]*?Office\.context\.mailbox\.item !== target\.item[\s\S]*?throw codedError\('ITEM_CHANGED'\);/);
    assert.match(taskpane, /await attachInlineMedia\(target, signature\.media, bootstrap\.binding\);\s*await assertWriteTarget\(target, bootstrap\.binding\);\s*await setSignature\(item, signature\.html\);/);
    assert.match(taskpane, /prependTemplate\(Office, item, template\.html, \(\) => assertWriteTarget\(target, bootstrap\.binding\), \{\s*media: template\.media,\s*beforeInsert: \(\) => attachInlineMedia\(target, template\.media, bootstrap\.binding\)/);
    assert.match(taskpane, /taskpaneState\.itemChangedMonitoringReady = result\?\.status === Office\.AsyncResultStatus\.Succeeded;[\s\S]*?if \(!taskpaneState\.itemChangedMonitoringReady\) \{[\s\S]*?failOpenSignatureCurrentState\(\);/);
    assert.match(taskpane, /\} else \{\s*taskpaneState\.itemChangedMonitoringReady = false;\s*failOpenSignatureCurrentState\(\);/);
    assert.doesNotMatch(taskpane, /localStorage/);
    assert.match(taskpane, /validatedDocument\(templateChoice\.document, 'template'/);
    assert.doesNotMatch(taskpane, /body\.setAsync|removeStaleManagedInlineMedia|removeAttachmentAsync|displayNewMessageForm/);
    assert.doesNotMatch(taskpane, /allowAdditional: true/);
    assert.match(taskpane, /confirmAdditional: confirmAdditionalTemplate/);
    assert.doesNotMatch(taskpane, /window\.confirm/);
    assert.match(taskpane, /async function withAuthenticatedBootstrap\(button, callback\) \{\s*if \(taskpaneState\.busy\) return;/);
    assert.match(taskpane, /acceptBootstrap\(bootstrap, \{ inspectBody: false \}\)/);
    assert.equal(occurrences(taskpane, /await refreshSignatureCurrentState\(\);/g), 2);
    assert.doesNotMatch(taskpane, /taskpaneState\.signatureCurrent = await signatureIsCurrent/);
});

const composeLibrary = {
    ...await import('../../resources/js/outlook-addin/compose-template.js'),
    ...await import('../../resources/js/outlook-addin/mailbox-guard.js'),
    ...await import('../../resources/js/outlook-addin/diagnostics.js'),
    ...await import('../../resources/js/outlook-addin/office-write.js'),
};
const testMailboxBinding = {
    schema: 1, mailboxAddress: 'employee@example.test', senderAddress: 'employee@example.test',
    allowedSenderAddresses: ['employee@example.test'],
};

test('opt-in symmetric signature header changes only its mobile table groups, not source display permissions', async () => {
    const css = await source('../../resources/views/emails/parts/responsive-css.blade.php');
    const sanitizer = await source('../../app/Support/Mail/EmailHtmlSanitizer.php');
    assert.match(css, /tr\[data-rt-artifact-version="v23"\] \.rt-sign-heading-logo\s*\{\s*display:\s*table-header-group !important;/);
    assert.match(css, /tr\[data-rt-artifact-version="v23"\] \.rt-sign-heading-person\s*\{\s*display:\s*table-row-group !important;/);
    for (const name of ['rt-sign-heading-table', 'rt-sign-heading-person', 'rt-sign-heading-logo']) {
        assert.ok(sanitizer.includes(`'${name}'`));
    }
    assert.doesNotMatch(sanitizer, /['"]table-header-group['"]|['"]table-row-group['"]/);
});

function composeFixture({ html = '<p>Existing user text</p>', composeType = 'newMail', platform = 'PC' } = {}) {
    const state = { html, prepends: [], signatures: [], attachments: [], completed: 0, bodyReads: 0 };
    const succeeded = (value) => ({ status: 'succeeded', value });
    const item = {
        from: { getAsync(callback) { callback(succeeded({ emailAddress: 'employee@example.test' })); } },
        body: {
            getAsync(_format, callback) { state.bodyReads += 1; callback(succeeded(state.html)); },
            getTypeAsync(callback) { callback(succeeded('html')); },
            prependAsync(value, _options, callback) {
                state.prepends.push(value);
                state.html = value + state.html;
                callback(succeeded());
            },
            setSignatureAsync(value, _options, callback) {
                state.signatures.push(value);
                callback(succeeded());
            },
            setAsync() { throw new Error('Full body replacement must never run'); },
        },
        getComposeTypeAsync(callback) { callback(succeeded({ composeType })); },
        getAttachmentsAsync(callback) { callback(succeeded(state.attachments)); },
        addFileAttachmentFromBase64Async(_base64, name, options, callback) {
            state.attachments.push({ name, isInline: options.isInline });
            callback(succeeded(name));
        },
    };
    const office = {
        AsyncResultStatus: { Succeeded: 'succeeded' },
        CoercionType: { Html: 'html' },
        context: {
            platform,
            requirements: { isSetSupported() { return true; } },
            mailbox: { item, userProfile: { emailAddress: 'employee@example.test' } },
        },
        onReady() {},
        actions: { associate() {} },
    };
    return { state, item, office, event: { completed() { state.completed += 1; } } };
}

test('explicit Outlook default does not activate a legacy system template implicitly', () => {
    const entry = { id: 'offer', isDefault: true, html: '<p>Offer</p>' };
    assert.equal(composeLibrary.automaticTemplate({ template: entry, templates: [entry] }), null);
    assert.equal(composeLibrary.automaticTemplate({ automaticTemplateId: 'offer', templates: [entry] }), entry);
    assert.equal(composeLibrary.automaticTemplate({ automaticTemplateId: 'offer', templates: [entry, entry] }), null);
    assert.equal(composeLibrary.automaticTemplate({ automaticTemplateId: 'offer', templates: [{ ...entry, isDefault: false }] }), null);
});

test('prepend preserves reply content and quoted templates and blocks repeated automatic insertion', async () => {
    const quoted = `<p>My reply</p><div id="divRplyFwdMsg">Original ${composeLibrary.TEMPLATE_MARKER}</div>`;
    const { office, item, state } = composeFixture({ html: quoted, composeType: 'reply' });
    assert.equal((await composeLibrary.readTemplateState(office, item)).present, false);
    await composeLibrary.prependTemplate(office, item, '<table><tr><td>Template</td></tr></table>');
    assert.ok(state.html.endsWith(quoted), 'original user text and quoted HTML stay byte-for-byte intact');
    assert.equal(state.prepends.length, 1);
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Duplicate</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(state.prepends.length, 1);
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Explicit extra</p>', () => {}, { allowAdditional: true }), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(state.prepends.length, 1);
    assert.ok(state.html.endsWith(quoted));
});

test('template insertion refuses mobile, unreadable bodies, plain text and changed items before writing', async () => {
    for (const platform of ['iOS', 'Android']) {
        const { office, item, state } = composeFixture({ platform });
        await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Template</p>'), { code: 'TEMPLATE_PREPEND_UNAVAILABLE' });
        assert.equal(state.prepends.length, 0);
    }
    const unreadable = composeFixture();
    unreadable.item.body.getAsync = (_format, callback) => callback({ status: 'failed' });
    await assert.rejects(composeLibrary.prependTemplate(unreadable.office, unreadable.item, '<p>Template</p>'), { code: 'COMPOSE_BODY_UNREADABLE' });
    const plain = composeFixture();
    plain.item.body.getTypeAsync = (callback) => callback({ status: 'succeeded', value: 'text' });
    await assert.rejects(composeLibrary.prependTemplate(plain.office, plain.item, '<p>Template</p>'), { code: 'TEMPLATE_REQUIRES_HTML' });
    const changed = composeFixture();
    await assert.rejects(composeLibrary.prependTemplate(changed.office, changed.item, '<p>Template</p>', () => {
        throw Object.assign(new Error('Changed'), { code: 'ITEM_CHANGED' });
    }), { code: 'ITEM_CHANGED' });
    assert.equal(changed.state.prepends.length, 0);
});

test('template budgets run before media writes and accepted insertion reads the conversation only once', async () => {
    const limit = composeLibrary.TEMPLATE_INSERT_LIMITS;
    const cases = [
        { html: 'x'.repeat(limit.htmlLength), options: {}, code: 'TEMPLATE_TOO_LARGE' },
        { html: '<img>'.repeat(limit.images + 1), options: {}, code: 'TEMPLATE_MEDIA_TOO_LARGE' },
        { html: '<p>Template</p>', options: { media: [{ base64: Buffer.alloc(limit.mediaBytes + 1).toString('base64') }] }, code: 'TEMPLATE_MEDIA_TOO_LARGE' },
    ];
    for (const scenario of cases) {
        const fixture = composeFixture();
        let attachments = 0;
        await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, scenario.html, () => {}, {
            ...scenario.options, beforeInsert() { attachments += 1; },
        }), { code: scenario.code });
        assert.equal(fixture.state.bodyReads, 0);
        assert.equal(attachments, 0);
        assert.equal(fixture.state.prepends.length, 0);
    }
    const longBody = composeFixture({ html: 'x'.repeat(limit.bodyLength) });
    let attachments = 0;
    await assert.rejects(composeLibrary.prependTemplate(longBody.office, longBody.item, '<p>Template</p>', () => {}, {
        beforeInsert() { attachments += 1; },
    }), { code: 'COMPOSE_BODY_TOO_LARGE' });
    assert.equal(attachments, 0);
    const normal = composeFixture();
    await composeLibrary.prependTemplate(normal.office, normal.item, '<p>Template</p>');
    assert.equal(normal.state.bodyReads, 1);
    assert.equal(normal.state.prepends.length, 1);
});

test('parallel template clicks share the preflight/media lock and cannot create duplicate native writes', async () => {
    const { office, item, state } = composeFixture();
    let release;
    let prepared = 0;
    const waitForMedia = new Promise((resolve) => { release = resolve; });
    const first = composeLibrary.prependTemplate(office, item, '<p>Template</p>', () => {}, {
        async beforeInsert() { prepared += 1; await waitForMedia; },
    });
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Duplicate</p>'), { code: 'TEMPLATE_INSERT_IN_PROGRESS' });
    release();
    await first;
    assert.equal(prepared, 1);
    assert.equal(state.bodyReads, 1);
    assert.equal(state.prepends.length, 1);
    assert.equal(composeLibrary.isTemplateInsertionBlocked(item), false);
});

test('a pending session prevents insertion from a reopened taskpane module and definite failures release only their claim', async () => {
    const reopened = await import('../../resources/js/outlook-addin/compose-template.js?reopened-taskpane-test');
    const fixture = composeFixture();
    let sessionValue = null;
    fixture.item.sessionData = {
        getAsync(_key, callback) { callback({ status: 'succeeded', value: sessionValue }); },
        setAsync(_key, value, callback) { sessionValue = value; callback({ status: 'succeeded' }); },
    };
    let release;
    let preparing = false;
    const pending = new Promise((resolve) => { release = resolve; });
    const first = composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Template</p>', () => {}, {
        async beforeInsert() { preparing = true; await pending; },
    });
    for (let index = 0; index < 80 && !preparing; index += 1) await Promise.resolve();
    assert.equal(preparing, true);
    assert.match(sessionValue, /^pending:/);
    await assert.rejects(reopened.prependTemplate(fixture.office, fixture.item, '<p>Duplicate</p>'), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.equal(fixture.state.bodyReads, 1);
    release();
    await first;
    assert.equal(sessionValue, '1');
    await assert.rejects(reopened.prependTemplate(fixture.office, fixture.item, '<p>Duplicate</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(fixture.state.prepends.length, 1);

    const failed = composeFixture();
    sessionValue = '';
    failed.item.sessionData = fixture.item.sessionData;
    await assert.rejects(composeLibrary.prependTemplate(failed.office, failed.item, '<p>Template</p>', () => {}, {
        beforeInsert() { throw Object.assign(new Error('Definite attachment failure'), { code: 'INLINE_ATTACHMENT_FAILED' }); },
    }), { code: 'INLINE_ATTACHMENT_FAILED' });
    assert.equal(sessionValue, '');
    await reopened.prependTemplate(failed.office, failed.item, '<p>Retry</p>');
    assert.equal(failed.state.prepends.length, 1);
});

test('failed session reads never proceed to body reads or native media writes', async () => {
    const fixture = composeFixture();
    fixture.item.sessionData = { getAsync(_key, callback) { callback({ status: 'failed' }); } };
    let media = 0;
    await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Template</p>', () => {}, {
        beforeInsert() { media += 1; },
    }), { code: 'COMPOSE_SESSION_UNREADABLE' });
    assert.equal(fixture.state.bodyReads, 0);
    assert.equal(media, 0);
    assert.equal(fixture.state.prepends.length, 0);
});

test('manual library choice remains available after the default but every additional template requires confirmation and a fresh budget', async () => {
    const fixture = composeFixture();
    let sessionValue = '';
    fixture.item.sessionData = {
        getAsync(_key, callback) { callback({ status: 'succeeded', value: sessionValue }); },
        setAsync(_key, value, callback) { sessionValue = value; callback({ status: 'succeeded' }); },
    };
    await composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Default</p>');
    const original = fixture.state.html;
    const reads = fixture.state.bodyReads;
    let media = 0;
    await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Alternative</p>', () => {}, {
        confirmAdditional: () => false,
        beforeInsert() { media += 1; },
    }), { code: 'TEMPLATE_INSERT_CANCELLED' });
    assert.equal(fixture.state.bodyReads, reads + 1);
    assert.equal(fixture.state.html, original);
    assert.equal(sessionValue, '1');
    assert.equal(media, 0);

    await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Alternative</p>', () => {}, {
        confirmAdditional: () => true,
        beforeInsert() { throw Object.assign(new Error('Definite failure'), { code: 'INLINE_ATTACHMENT_FAILED' }); },
    }), { code: 'INLINE_ATTACHMENT_FAILED' });
    assert.equal(sessionValue, '1', 'failure restores existing default marker, not an empty message');
    await composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Alternative</p>', () => {}, {
        confirmAdditional: () => true,
    });
    assert.equal(fixture.state.prepends.length, 2);
    assert.ok(fixture.state.html.endsWith(original));
    await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Automatic repeat</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });

    fixture.state.html += 'x'.repeat(500000);
    let confirmations = 0;
    await assert.rejects(composeLibrary.prependTemplate(fixture.office, fixture.item, '<p>Too much</p>', () => {}, {
        confirmAdditional() { confirmations += 1; return true; },
    }), { code: 'COMPOSE_BODY_TOO_LARGE' });
    assert.equal(confirmations, 0);
    assert.equal(fixture.state.prepends.length, 2);
});

test('uncertain attachment results stop template writes and quarantine the compose item', async () => {
    const { office, item, state } = composeFixture();
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Template</p>', () => {}, {
        beforeInsert() { throw Object.assign(new Error('Timeout'), { code: 'INLINE_ATTACHMENT_UNCERTAIN' }); },
    }), { code: 'INLINE_ATTACHMENT_UNCERTAIN' });
    assert.equal(state.prepends.length, 0);
    assert.equal(composeLibrary.isTemplateInsertionBlocked(item), true);
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Retry</p>'), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
});

test('native timeout never retries; late success establishes the duplicate guard', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { office, item, state } = composeFixture();
    let nativeCallback;
    item.body.prependAsync = (html, _options, callback) => {
        state.prepends.push(html);
        nativeCallback = callback;
    };
    const operation = composeLibrary.prependTemplate(office, item, '<p>Template</p>');
    const rejected = assert.rejects(operation, { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    for (let index = 0; index < 30 && !nativeCallback; index += 1) await Promise.resolve();
    assert.equal(typeof nativeCallback, 'function');
    context.mock.timers.tick(composeLibrary.TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    await rejected;
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Retry</p>'), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    nativeCallback({ status: 'succeeded' });
    await assert.rejects(composeLibrary.prependTemplate(office, item, '<p>Retry</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(state.prepends.length, 1);
});

async function runtimeFixture(options = {}) {
    const fixture = composeFixture(options);
    const marker = 'RT-SIGNATURE-MANAGED-V1';
    const media = [{ name: 'railtime-test.png', contentId: 'logo', base64: 'aW1hZ2U=' }];
    const bootstrap = {
        marker,
        binding: testMailboxBinding,
        automaticTemplateId: options.withoutDefault ? null : 'default',
        templates: [{ id: 'default', isDefault: true, html: '<p>Default</p><img src="cid:logo">', media }],
        signature: { html: '<p>Signature</p><img src="cid:logo">', media },
    };
    fixture.bootstrap = bootstrap;
    const configuration = {
        ready: true,
        marker,
        auth: { clientId: 'test-client', authority: 'https://login.microsoftonline.com/test', scopes: ['Signature.Read'] },
        endpoints: { bootstrap: 'https://example.test/api/bootstrap' },
    };
    const runtimeSource = (await source('../../resources/js/outlook-addin/runtime.js'))
        .replace(/import\s*\{([\s\S]*?)\}\s*from '@azure\/msal-browser';/, 'const {$1} = auth;')
        .replace(/import\s*\{([\s\S]*?)\}\s*from '\.\/[^']+\.js';/g, 'const {$1} = shared;');
    const auth = {
        InteractionRequiredAuthError: class extends Error {},
        createNestablePublicClientApplication: async () => ({
            getAllAccounts() { return []; },
            async acquireTokenSilent() { return { accessToken: 'synthetic-test-token' }; },
        }),
    };
    const createHandler = new Function('Office', 'globalThis', 'fetch', 'auth', 'shared', 'console', `${runtimeSource}\nreturn handleComposeEvent;`);
    let failedBootstrap = false;
    fixture.handler = createHandler(
        fixture.office,
        { Office: fixture.office, RAILTIME_OUTLOOK_CONFIG_URL: 'https://example.test/config.json' },
        async (url) => {
            if (options.failFirstBootstrap && !url.includes('config.json') && !failedBootstrap) {
                failedBootstrap = true;
                throw new Error('Synthetic transient network failure');
            }
            return { ok: true, json: async () => url.includes('config.json') ? configuration : bootstrap };
        },
        auth,
        composeLibrary,
        { info() {} },
    );
    return fixture;
}

test('compose event inserts explicit default once for new, reply and forward, and completes every event', async () => {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        const existing = '<p>User text</p><div id="divRplyFwdMsg">Quoted conversation</div>';
        const { handler, event, state } = await runtimeFixture({ composeType, html: existing });
        await Promise.all([handler(event), handler(event)]);
        assert.equal(state.prepends.length, 1);
        assert.equal(state.signatures.length, 0, 'full template already includes signature');
        assert.equal(state.completed, 2);
        assert.equal(state.attachments.length, 1);
        assert.equal(state.bodyReads, 1, 'one preflight, no full conversation readback after inserting');
        assert.ok(state.html.endsWith(existing));
    }
});

test('RailTime app links never install or open Outlook as the app target', async () => {
    const view = await source('../../resources/views/outlook-addin/taskpane.blade.php');
    const links = await source('../../resources/views/components/email-templates/outlook-app-links.blade.php');
    for (const markup of [view, links]) {
        assert.match(markup, /route\('home'\)/);
        assert.match(markup, /route\('help'\)/);
        assert.doesNotMatch(markup, /https:\/\/outlook\.office\.com|Outlook als Browser-App/);
        assert.match(markup, /RailTime öffnen/);
    }
    assert.match(view, /RailTime als App installieren/);
    assert.match(links, /installApp\(\)/);
});

test('compose event falls back to signature on mobile or without explicit default and never overwrites body', async () => {
    for (const options of [{ platform: 'iOS' }, { platform: 'Android' }, { withoutDefault: true }]) {
        const { handler, event, state } = await runtimeFixture(options);
        await handler(event);
        assert.equal(state.prepends.length, 0);
        assert.equal(state.signatures.length, 1);
        assert.equal(state.html, '<p>Existing user text</p>');
        assert.equal(state.completed, 1);
    }
});

test('compose event retries a failed activation and still suppresses subsequent successful duplicates', async () => {
    const { handler, event, state } = await runtimeFixture({ failFirstBootstrap: true });
    await handler(event);
    assert.equal(state.prepends.length, 0);
    assert.equal(state.completed, 1);
    await handler(event);
    await handler(event);
    assert.equal(state.prepends.length, 1);
    assert.equal(state.completed, 3);
});

test('compose event stops after mailbox item changes during media insertion', async () => {
    const { handler, event, state, office, item } = await runtimeFixture();
    item.addFileAttachmentFromBase64Async = (_base64, _name, _options, callback) => {
        office.context.mailbox.item = {};
        callback({ status: 'succeeded', value: 'attachment' });
    };
    await handler(event);
    assert.equal(state.prepends.length, 0);
    assert.equal(state.signatures.length, 0);
    assert.equal(state.completed, 1);
});

test('automatic template and signature never write into a private From mailbox', async () => {
    for (const withoutDefault of [false, true]) {
        const fixture = await runtimeFixture({ withoutDefault });
        fixture.item.from.getAsync = (callback) => callback({ status: 'succeeded', value: { emailAddress: 'private@personal.test' } });
        await fixture.handler(fixture.event);
        assert.equal(fixture.state.prepends.length, 0);
        assert.equal(fixture.state.signatures.length, 0);
        assert.equal(fixture.state.attachments.length, 0);
        assert.equal(fixture.state.completed, 1);
    }
});

test('From change while media is prepared prevents a template or signature body write', async () => {
    for (const withoutDefault of [false, true]) {
        const fixture = await runtimeFixture({ withoutDefault });
        fixture.item.addFileAttachmentFromBase64Async = (_base64, _name, _options, callback) => {
            fixture.item.from.getAsync = cb => cb({ status: 'succeeded', value: { emailAddress: 'private@personal.test' } });
            callback({ status: 'succeeded', value: 'media' });
        };
        await fixture.handler(fixture.event);
        assert.equal(fixture.state.prepends.length, 0);
        assert.equal(fixture.state.signatures.length, 0);
        assert.equal(fixture.state.completed, 1);
    }
});

test('automatic preflight read failure is not cached as a completed insertion', async () => {
    const fixture = await runtimeFixture();
    let failed = true, session = '';
    fixture.item.sessionData = {
        getAsync(_key, callback) { callback(failed ? { status: 'failed' } : { status: 'succeeded', value: session }); },
        setAsync(_key, value, callback) { session = value; callback({ status: 'succeeded' }); },
    };
    await fixture.handler(fixture.event);
    assert.equal(fixture.state.attachments.length, 0);
    assert.equal(fixture.state.prepends.length, 0);
    assert.equal(fixture.state.signatures.length, 0);
    failed = false;
    await fixture.handler(fixture.event);
    await fixture.handler(fixture.event);
    assert.equal(fixture.state.prepends.length, 1);
    assert.equal(fixture.state.completed, 3);
});

test('automatic insertion never falls back to another write after an unconfirmed native prepend', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const fixture = await runtimeFixture();
    let invoked = false;
    fixture.item.body.prependAsync = () => { invoked = true; };
    const pending = fixture.handler(fixture.event);
    for (let index = 0; index < 80 && !invoked; index += 1) await Promise.resolve();
    assert.equal(invoked, true);
    context.mock.timers.tick(composeLibrary.TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    await pending;
    await fixture.handler(fixture.event);
    assert.equal(fixture.state.signatures.length, 0);
    assert.equal(fixture.state.attachments.length, 1);
    assert.equal(fixture.state.completed, 2);
});

test('signature-only activation completes on a lost Office callback and does not retry the uncertain write', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const fixture = await runtimeFixture({ withoutDefault: true });
    let signatures = 0;
    fixture.item.body.setSignatureAsync = () => { signatures += 1; };
    const pending = fixture.handler(fixture.event);
    for (let index = 0; index < 80 && signatures === 0; index += 1) await Promise.resolve();
    assert.equal(signatures, 1);
    context.mock.timers.tick(30000);
    await pending;
    await fixture.handler(fixture.event);
    assert.equal(signatures, 1);
    assert.equal(fixture.state.completed, 2);
});

test('late confirmed signature success is never reinserted by a later compose event', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const fixture = await runtimeFixture({ withoutDefault: true });
    let callback, count = 0;
    fixture.item.body.setSignatureAsync = (_html, _options, cb) => { callback = cb; count++; };
    const first = fixture.handler(fixture.event);
    for (let index = 0; index < 100 && !callback; index++) await Promise.resolve();
    assert.equal(count, 1);
    context.mock.timers.tick(30000);
    await first;
    callback({ status: 'succeeded' });
    await fixture.handler(fixture.event);
    assert.equal(count, 1);
    assert.equal(fixture.state.completed, 2);
});

test('authenticated taskpane inserts once on double click with one body read and no readback', async () => {
    const { parseHTML } = await import('linkedom');
    const { document, window } = parseHTML(await source('../../resources/views/outlook-addin/taskpane.blade.php'));
    Object.defineProperty(document.querySelector('select'), 'value', { writable: true, value: '' });
    const fixture = composeFixture();
    const marker = 'RT-SIGNATURE-MANAGED-V1';
    const config = { marker, auth: { scopes: ['Signature.Read'] }, endpoints: { bootstrap: 'https://example.test/api/bootstrap' } };
    const bootstrap = {
        marker,
        binding: testMailboxBinding,
        templates: [{ id: 'one', name: 'Example', html: '<table><tr><td>Example</td></tr></table>', media: [] }],
    };
    const auth = {
        InteractionRequiredAuthError: class extends Error {},
        createNestablePublicClientApplication: async () => ({
            getAllAccounts() { return []; },
            acquireTokenSilent: async () => ({ accessToken: 'synthetic-test-token' }),
        }),
    };
    const script = (await source('../../resources/js/outlook-addin/taskpane.js'))
        .replace(/import\s*\{([\s\S]*?)\}\s*from '@azure\/msal-browser';/, 'const {$1} = auth;')
        .replace(/import\s*\{([\s\S]*?)\}\s*from '\.\/[^']+\.js';/g, (_match, names) => `const {${names.replace(/\s+as\s+/g, ': ')}} = shared;`)
        .replace(/\bexport (?=(?:async )?function)/g, '');
    const client = new Function('Office', 'globalThis', 'document', 'window', 'fetch', 'auth', 'shared', `${script}\nreturn {
        async setup(config, payload) { currentConfig = config; configPromise = Promise.resolve(config); taskpaneState.configReady = true; taskpaneState.authenticated = true; taskpaneState.busy = false; await acceptBootstrap(payload, {inspectBody:false}); },
        insertTemplate, updateSignature, taskpaneState, runDiagnostics, bindActions, requestSilentBootstrapRefresh,
        resetBootstrapAge() { lastBootstrapRefreshAt = 0; },
        refreshPending() { return silentBootstrapRefreshPromise; }
    };`)(fixture.office, { Office: fixture.office }, document, window,
        async () => ({ ok: true, json: async () => bootstrap }), auth, composeLibrary);
    await client.setup(config, bootstrap);
    const button = document.querySelector('[data-outlook-action="template"]');
    await Promise.all([client.insertTemplate(button), client.insertTemplate(button)]);
    assert.equal(fixture.state.prepends.length, 1);
    assert.equal(fixture.state.bodyReads, 1);
    assert.equal(client.taskpaneState.templatePresent, true);
    assert.equal(client.taskpaneState.busy, false);
    assert.equal(button.disabled, false, 'explicit additional template selection remains available after an automatic or manual insertion');
    assert.equal(document.querySelector('[data-outlook-status-title]').textContent, 'Vorlage wurde oberhalb eingefügt');

    for (const failure of ['unreadable', 'oversized']) {
        const fresh = composeFixture({ html: 'x'.repeat(failure === 'oversized' ? 500001 : 10) });
        if (failure === 'unreadable') fresh.item.body.getAsync = (_format, callback) => callback({ status: 'failed' });
        fixture.office.context.mailbox.item = fresh.item;
        await client.updateSignature(document.querySelector('[data-outlook-action="signature"]'));
        assert.equal(fresh.state.signatures.length, 0, `${failure}: no signature write`);
        assert.equal(fresh.state.attachments.length, 0, `${failure}: no media writes`);
        assert.match(document.querySelector('[data-outlook-status-detail]').textContent,
            failure === 'unreadable' ? /nicht sicher geprüft/ : /Sicherheitsbudget/);
        assert.equal(client.taskpaneState.busy, false);
    }

    const personal = composeFixture();
    personal.item.from.getAsync = cb => cb({ status: 'succeeded', value: { emailAddress: 'private@personal.test' } });
    fixture.office.context.mailbox.item = personal.item;
    for (const action of [client.insertTemplate, client.updateSignature]) {
        await action(button);
        assert.match(document.querySelector('[data-outlook-status-detail]').textContent, /nicht als Ihr RailTime-Microsoft-Postfach bestätigt/);
        assert.equal(personal.state.prepends.length, 0);
        assert.equal(personal.state.signatures.length, 0);
        assert.equal(personal.state.attachments.length, 0);
    }

    const fresh = composeFixture();
    fixture.office.context.mailbox.item = fresh.item;
    fresh.item.sessionData = { getAsync(_key, cb) { cb({ status: 'failed', error: { code: 'READ_FAILURE' } }); } };
    await client.setup(config, bootstrap);
    await client.updateSignature(document.querySelector('[data-outlook-action="signature"]'));
    const errorText = document.querySelector('[data-outlook-status-detail]').textContent;
    assert.match(errorText, /Vorprüfung.*noch nicht gestartet/);
    client.resetBootstrapAge();
    client.requestSilentBootstrapRefresh();
    await client.refreshPending();
    assert.equal(document.querySelector('[data-outlook-status-detail]').textContent, errorText, 'silent successful refresh never overwrites an insertion error');
    assert.equal(document.querySelector('[data-outlook-connection-chip]').textContent, 'Prüfen');

    fresh.item.sessionData = undefined;
    await client.runDiagnostics(document.querySelector('[data-outlook-diagnostics-run]'));
    assert.equal(fresh.state.prepends.length, 0);
    assert.equal(fresh.state.signatures.length, 0);
    assert.equal(fresh.state.attachments.length, 0);
    const report = JSON.parse(document.querySelector('[data-outlook-diagnostics-output]').value);
    assert.equal(report.checks.boundMailbox, true);
    assert.doesNotMatch(JSON.stringify(report), /employee@example|private@personal|synthetic-test-token/);
    assert.equal(document.querySelector('[data-outlook-status-title]').textContent, 'Verbindung und Vorprüfung bestätigt');

    client.bindActions();
    await client.insertTemplate(button);
    assert.equal(fresh.state.prepends.length, 1);
    const confirmation = document.querySelector('[data-outlook-template-confirmation]');
    const cancel = client.insertTemplate(button);
    for (let i = 0; i < 120 && confirmation.hidden; i++) await Promise.resolve();
    assert.equal(confirmation.hidden, false, 'additional insertion is a visible in-pane choice');
    document.querySelector('[data-outlook-template-cancel]').click();
    await cancel;
    assert.equal(fresh.state.prepends.length, 1);
    const extra = client.insertTemplate(button);
    for (let i = 0; i < 120 && confirmation.hidden; i++) await Promise.resolve();
    document.querySelector('[data-outlook-template-confirm]').click();
    await extra;
    assert.equal(fresh.state.prepends.length, 2);
    assert.equal(confirmation.hidden, true);
});

test('Outlook dialogs open only on explicit clicks and restore opener focus after closing', async () => {
    const { parseHTML } = await import('linkedom');
    const { document, window } = parseHTML('<button data-outlook-dialog-open="status">Status</button><dialog data-outlook-dialog="status"><button data-outlook-dialog-close>Close</button></dialog>');
    const previousOffice = globalThis.Office;
    globalThis.Office = { onReady() {} };
    try {
        const { bindOutlookDialogs } = await import('../../resources/js/outlook-addin/taskpane.js');
        const dialog = document.querySelector('dialog');
        const opener = document.querySelector('button');
        let focused = 0;
        opener.focus = () => { focused += 1; };
        dialog.showModal = () => { dialog.open = true; };
        dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new window.Event('close')); };
        bindOutlookDialogs(document);
        bindOutlookDialogs(document);
        assert.equal(Boolean(dialog.open), false);
        opener.click();
        assert.equal(dialog.open, true);
        dialog.querySelector('button').click();
        assert.equal(dialog.open, false);
        assert.equal(focused, 1);
    } finally {
        globalThis.Office = previousOffice;
    }
});

test('hosted taskpane help and install dialogs work without Office, outside Outlook and during a delayed handshake', async () => {
    const { parseHTML } = await import('linkedom');
    const { startOutlookTaskpane } = await import('../../resources/js/outlook-addin/taskpane.js');
    const markup = await source('../../resources/views/outlook-addin/taskpane.blade.php');
    const previous = { Office: globalThis.Office, document: globalThis.document, window: globalThis.window };
    const cases = [
        { name: 'Office.js unavailable', office: undefined, unavailable: true },
        { name: 'ordinary browser', office: { HostType: { Outlook: 'Outlook' }, onReady(callback) { callback({ host: null }); } }, unavailable: true },
        { name: 'another Office host', office: { HostType: { Outlook: 'Outlook' }, onReady(callback) { callback({ host: 'Word' }); } }, unavailable: true },
        { name: 'failed handshake', office: { onReady() { return Promise.reject(new Error('Synthetic Office.js failure')); } }, unavailable: true },
        { name: 'handshake pending', office: { onReady() {} }, unavailable: false },
    ];

    try {
        for (const scenario of cases) {
            const { document, window } = parseHTML(markup);
            globalThis.document = document;
            globalThis.window = window;
            globalThis.Office = scenario.office;
            const dialogs = Array.from(document.querySelectorAll('[data-outlook-dialog]'));
            let opened = 0;
            dialogs.forEach((dialog) => {
                dialog.showModal = () => { dialog.open = true; opened += 1; };
                dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new window.Event('close')); };
            });

            assert.doesNotThrow(() => startOutlookTaskpane(scenario.office), scenario.name);
            startOutlookTaskpane(scenario.office);
            await Promise.resolve();
            assert.equal(opened, 0, 'no automatic help or login popup');
            for (const name of ['install', 'status', 'connection']) {
                const dialog = document.querySelector(`[data-outlook-dialog="${name}"]`);
                document.querySelector(`[data-outlook-dialog-open="${name}"]`).click();
                assert.equal(dialog.open, true, `${scenario.name}: ${name} dialog opens`);
                dialog.querySelector('[data-outlook-dialog-close]').click();
                assert.equal(dialog.open, false);
            }
            assert.equal(opened, 3, 'repeat startup does not register duplicate handlers');
            if (scenario.unavailable) {
                assert.equal(document.querySelector('[data-outlook-addin-taskpane]').getAttribute('aria-busy'), 'false');
                assert.equal(document.querySelector('[data-outlook-status-title]').textContent, 'In Outlook öffnen');
                assert.equal(document.querySelector('[data-outlook-connection-chip]').textContent, 'Browser');
                assert.match(document.querySelector('[data-outlook-template-error]').textContent, /unter „Apps“ öffnen/);
                assert.equal(document.querySelector('[data-outlook-action="template"]').disabled, true);
                assert.equal(document.querySelector('[data-outlook-action="login"]').disabled, true);
                assert.match(document.querySelector('[data-outlook-dialog-open="status"]').getAttribute('aria-label'), /In Outlook öffnen/);
            }
        }
    } finally {
        globalThis.Office = previous.Office;
        globalThis.document = previous.document;
        globalThis.window = previous.window;
    }
});

test('Outlook connection feedback mirrors errors as text and retains focus within the open dialog', async () => {
    const { parseHTML } = await import('linkedom');
    const { setStatus } = await import('../../resources/js/outlook-addin/taskpane.js');
    const { document } = parseHTML(await source('../../resources/views/outlook-addin/taskpane.blade.php'));
    const previousDocument = globalThis.document;
    globalThis.document = document;

    try {
        const connection = document.querySelector('[data-outlook-dialog="connection"]');
        const feedback = connection.querySelector('[data-outlook-dialog-feedback]');
        const statusButton = document.querySelector('[data-outlook-dialog-open="status"]');
        const statusDialog = document.querySelector('[data-outlook-dialog="status"]');
        let feedbackFocus = 0;
        let buttonFocus = 0;
        feedback.focus = () => { feedbackFocus += 1; };
        statusButton.focus = () => { buttonFocus += 1; };

        connection.open = true;
        setStatus('error', 'Verbindung fehlgeschlagen', 'Bitte erneut versuchen. <img src="test">', true);
        assert.equal(feedback.hidden, false);
        assert.equal(feedback.dataset.tone, 'error');
        assert.equal(feedback.getAttribute('role'), 'alert');
        assert.equal(feedback.getAttribute('aria-live'), 'assertive');
        assert.match(feedback.textContent, /Bitte erneut versuchen\. <img src="test">/);
        assert.equal(feedback.querySelector('img'), null, 'status data is never interpreted as HTML');
        assert.equal(feedbackFocus, 1);
        assert.equal(buttonFocus, 0, 'do not focus an inert button behind the connection dialog');
        assert.equal(Boolean(statusDialog.open), false, 'errors never automatically open another modal');
        assert.equal(statusButton.dataset.tone, 'error');

        setStatus('success', 'RailTime ist bereit', 'Verbindung erfolgreich.');
        assert.equal(feedback.dataset.tone, 'success');
        assert.equal(feedback.getAttribute('role'), 'status');
        assert.equal(feedback.getAttribute('aria-live'), 'polite');
        assert.match(feedback.textContent, /Verbindung erfolgreich/);

        connection.open = false;
        setStatus('error', 'Abruf fehlgeschlagen', '', true);
        assert.equal(buttonFocus, 1, 'a closed dialog sends requested error focus to the visible status control');
        feedback.remove();
        assert.doesNotThrow(() => setStatus('neutral', 'Status ohne Zusatzfeld'), 'feedback remains optional');
    } finally {
        globalThis.document = previousDocument;
    }
});

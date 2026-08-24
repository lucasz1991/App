import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root));
const text = (path) => read(path).toString('utf8');

function skipSubBlocks(bytes, start) {
    let offset = start;
    while (offset < bytes.length) {
        const size = bytes[offset];
        offset += 1;
        if (size === 0) return offset;
        offset += size;
    }
    throw new Error('Unvollstaendige GIF-Subblocks.');
}

function gifTimeline(bytes) {
    assert.equal(bytes.subarray(0, 6).toString('ascii'), 'GIF89a');
    let offset = 13;
    const packed = bytes[10];
    if ((packed & 0x80) !== 0) offset += 3 * (1 << ((packed & 0x07) + 1));

    const delays = [];
    let pendingDelay = null;
    while (offset < bytes.length) {
        const marker = bytes[offset];
        offset += 1;
        if (marker === 0x3b) break;
        if (marker === 0x21) {
            const label = bytes[offset];
            offset += 1;
            if (label === 0xf9) {
                assert.equal(bytes[offset], 4);
                pendingDelay = bytes.readUInt16LE(offset + 2);
                offset += 6;
            } else {
                offset = skipSubBlocks(bytes, offset);
            }
            continue;
        }
        assert.equal(marker, 0x2c, `Unbekannter GIF-Marker 0x${marker.toString(16)}`);
        const imagePacked = bytes[offset + 8];
        offset += 9;
        if ((imagePacked & 0x80) !== 0) offset += 3 * (1 << ((imagePacked & 0x07) + 1));
        offset += 1; // LZW minimum code size
        offset = skipSubBlocks(bytes, offset);
        assert.notEqual(pendingDelay, null);
        delays.push(pendingDelay);
        pendingDelay = null;
    }

    return delays;
}

test('all delivered mail outputs keep the train-first flow overlap and a Classic Outlook IMG', () => {
    const signature = text('app/Support/MailSignature.php');
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const carrier = text('app/Support/Mail/SignatureTrainCarrier.php');
    const previewService = text('app/Services/PageBuilder/PageBuilderPreviewService.php');
    const builder = text('app/Support/EmailTemplateBuilder.php');
    const cssSemantic = text('app/Support/Mail/CssSemantic.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');
    const routes = text('routes/api.php');
    const msoFallback = carrier.slice(
        carrier.indexOf('public static function withMsoFallback'),
        carrier.indexOf('public static function withIdleOverlay'),
    );

    assert.match(signature, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(signature, /SignatureTrainCarrier::projectAsRuntimeBackground\(/);
    assert.equal((signature.match(/SignatureTrainCarrier::withMsoFallback\(/g) || []).length, 1);
    assert.match(signature, /\$outlookFallbackSource = trim\(\(string\) \([\s\S]+?\$values\['TRAIN_STILL_SRC'\]/);
    assert.match(signature, /zug-dampf-idle-/);
    assert.match(signature, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(signature, /\$tokenizedTrainCarrier = \$this->usesTokenizedTrainCarrier\(\$values, \$layout\);/);
    assert.match(signature, /\$html = \$this->projectPublishedTrainAsImage\(\$html, \$singleTrainLayout\);/);
    assert.doesNotMatch(signature, /function appendClassicOutlookTrainFallback/);
    assert.doesNotMatch(signature, /class="rt-classic-outlook-train"/);
    assert.match(carrier, /public static function withoutMainLayer/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.doesNotMatch(carrier, /public static function projectAsRuntimeBackground/);
    assert.match(carrier, /public static function withMsoFallback/);
    assert.match(carrier, /public static function withIdleOverlay/);
    assert.match(carrier, /<span class="rt-train-idle-overlay" data-rt-train-idle-overlay[\s\S]*?<img class="rt-train-idle-image" data-rt-train-idle-image src="'\.\$escapedSource\.'"[\s\S]*?<\/span>/);
    assert.doesNotMatch(carrier, /background-image:[^;]*(?:TRAIN|train|\.gif)/);
    assert.doesNotMatch(carrier, /<!--\[if mso\]><tr><td class="rt-sign-train-mso"/);
    assert.match(carrier, /<!--\[if mso\]><img class="rt-sign-train-mso"/);
    assert.match(msoFallback, /self::assertRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.match(msoFallback, /<img class="rt-sign-train-mso"[^>]*display:inline-block;[^>]*vertical-align:bottom;/);
    assert.doesNotMatch(carrier, /rt-sign-train-mso[\s\S]*?width:720px;max-width:100%/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(carrier, /return '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="'\.\$alignment\.'" data-rt-layer-size="'\.\$sizeName\.'" data-rt-layer-mobile="'\.\$mobileCrop\.'" '/);
    assert.match(carrier, /'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:'\.self::layerMargin\(\$alignment\)\.';margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;">'/);
    assert.match(carrier, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"[^>]*>[\s\S]*?<td class="rt-sign-train-slot" height="200" valign="bottom"[^>]*>[\s\S]*?<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"[^>]*style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;[^"\r\n]*vertical-align:bottom;[^"\r\n]*mso-hide:all;/);
    assert.match(carrier, /<table class="rt-sign-content-frame" role="presentation" width="100%" height="200"/);
    assert.match(msoFallback, /substr_replace\(\$html, \$fallback, \$slots\[0\]\['endOffset'\] \+ 1, 0\)/);
    assert.match(previewService, /MailSignature::forCompany\(/);
    assert.match(previewService, /\$signatureRenderer->renderDocument\(/);
    assert.doesNotMatch(previewService, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(builder, /function validateBrowserCopyTrainImages/);
    assert.match(builder, /SignatureTrainCarrier::assertRuntimeImages\(/);
    assert.doesNotMatch(
        builder.slice(
            builder.indexOf('function validateBrowserCopyTrainImages'),
            builder.indexOf('private static function imageSources'),
        ),
        /\$trainImages|\$trainLayers|data-rt-layer-train/,
    );
    assert.match(cssSemantic, /'data-rt-train-idle-overlay'/);
    assert.match(cssSemantic, /'data-rt-train-idle-image'/);
    assert.match(cssSemantic, /\$isProtectedAttribute = in_array\(/);
    assert.match(responsiveCss, /@keyframes rt-train-idle-reveal/);
    assert.match(responsiveCss, /animation-delay:\s*13s/);
    assert.match(responsiveCss, /\.rt-sign-train-layer\s*\{[^}]*display:\s*block !important;[^}]*margin-top:\s*0 !important;/s);
    assert.doesNotMatch(responsiveCss, /\.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;/s);
    assert.match(responsiveCss, /\.rt-sign-train,\s*\.rt-sign-train-mso\s*\{[^}]*position:\s*static !important;[^}]*bottom:\s*auto !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.doesNotMatch(responsiveCss, /\.rt-sign-train-mso\s*\{[^}]*width:\s*100% !important;[^}]*margin:\s*0 !important;/s);
    assert.match(responsiveCss, /\.rt-train-idle-overlay\s*\{[^}]*top:\s*auto !important;[^}]*bottom:\s*0 !important;[^}]*max-width:\s*none !important;/s);
    assert.match(responsiveCss, /\.rt-train-idle-image\s*\{[^}]*position:\s*absolute !important;[^}]*bottom:\s*0 !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.match(responsiveCss, /prefers-reduced-motion:[^)]+\)[\s\S]*?\.rt-train-idle-overlay/);
    assert.doesNotMatch(routes, /mail-animations\/train/);
    assert.match(signatureView, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(signatureView, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}" width="720"[^>]*position:static;[^>]*bottom:auto;[^>]*display:inline-block;[^>]*vertical-align:bottom;[^>]*mso-hide:all;/);
    assert.match(signatureView, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="center" data-rt-layer-size="125" data-rt-layer-mobile="train" style="display:block;[^">]*height:200px;max-height:200px;[^">]*margin:0 auto;margin-bottom:-200px;/);
    assert.match(signatureView, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"/);
    assert.match(signatureView, /<td class="rt-sign-train-slot" height="200" valign="bottom"/);
    assert.match(signatureView, /<table class="rt-sign-content-frame" role="presentation" width="100%" height="200"/);
    assert.doesNotMatch(signatureView, /rt-sign-train-layer[^>]*height:100%/);
    assert.doesNotMatch(carrier, /rt-train-idle-overlay[^>]*height:100%/);
    assert.match(carrier, /rt-train-idle-overlay[^>]*position:absolute;[^>]*display:block;[^>]*height:0;max-height:0;[^>]*overflow:hidden;/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.match(
        signatureView,
        /<td class="rt-sign-cell"[^>]+style="padding:0;overflow:hidden;/,
    );
    assert.doesNotMatch(signatureView, /<td class="rt-sign-cell"[^>]+position:relative/);
    assert.match(
        signatureView,
        /<td class="rt-pad rt-sign-content" valign="bottom" style="padding:\{\{ \$padding \}\};position:relative;z-index:1;vertical-align:bottom;">/,
    );
    assert.doesNotMatch(signatureView, /class="rt-pad rt-sign-cell"/);
    assert.doesNotMatch(signatureView, /\$cellPadding/);
    assert.match(signatureView, /\$padding = \$padding \?\? '0 36px 15px';/);
    assert.match(signatureView, /\$outlookTrainPadding = \$outlookTrainPadding \?\? '0';/);
    assert.match(carrier, /compactDefaultContentPadding/);
    assert.match(carrier, /'padding:18px 36px 20px;' => 'padding:0 36px 15px;'/);
    assert.match(carrier, /' valign="bottom"'/);
    assert.match(responsiveCss, /\.rt-sign-content \{ padding-top: 0 !important; padding-bottom: 15px !important; \}/);
    const phoneCss = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-sign-content\s*\{\s*padding-top:\s*10px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-sign-logo\s*\{\s*padding-bottom:\s*10px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-sign-top-row > \.rt-sign-identity\s*\{\s*padding-top:\s*8px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-sign-company\s*\{\s*padding-top:\s*8px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-sign-identity \.rt-contact\s*\{\s*margin-top:\s*8px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v9"\] \.rt-company-contact\s*\{\s*margin-top:\s*10px !important;/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.doesNotMatch(responsiveCss, /\.rt-sign-cell\.rt-sign-train-background/);
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position:\s*center center !important;[\s\S]+?background-size:\s*100% 100% !important;/);
    assert.doesNotMatch(signatureView, /signatur-(?:raster|marke)-/);
});

test('idle IMG remains a bottom-anchored zero-height overlay when animation support is available', () => {
    const carrier = text('app/Support/Mail/SignatureTrainCarrier.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');
    const idleMethod = carrier.slice(
        carrier.indexOf('public static function withIdleOverlay'),
        carrier.indexOf('public static function withoutLegacyBackgroundAttribute'),
    );

    assert.match(
        idleMethod,
        /<span class="rt-train-idle-overlay" data-rt-train-idle-overlay[^>]*height:0;max-height:0;[^>]*overflow:hidden;[^>]*>[\s\S]*?<img class="rt-train-idle-image" data-rt-train-idle-image[\s\S]*?<\/span>/,
    );
    assert.match(idleMethod, /substr_replace\(\$html, \$overlay, \$images\[0\]\['startOffset'\], 0\)/);
    assert.match(carrier, /count\(\$slotElements\) !== 2[\s\S]*?\$slotElements\[0\]->isSameNode\(\$holder\)[\s\S]*?\$slotElements\[1\]->isSameNode\(\$main\)/);
    assert.match(carrier, /\$idleHolderRange\['startOffset'\][\s\S]*?\$idleHolderRange\['length'\]/);
    assert.match(
        responsiveCss,
        /\.rt-train-idle-overlay\s*\{[^}]*height:\s*0 !important;[^}]*max-height:\s*0 !important;[^}]*overflow:\s*hidden !important;/s,
    );
    assert.match(
        responsiveCss,
        /@supports \(animation-name: rt-train-idle-reveal\)\s*\{\s*\.rt-train-idle-overlay\s*\{[^}]*overflow:\s*visible !important;[^}]*animation-delay:\s*13s;/s,
    );
    assert.doesNotMatch(`${carrier}\n${responsiveCss}`, /background(?:-image)?\s*:[^;\r\n]*(?:TRAIN_(?:SRC|IDLE_SRC)|zug-dampf[^;\r\n]*\.gif)/i);
});

test('editor and delivery enlarge the default train progressively while keeping the fixed stage', () => {
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');

    assert.match(signatureView, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signatureView, /position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:125%;max-width:none;height:auto;margin:0 0 0 -12\.5%/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position: center center !important;[\s\S]+?background-size: 100% 100% !important;/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[^}]*width: 100% !important;[^}]*max-width: 1815px !important;/s);
    assert.doesNotMatch(responsiveCss, /\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0 !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]+?width: 150% !important; max-width: none !important; margin-left: 0 !important;/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]+?width: 150% !important; max-width: none !important; margin-left: -25% !important;/);
    const phone = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]+?width: 175% !important;[\s\S]+?margin-left: -8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height:\s*280px !important;[^}]*margin-bottom:\s*-280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]+?width: 175% !important;[\s\S]+?margin-left: -40% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height: 270px !important;[^}]*max-height: 270px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 108\.67% !important;[^}]*margin-left: 0 !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="right"\]\[data-rt-layer-size\][\s\S]+?width: 200% !important; max-width: none !important; margin-left: -100% !important;/);
    assert.match(responsiveCss, /rt-train-idle-reveal/);
});

test('train GIF starts quickly but preserves the 13 second handoff', () => {
    for (const theme of ['light', 'dark']) {
        const resource = read(`resources/mail-templates/assets/zug-dampf-${theme}.gif`);
        const publicCopy = read(`public/mail-assets/zug-dampf-${theme}.gif`);
        const outlook = read(`resources/mail-templates/assets/zug-dampf-outlook-${theme}.gif`);
        const idle = read(`resources/mail-templates/assets/zug-dampf-idle-${theme}.gif`);
        const idlePublic = read(`public/mail-assets/zug-dampf-idle-${theme}.gif`);

        assert.deepEqual(resource, publicCopy);
        for (const bytes of [resource, outlook]) {
            const delays = gifTimeline(bytes);
            assert.equal(delays.length, 72);
            assert.equal(delays[0], 30);
            assert.equal(delays.reduce((sum, delay) => sum + delay, 0), 1300);
            const starts = [];
            delays.reduce((elapsed, delay) => {
                starts.push(elapsed);
                return elapsed + delay;
            }, 0);
            assert.equal(starts[2], 44);
            assert.equal(starts[52], 735);
            assert.equal(starts[53], 764);
            assert.equal(bytes.includes(Buffer.from('NETSCAPE2.0')), false);
        }
        assert.deepEqual(idle, idlePublic);
        const idleDelays = gifTimeline(idle);
        assert.equal(idleDelays.length, 20);
        assert.equal(idleDelays.reduce((sum, delay) => sum + delay, 0), 200);
        assert.equal(idle.includes(Buffer.from('NETSCAPE2.0')), true);
    }
});

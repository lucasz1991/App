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

test('delivered mail keeps legacy overlap contracts plus the V21 flow-safe Classic Outlook IMG', () => {
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
    const flowSafeMsoFallback = msoFallback.slice(
        msoFallback.indexOf('if (self::usesFlowSafeTrain($html))'),
        msoFallback.indexOf('self::assertRuntimeImages($html);'),
    );
    const legacyMsoFallback = msoFallback.slice(msoFallback.indexOf('self::assertRuntimeImages($html);'));

    assert.match(signature, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(signature, /SignatureTrainCarrier::projectAsRuntimeBackground\(/);
    assert.equal((signature.match(/SignatureTrainCarrier::withMsoFallback\(/g) || []).length, 1);
    assert.match(signature, /\$outlookFallbackSource = trim\(\(string\) \([\s\S]+?\$values\['TRAIN_STILL_SRC'\]/);
    assert.match(signature, /zug-dampf-idle-/);
    assert.match(signature, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(signature, /\$tokenizedTrainCarrier = \$this->usesTokenizedTrainCarrier\(\$values, \$layout\);/);
    assert.match(signature, /\$html = \$this->projectPublishedTrainAsImage\(\$html, \$singleTrainLayout\);/);
    assert.match(signature, /SignatureArtifactVersion::usesOptimizedMailAssets\(\$artifactVersion\)/);
    assert.match(signature, /\$values\['TRAIN_IDLE_SRC'\] = '';/);
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
    assert.match(flowSafeMsoFallback, /self::assertFlowSafeRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.match(flowSafeMsoFallback, /rt-sign-train-mso[\s\S]*?width:720px;max-width:100%;height:auto;[\s\S]*?vertical-align:bottom;/);
    assert.match(legacyMsoFallback, /self::assertRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.match(legacyMsoFallback, /\$fallbackStyle = \(\$aspectSafeTrain \|\| \$forwardSafeTrain\)[\s\S]*?'display:inline-block;width:720px;max-width:720px;height:61px;[^']*vertical-align:bottom;'/);
    assert.match(legacyMsoFallback, /<img class="rt-sign-train-mso"[\s\S]*?style="'\.\$fallbackStyle\.'"/);
    assert.doesNotMatch(legacyMsoFallback, /rt-sign-train-mso[\s\S]*?width:720px;max-width:100%/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(carrier, /return '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="'\.\$alignment\.'" data-rt-layer-size="'\.\$sizeName\.'" data-rt-layer-mobile="'\.\$mobileCrop\.'" '/);
    assert.match(carrier, /\$layerPosition = \$failOpenStage \? 'position:relative;z-index:0;' : '';/);
    assert.match(carrier, /'style="'\.\$layerPosition\.'display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:'\.self::layerMargin\(\$alignment\)\.';margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;">'/);
    assert.match(carrier, /\$imageHeight = \(\$forwardSafeTrain \|\| \(\$failOpenStage && ! \$aspectSafeTrain\)\) \? ' height="61"' : '';/);
    assert.match(carrier, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"[^>]*>[\s\S]*?<td class="rt-sign-train-slot" height="200" valign="bottom"[^>]*>[\s\S]*?<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"'\.\$imageHeight\.' alt=""[^>]*style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;[^"\r\n]*vertical-align:bottom;[^"\r\n]*mso-hide:all;/);
    assert.match(carrier, /<table class="rt-sign-content-frame" role="presentation" width="100%" height="200"/);
    assert.match(msoFallback, /substr_replace\(\$html, \$fallback, \$slots\[0\]\['endOffset'\] \+ 1, 0\)/);
    assert.match(previewService, /MailSignature::forCompany\(/);
    assert.match(previewService, /\$signatureRenderer->renderDocument\(/);
    assert.doesNotMatch(previewService, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(builder, /function validateBrowserCopyTrainImages/);
    assert.match(builder, /SignatureTrainCarrier::assertRuntimeImages\(/);
    assert.match(builder, /SignatureArtifactVersion::usesOptimizedMailAssets\(\$artifactVersion\)[\s\S]*?\? 'zug-dampf-v15'[\s\S]*?SignatureArtifactVersion::usesSmokeSafeArrivalTrain\(\$artifactVersion\)[\s\S]*?\? 'zug-dampf-v13'[\s\S]*?SignatureArtifactVersion::usesOptimizedArrivalTrain\(\$artifactVersion\)[\s\S]*?\? 'zug-dampf-v12'/);
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
    const baseRuntimeCss = responsiveCss.slice(
        responsiveCss.indexOf('.rt-sign-stage {'),
        responsiveCss.indexOf('/* V11 bis V13'),
    );
    const legacyResponsiveCss = responsiveCss.slice(0, responsiveCss.indexOf('/* V21:'));
    assert.doesNotMatch(baseRuntimeCss, /\.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;[^}]*bottom:\s*0 !important;[^}]*margin:\s*0 !important;/s);
    assert.match(responsiveCss, /\.rt-sign-train,\s*\.rt-sign-train-mso\s*\{[^}]*position:\s*static !important;[^}]*bottom:\s*auto !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.doesNotMatch(legacyResponsiveCss, /\.rt-sign-train-mso\s*\{[^}]*width:\s*100% !important;[^}]*margin:\s*0 !important;/s);
    assert.match(responsiveCss, /\.rt-train-idle-overlay\s*\{[^}]*top:\s*auto !important;[^}]*bottom:\s*0 !important;[^}]*max-width:\s*none !important;/s);
    assert.match(responsiveCss, /\.rt-train-idle-image\s*\{[^}]*position:\s*absolute !important;[^}]*bottom:\s*0 !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.match(responsiveCss, /prefers-reduced-motion:[^)]+\)[\s\S]*?\.rt-train-idle-overlay/);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*175px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-content-frame\s*\{[^}]*position:\s*relative !important;[^}]*z-index:\s*1 !important;/s);
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
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-content\s*\{\s*padding-top:\s*14px !important;/);
    assert.match(phoneCss, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-logo,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-logo,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-logo,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-logo,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-logo\s*\{\s*padding-bottom:\s*8px !important;/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.doesNotMatch(responsiveCss, /\.rt-sign-cell\.rt-sign-train-background/);
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position:\s*center center !important;[\s\S]+?background-size:\s*100% 100% !important;/);
    assert.doesNotMatch(signatureView, /signatur-(?:raster|marke)-/);
});

test('immutable caching covers V12/V13 plus V15/V17 assets while production URLs stay stable', () => {
    const htaccess = text('public/.htaccess');
    const builder = text('app/Support/EmailTemplateBuilder.php');
    const signature = text('app/Support/MailSignature.php');
    const forCompany = signature.slice(
        signature.indexOf('public static function forCompany'),
        signature.indexOf('public function values'),
    );
    const playbackNonce = signature.slice(
        signature.indexOf('private function withRemotePlaybackNonce'),
        signature.indexOf('protected function companyAsSender'),
    );

    assert.match(htaccess, /zug-dampf-v\(\?:12\|13\|15\|17\)-\(\?:light\|dark\)/);
    assert.match(htaccess, /wortmarke-\(\?:signature-v15-light\|mail-v15-dark\)/);
    assert.match(htaccess, /Cache-Control "public, max-age=31536000, immutable"/);
    assert.doesNotMatch(htaccess, /zug-dampf-v8-/);
    assert.match(builder, /\$stand = is_file\(\$pfad\) \? filemtime\(\$pfad\) : null;/);
    assert.match(builder, /\(\$stand !== null \? '\?v='\.\$stand : ''\)/);
    assert.match(forCompany, /\?string \$playbackNonce = null/);
    assert.match(forCompany, /return new self\(null, \$theme, \$animated, \$playbackNonce, \$remoteAssets, \$staticAssets\);/);
    assert.doesNotMatch(forCompany, /random_bytes|Str::random|uniqid/);
    assert.match(playbackNonce, /\$this->playbackNonce === null/);
    assert.match(playbackNonce, /\$playbackId = substr\(hash\('sha256', \$this->playbackNonce\), 0, 32\);/);
    assert.match(playbackNonce, /return \$url\.\$separator\.'p='\.\$playbackId;/);
});

test('V15 train and wordmark assets are optimized, public and dimensionally stable', () => {
    for (const theme of ['light', 'dark']) {
        const trainGif = read(`resources/mail-templates/assets/zug-dampf-v15-${theme}.gif`);
        const publicTrainGif = read(`public/mail-assets/zug-dampf-v15-${theme}.gif`);
        const trainPng = read(`resources/mail-templates/assets/zug-dampf-v15-${theme}.png`);
        const publicTrainPng = read(`public/mail-assets/zug-dampf-v15-${theme}.png`);

        assert.deepEqual(trainGif, publicTrainGif);
        assert.deepEqual(trainPng, publicTrainPng);
        assert.equal(trainGif.subarray(0, 6).toString('ascii'), 'GIF89a');
        assert.deepEqual([trainGif.readUInt16LE(6), trainGif.readUInt16LE(8)], [2016, 171]);
        assert.deepEqual([trainPng.readUInt32BE(16), trainPng.readUInt32BE(20)], [2016, 171]);
        assert.equal(gifTimeline(trainGif).length, 37);
        assert.equal(gifTimeline(trainGif).reduce((sum, delay) => sum + delay, 0), 1300);
        assert.equal(trainGif.includes(Buffer.from('NETSCAPE2.0')), false);
        assert.ok(trainGif.length <= (theme === 'light' ? 307_738 : 242_630));
        assert.ok(trainPng.length <= (theme === 'light' ? 28_483 : 26_736));
    }

    for (const [stem, maximumGifBytes, maximumPngBytes] of [
        ['wortmarke-signature-v15-light', 150_454, 24_237],
        ['wortmarke-mail-v15-dark', 147_044, 22_484],
    ]) {
        const logoGif = read(`resources/mail-templates/assets/${stem}.gif`);
        const publicLogoGif = read(`public/mail-assets/${stem}.gif`);
        const logoPng = read(`resources/mail-templates/assets/${stem}.png`);
        const publicLogoPng = read(`public/mail-assets/${stem}.png`);

        assert.deepEqual(logoGif, publicLogoGif);
        assert.deepEqual(logoPng, publicLogoPng);
        assert.deepEqual([logoGif.readUInt16LE(6), logoGif.readUInt16LE(8)], [400, 68]);
        assert.deepEqual([logoPng.readUInt32BE(16), logoPng.readUInt32BE(20)], [400, 68]);
        assert.equal(gifTimeline(logoGif).length, 40);
        assert.equal(gifTimeline(logoGif).reduce((sum, delay) => sum + delay, 0), 480);
        assert.equal(logoGif.includes(Buffer.from('NETSCAPE2.0')), false);
        assert.ok(logoGif.length <= maximumGifBytes);
        assert.ok(logoPng.length <= maximumPngBytes);
    }
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
    // Der generische Legacy-Layer bleibt negativ; V21 setzt ihn gezielt zurueck.
    assert.doesNotMatch(responsiveCss, /^\s*\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/ms);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]+?width: 150% !important; max-width: none !important; margin-left: 0 !important;/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]+?width: 150% !important; max-width: none !important; margin-left: -25% !important;/);
    const phone = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]+?width: 175% !important;[\s\S]+?margin-left: -8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height:\s*280px !important;[^}]*margin-bottom:\s*-280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]+?width: 175% !important;[\s\S]+?margin-left: -40% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height: 270px !important;[^}]*max-height: 270px !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 190px !important;[^}]*max-height: 190px !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\s*\{[^}]*height: 175px !important;[^}]*max-height: 175px !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso\s*\{[^}]*width: 94% !important;[^}]*margin-left: 0 !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*max-height: none !important;[^}]*min-height: 175px !important;[^}]*overflow: visible !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\s*\{[^}]*position: relative !important;[^}]*z-index: 0 !important;[^}]*height: 175px !important;[^}]*margin-bottom: -175px !important;/s);
    assert.match(responsiveCss, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 145px !important;[^}]*max-height: 145px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 296px !important;[^}]*max-height: 296px !important;/s);
    assert.match(mobile, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 215px !important;[^}]*max-height: 215px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 296px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 264px !important;[^}]*max-height: 264px !important;/s);
    assert.match(phone, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 190px !important;[^}]*max-height: 190px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 264px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 108\.67% !important;[^}]*margin-left: 0 !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 135% !important;[^}]*margin-left: -15\.75% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 175% !important;[^}]*margin-left: -40% !important;/s);
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

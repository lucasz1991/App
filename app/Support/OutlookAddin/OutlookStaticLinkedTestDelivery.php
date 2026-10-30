<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Explicitly admitted test entries only; publication/admission remains the caller's responsibility. */
final class OutlookStaticLinkedTestDelivery
{
    public const PROFILE = 'static-linked-test-v1';

    public const MARKER = 'data-rt-static';

    private const REVISION = 'outlook-static-linked-test-delivery-v1';

    private const MAX_MEDIA_BYTES = 2097152;

    private const TAG = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';

    /** filename => [source SHA256, bytes, MIME, width, height, compact PNG path, PNG SHA256] */
    private const ASSETS = [
        'icon-rt-v19-light.png' => ['c91d7f93db31784cdce7eed0218904340c9b78e415ab4581d938cd1e01680fad', 1531, 'image/png', 132, 132, '/m/c91d7f93db.png', 'c91d7f93db31784cdce7eed0218904340c9b78e415ab4581d938cd1e01680fad'],
        'icon-rt-v19-dark.png' => ['74ed4f5ff7bf5c00ba8d5b1513c8127336a2f65bf5ee2bb0938512eba580778c', 1442, 'image/png', 132, 132, '/m/74ed4f5ff7.png', '74ed4f5ff7bf5c00ba8d5b1513c8127336a2f65bf5ee2bb0938512eba580778c'],
        'wortmarke-signature-v19-light.png' => ['b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a', 6793, 'image/png', 400, 68, '/m/b983af0049.png', 'b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a'],
        'wortmarke-mail-v19-dark.png' => ['24b9a9b1cdbd035f93e4d27307fde5efe2324ec9a5711c22a62f7ec6b0002b28', 6383, 'image/png', 400, 68, '/m/24b9a9b1cd.png', '24b9a9b1cdbd035f93e4d27307fde5efe2324ec9a5711c22a62f7ec6b0002b28'],
        'contact-location.png' => ['097e33e85eb0425fa5561c29f881f7f6fec5c9efc8f98704b30d8c9d2cc698a7', 1915, 'image/png', 44, 44, '/m/097e33e85e.png', '097e33e85eb0425fa5561c29f881f7f6fec5c9efc8f98704b30d8c9d2cc698a7'],
        'contact-phone.png' => ['741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a', 1851, 'image/png', 44, 44, '/m/741a9d43f5.png', '741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a'],
        'contact-mobile.png' => ['8f6e4932f895cad94c310caa7b88d0ae95c8e71c556b1fe606d0251753cb487f', 826, 'image/png', 44, 44, '/m/8f6e4932f8.png', '8f6e4932f895cad94c310caa7b88d0ae95c8e71c556b1fe606d0251753cb487f'],
        'contact-email.png' => ['cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc', 1556, 'image/png', 44, 44, '/m/cfa8c68211.png', 'cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc'],
        'contact-web.png' => ['09d2f890b7ad37987fc7798e4a4470d9a6dd919f5bf9e2bcded88ba1dedbe71b', 2663, 'image/png', 44, 44, '/m/09d2f890b7.png', '09d2f890b7ad37987fc7798e4a4470d9a6dd919f5bf9e2bcded88ba1dedbe71b'],
        'outlook-hotline-fill-outline-v1.png' => ['a27ccfb5bfa433fb8d4a13401a3ea1ffbf1d77482e0ba1162a041f218548abe3', 76, 'image/png', 1, 34, '/m/a27ccfb5bf.png', 'a27ccfb5bfa433fb8d4a13401a3ea1ffbf1d77482e0ba1162a041f218548abe3'],
        'outlook-hotline-label-outline-v1.png' => ['003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec', 6474, 'image/png', 552, 102, '/m/003919f6fe.png', '003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec'],
        'outlook-hotline-mail-outline-v1.png' => ['dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d', 1684, 'image/png', 126, 102, '/m/dcf209bd17.png', 'dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d'],
        'outlook-hotline-phone-outline-v1.png' => ['e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1', 2924, 'image/png', 126, 102, '/m/e24cb8b0ac.png', 'e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1'],
        'outlook-hotline-fill-v1.png' => ['3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583', 75, 'image/png', 1, 34, '/m/3ee5033b5b.png', '3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583'],
        'outlook-hotline-label-v1.png' => ['c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d', 6485, 'image/png', 411, 102, '/m/c52d2cdb48.png', 'c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d'],
        'outlook-hotline-mail-v1.png' => ['36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d', 2415, 'image/png', 126, 102, '/m/36ecdeda31.png', '36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d'],
        'outlook-hotline-phone-v1.png' => ['a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5', 4360, 'image/png', 126, 102, '/m/a3564d9bc8.png', 'a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5'],
        'zug-dampf-v27-delivery-light.png' => ['e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e', 8435, 'image/png', 1205, 151, '/m/e9f40cff8a.png', 'e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e'],
        'zug-dampf-v27-delivery-light.gif' => ['e0f8327a1041e23bdbbb2a478978e81521efe4e021767e40ad30034ed61dbe53', 225226, 'image/gif', 1205, 151, '/m/e9f40cff8a.png', 'e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e'],
        'zug-dampf-v27-delivery-dark.png' => ['2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb', 4317, 'image/png', 1204, 151, '/m/2c170c402e.png', '2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb'],
        'zug-dampf-v27-delivery-dark.gif' => ['380b43ab43da0c724c492abf8f095917ad06408e7de07aaf33134bf82afb50e9', 151255, 'image/gif', 1204, 151, '/m/2c170c402e.png', '2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb'],
        'zug-dampf-v27-delivery-light-mirrored.png' => ['b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51', 8506, 'image/png', 1205, 151, '/m/b3f813d3f4.png', 'b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51'],
        'zug-dampf-v27-delivery-light-mirrored.gif' => ['52c80c7a8a193472d50346a14a2876a857cce4f1dc98b944dfe98a7fbc94a840', 225702, 'image/gif', 1205, 151, '/m/b3f813d3f4.png', 'b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51'],
        'zug-dampf-v27-delivery-dark-mirrored.png' => ['5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c', 4295, 'image/png', 1204, 151, '/m/5784b3d25f.png', '5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c'],
        'zug-dampf-v27-delivery-dark-mirrored.gif' => ['adb9dbd54e0945c985a96f939867448b787a4990550d8cfefb16fa4c757a20e9', 151273, 'image/gif', 1204, 151, '/m/5784b3d25f.png', '5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c'],
        'zug-dampf-outlook-intrinsic-v1-light.png' => ['78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a', 5967, 'image/png', 695, 151, '/m/78a1fd22c3.png', '78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a'],
        'zug-dampf-outlook-intrinsic-v1-light.gif' => ['45f4109cf7dbeed8c31c4c8a56bfdd9065b947b4b576596ba4bd6e5373b73fb8', 142199, 'image/gif', 695, 151, '/m/78a1fd22c3.png', '78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a'],
        'zug-dampf-outlook-intrinsic-v1-dark.png' => ['b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd', 2918, 'image/png', 695, 151, '/m/b1bc9d62a6.png', 'b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd'],
        'zug-dampf-outlook-intrinsic-v1-dark.gif' => ['822dd45cf0529e41d8e1ed9a082904581d76be4a9f25c39240111e11fd3d3554', 98641, 'image/gif', 695, 151, '/m/b1bc9d62a6.png', 'b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd'],
        'zug-dampf-outlook-intrinsic-v1-light-mirrored.png' => ['dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6', 5983, 'image/png', 695, 151, '/m/dc6eb47779.png', 'dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6'],
        'zug-dampf-outlook-intrinsic-v1-light-mirrored.gif' => ['9db04d54004742501156413430e77ccf1b1ed8515018caa9c7d00caedaf63f17', 142477, 'image/gif', 695, 151, '/m/dc6eb47779.png', 'dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6'],
        'zug-dampf-outlook-intrinsic-v1-dark-mirrored.png' => ['4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78', 2884, 'image/png', 695, 151, '/m/4b6995aa47.png', '4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78'],
        'zug-dampf-outlook-intrinsic-v1-dark-mirrored.gif' => ['18567efab2a728b8058195b8fef1651e97b2aff8c116261af9698afabdaad4d9', 98776, 'image/gif', 695, 151, '/m/4b6995aa47.png', '4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78'],
        'zug-dampf-outlook-brand-v1-light.png' => ['0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258', 7371, 'image/png', 695, 151, '/m/0b9b8d55f6.png', '0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258'],
        'zug-dampf-outlook-brand-v1-light.gif' => ['47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d', 179636, 'image/gif', 695, 151, '/m/0b9b8d55f6.png', '0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258'],
        'zug-dampf-outlook-brand-v1-dark.png' => ['d8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3', 4411, 'image/png', 695, 151, '/m/d8370fc0cc.png', 'd8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3'],
        'zug-dampf-outlook-brand-v1-dark.gif' => ['243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5', 130062, 'image/gif', 695, 151, '/m/d8370fc0cc.png', 'd8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3'],
        'zug-dampf-outlook-brand-v1-light-mirrored.png' => ['b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792', 7572, 'image/png', 695, 151, '/m/b6d051cacd.png', 'b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792'],
        'zug-dampf-outlook-brand-v1-light-mirrored.gif' => ['67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39', 178583, 'image/gif', 695, 151, '/m/b6d051cacd.png', 'b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792'],
        'zug-dampf-outlook-brand-v1-dark-mirrored.png' => ['917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b', 4566, 'image/png', 695, 151, '/m/917e8f055d.png', '917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b'],
        'zug-dampf-outlook-brand-v1-dark-mirrored.gif' => ['ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768', 129369, 'image/gif', 695, 151, '/m/917e8f055d.png', '917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b'],
        'outlook-hotline-label-inktop-v1.png' => ['37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a', 6293, 'image/png', 552, 77, '/m/37683e55f5.png', '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a'],
        'outlook-dispo-card-caption-v1.png' => ['464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7', 6597, 'image/png', 398, 76, '/m/464054a84f.png', '464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7'],
    ];

    /** @return array<string, string> Full hashes bind both the input map and every immutable public PNG. */
    public static function fingerprint(): array
    {
        return self::catalog()['fingerprint'];
    }

    /** Selected, freshly admitted system test-signature preview; never a send or editor preview hook. */
    public static function renderedPreview(string $html, ?string $trustedProfile, string $httpsAppUrl): string
    {
        if ($trustedProfile === null) {
            return $html;
        }
        if ($trustedProfile !== self::PROFILE
            || preg_match('/\A(?:\xEF\xBB\xBF)?\s*<!doctype\s+html\b[^>]*>\s*<html\b/is', $html) !== 1
            || preg_match('~</html\s*>\s*\z~i', $html) !== 1) {
            self::fail();
        }
        self::budget($html, 99000, 24576);
        $origin = self::origin($httpsAppUrl);
        $catalog = self::catalog();
        $resolve = static function (string $source) use ($origin, $catalog): string {
            $prefix = $origin.'/mail-assets/';
            if (! str_starts_with($source, $prefix) || preg_match('/[\\\\%\x00-\x20\x7f]/', $source)) {
                self::fail();
            }
            $parts = explode('?', substr($source, strlen($prefix)), 2);
            $asset = $catalog['files'][$parts[0]] ?? null;
            if ($asset === null || isset($parts[1]) && $parts[1] !== 'v='.$asset['mtime']) {
                self::fail();
            }

            return $origin.$asset['output'];
        };
        // template markers, signature markers, runtime bodies, artifact rows,
        // HTML roots, heads and body elements must each have their exact role.
        $markers = [0, 0, 0, 0, 0, 0, 0];
        $images = 0;
        $projected = self::html($html, $resolve, $markers, $images, systemPreview: true);
        if ($markers !== [0, 1, 1, 1, 1, 1, 1] || $images > 20
            || preg_match('/\b(?:cid|data)\s*:/i', $projected) || str_contains(strtolower($projected), self::MARKER)
            || str_contains(strtolower($projected), 'data-rt-outlook-delivery-profile')) {
            self::fail();
        }
        self::budget($projected, 99000, 24576);

        return $projected;
    }

    /**
     * The nullable profile is supplied from trusted fresh published snapshots,
     * never inferred from a marker/property in this entry. Fresh entries only.
     * Explicit kind=signature supports a selected signature-only preview.
     */
    public static function entry(array $entry, ?string $trustedProfile, string $httpsAppUrl): array
    {
        if ($trustedProfile === null) {
            return $entry;
        }
        if ($trustedProfile !== self::PROFILE) {
            self::fail();
        }
        $origin = self::origin($httpsAppUrl);
        $catalog = self::catalog();
        $salt = self::salt($origin, $catalog['fingerprint']);
        $kind = array_key_exists('kind', $entry) ? $entry['kind'] : 'template';
        if (! in_array($kind, ['template', 'signature'], true)) {
            self::fail();
        }
        if ($kind === 'signature') {
            if (isset($entry['hash']) || ! is_string($entry['version'] ?? null)) {
                self::fail();
            }
            $old = $entry['version'];
            $new = self::mapped('signature', $old, $salt);
            $entry = self::pair($entry, 'html', 'media', $origin, $catalog, [0, 1], $old, $new, null, null, 30000, 12288, true);
            $entry['version'] = $new;

            return $entry;
        }
        if (! is_string($entry['hash'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $entry['hash']) !== 1
            || ! is_string($entry['version'] ?? null) || $entry['version'] !== substr($entry['hash'], 0, 16)
            || ! is_array($entry['signature'] ?? null) || ! is_string($entry['signature']['html'] ?? null) || ! is_string($entry['signatureVersion'] ?? null)) {
            self::fail();
        }
        $oldSignature = $entry['signatureVersion'];
        $newSignature = self::mapped('signature', $oldSignature, $salt);
        // The physical-mobile payload deliberately retains desktop legacy
        // fragments. Their one shared version is separate from the paired
        // mobile signatureVersion; do not relabel that desktop evidence.
        $oldLegacySignature = $oldSignature;
        if (OutlookDesktopFlow::mobile($entry['signature']['html'])) {
            if (! is_string($entry['html'] ?? null)) {
                self::fail();
            }
            $legacyMarkers = self::markerValues($entry['html'], 'RT-SIGNATURE-VERSION:');
            if ($legacyMarkers === [] || count(array_unique($legacyMarkers)) !== 1) {
                self::fail();
            }
            $oldLegacySignature = $legacyMarkers[0];
        }
        $newLegacySignature = self::mapped('signature', $oldLegacySignature, $salt);
        $hasMobile = array_key_exists('mobileComposeHtml', $entry);
        if ($hasMobile !== array_key_exists('mobileComposeVersion', $entry)) {
            self::fail();
        }
        $oldMobile = $hasMobile ? $entry['mobileComposeVersion'] : null;
        if ($hasMobile && ! is_string($oldMobile)) {
            self::fail();
        }
        $newMobile = $hasMobile ? self::mapped('mobile-compose', $oldMobile, $salt) : null;
        foreach (['html' => 'media', 'composeHtml' => 'composeMedia', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $html => $media) {
            if (array_key_exists($html, $entry) !== array_key_exists($media, $entry) || ($html === 'html' && ! array_key_exists($html, $entry))) {
                self::fail();
            }
            if (! array_key_exists($html, $entry)) {
                continue;
            }
            $mobile = $html === 'mobileComposeHtml';
            $entry = self::pair($entry, $html, $media, $origin, $catalog, [1, $html === 'composeHtml' ? 0 : 1],
                $html === 'composeHtml' ? null : ($mobile ? $oldSignature : $oldLegacySignature),
                $html === 'composeHtml' ? null : ($mobile ? $newSignature : $newLegacySignature),
                $mobile ? $oldMobile : null, $mobile ? $newMobile : null, $mobile ? 30000 : 99000, $mobile ? 12288 : 24576);
        }
        if (array_key_exists('hash', $entry['signature'])
            || array_key_exists('version', $entry['signature']) && $entry['signature']['version'] !== $oldSignature) {
            self::fail();
        }
        $entry['signature'] = self::pair($entry['signature'], 'html', 'media', $origin, $catalog, [0, 1], $oldSignature, $newSignature, null, null, 30000, 12288, true);
        if (isset($entry['signature']['version'])) {
            $entry['signature']['version'] = $newSignature;
        }
        $entry['signatureVersion'] = $newSignature;
        if ($hasMobile) {
            $entry['mobileComposeVersion'] = $newMobile;
        }
        $entry['hash'] = self::mapped('template', $entry['hash'], $salt);
        $entry['version'] = substr($entry['hash'], 0, 16);

        return $entry;
    }

    /** Template input is a full 64-character hash; signature/mobile inputs are 16-character versions. */
    public static function version(string $kind, string $prior, string $httpsAppUrl): string
    {
        return self::mapped($kind, $prior, self::salt(self::origin($httpsAppUrl), self::fingerprint()));
    }

    private static function pair(array $pair, string $htmlKey, string $mediaKey, string $origin, array $catalog, array $expectedMarkers,
        ?string $oldSignature, ?string $newSignature, ?string $oldMobile, ?string $newMobile, int $maxCharacters, int $maxCss, bool $nativeSignature = false): array
    {
        if (! is_string($pair[$htmlKey] ?? null) || ! is_array($pair[$mediaKey] ?? null) || ! array_is_list($pair[$mediaKey])) {
            self::fail();
        }
        $html = $pair[$htmlKey];
        self::budget($html, $maxCharacters, $maxCss);
        $media = [];
        $bytes = 0;
        foreach ($pair[$mediaKey] as $record) {
            if (! is_array($record) || ! is_string($record['contentId'] ?? null) || ($record['name'] ?? null) !== $record['contentId']
                || ! is_string($record['base64'] ?? null) || strlen($record['base64']) > 4 * (int) ceil(self::MAX_MEDIA_BYTES / 3)
                || strlen($record['base64']) % 4 !== 0 || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $record['base64']) !== 1) {
                self::fail();
            }
            $cid = $record['contentId'];
            if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._@-]{0,190}[A-Za-z0-9])?\z/D', $cid) !== 1 || isset($media[strtolower($cid)])) {
                self::fail();
            }
            $binary = base64_decode($record['base64'], true);
            if (! is_string($binary) || $binary === '' || base64_encode($binary) !== $record['base64']) {
                self::fail();
            }
            $sha = hash('sha256', $binary);
            $asset = $catalog['sources'][$sha] ?? null;
            if ($asset === null || $cid !== 'railtime-'.substr($sha, 0, 20).'.'.substr($asset['mime'], 6)) {
                self::fail();
            }
            $bytes += strlen($binary);
            $media[strtolower($cid)] = ['cid' => $cid, 'output' => $asset['output'], 'used' => 0];
        }
        if ($bytes > self::MAX_MEDIA_BYTES || count($media) > 20) {
            self::fail();
        }
        $resolve = static function (string $source) use (&$media, $origin): string {
            if (! str_starts_with($source, 'cid:')) {
                self::fail();
            }
            $cid = substr($source, 4);
            $key = strtolower($cid);
            if (! isset($media[$key]) || $media[$key]['cid'] !== $cid) {
                self::fail();
            }
            $media[$key]['used']++;

            return $origin.$media[$key]['output'];
        };
        $markers = [0, 0];
        $images = 0;
        $html = self::html($html, $resolve, $markers, $images);
        if ($markers !== $expectedMarkers || $images > 20 || array_filter($media, static fn (array $record): bool => $record['used'] === 0) !== []
            || preg_match('/\b(?:cid|data)\s*:/i', $html) || str_contains(strtolower($html), self::MARKER)
            || str_contains(strtolower($html), 'data-rt-outlook-delivery-profile')) {
            self::fail();
        }
        $html = self::markers($html, 'RT-SIGNATURE-VERSION:', $oldSignature, $newSignature);
        $html = self::markers($html, OutlookMobileCombinedComposeDocument::VERSION_MARKER, $oldMobile, $newMobile);
        $reserve = $nativeSignature && ! OutlookDesktopFlow::mobile($html)
            ? (str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v15"') ? 168 : (str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v11"') || str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v12"') || str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v13"') || str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v14"') ? 166
                : (str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v10"') ? 855
                    : (str_contains($html, 'data-rt-desktop-flow="outlook-desktop-flow-v9"') ? 798 : 627)))) : 0;
        self::budget($html, $maxCharacters, $maxCss, $reserve);
        $pair[$htmlKey] = $html;
        $pair[$mediaKey] = [];

        return $pair;
    }

    /** Rewrite only URL value ranges and exact owned marker attributes. Everything else stays byte-exact. */
    private static function html(string $html, callable $resolve, array &$markers, int &$images, bool $conditional = false, bool $systemPreview = false): string
    {
        preg_match_all('~<!--.*?-->|(<style\b'.self::TAG.'>)(.*?)(</style\s*>)|</?[a-z][a-z0-9:-]*'.self::TAG.'>~is', $html, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $edits = [];
        $stack = [];
        $quoteTail = false;
        foreach ($tokens as $token) {
            [$opening, $offset] = $token[0];
            if (str_starts_with($opening, '<!--')) {
                if (preg_match('~\A(<!--\s*\[if\b[^\]]*\bmso\b[^\]]*\]>)(.*?)(<!\s*\[endif\]\s*-->)\z~is', $opening, $mso)) {
                    $rewritten = $mso[1].self::html($mso[2], $resolve, $markers, $images, true, $systemPreview).$mso[3];
                    $edits[] = [$offset, strlen($opening), $rewritten];
                }

                continue;
            }
            if (($token[1][0] ?? null) !== null) {
                [$css] = self::css($token[2][0], $resolve);
                $edits[] = [$token[2][1], strlen($token[2][0]), $css];

                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $opening, $name);
            $tag = strtolower($name[2]);
            if ($name[1] === '/') {
                $parent = array_pop($stack);
                if ($parent === null || $parent['tag'] !== $tag) {
                    self::fail();
                }

                continue;
            }
            $attrs = self::attributes($opening);
            $classes = preg_split('/\s+/', trim($attrs['class']['value'] ?? ''));
            $parent = $stack === [] ? ['template' => false, 'signature' => false, 'quoted' => false, 'system' => false] : end($stack);
            $quoteTail = $quoteTail || in_array(strtolower($attrs['id']['value'] ?? ''), ['divrplyfwdmsg', 'x_divrplyfwdmsg', 'mail-editor-reference-message-container'], true)
                || strtolower($attrs['name']['value'] ?? '') === '_mailoriginal';
            $quoted = $quoteTail || $parent['quoted'] || $tag === 'blockquote' || array_intersect($classes, ['gmail_quote', 'yahoo_quoted', 'moz-cite-prefix']) !== [];
            $template = $parent['template'] || ($tag === 'div' && array_intersect($classes, ['rt-outlook-template', 'x8']) !== []);
            $signature = $parent['signature'] || ($tag === 'div' && array_intersect($classes, ['rt-outlook-signature', 'xz']) !== []);
            $system = $parent['system'];
            if ($systemPreview) {
                if (array_intersect($classes, ['design-personal-columns', 'rt-personal-columns', 'rt-mobile-hybrid-v2']) !== []) {
                    self::fail();
                }
                if (in_array($tag, ['html', 'head', 'body'], true)) {
                    if ($conditional) {
                        self::fail();
                    }
                    $markers[array_search($tag, ['html', 'head', 'body'], true) + 4]++;
                }
                if (isset($attrs['data-rt-system-mail-inline-assets'])) {
                    if ($tag !== 'body' || $conditional || $quoted || $attrs['data-rt-system-mail-inline-assets']['raw'] !== '1') {
                        self::fail();
                    }
                    $system = true;
                    $markers[2]++;
                }
                if (isset($attrs['data-rt-artifact-version'])) {
                    if ($tag !== 'tr' || ! $system || $quoted || $conditional
                        || $attrs['data-rt-artifact-version']['value'] !== 'v27' || ! in_array('rt-system-signature-v27', $classes, true)) {
                        self::fail();
                    }
                    $markers[3]++;
                }
            }
            $local = [];
            if (isset($attrs['data-rt-outlook-delivery-profile'])) {
                self::fail();
            }
            if (isset($attrs[self::MARKER])) {
                if ($conditional || $quoted || $attrs[self::MARKER]['raw'] !== 'v1') {
                    self::fail();
                }
                if ($systemPreview) {
                    if ($tag !== 'tr' || ! $system || ($attrs['data-rt-artifact-version']['value'] ?? '') !== 'v27'
                        || ! in_array('rt-system-signature-v27', $classes, true)) {
                        self::fail();
                    }
                    $markers[1]++;
                } elseif ($tag === 'table' && $template && array_intersect($classes, ['rt-shell', 'x1']) !== []
                    && array_intersect($classes, ['design-v27', 'x9']) !== []) {
                    $markers[0]++;
                } elseif ($tag === 'tr' && ($signature || $template) && ($attrs['data-rt-artifact-version']['value'] ?? '') === 'v27') {
                    $markers[1]++;
                } else {
                    self::fail();
                }
                $local[] = [$attrs[self::MARKER]['start'], $attrs[self::MARKER]['length'], ''];
            }
            if (in_array($tag, ['script', 'iframe', 'svg', 'picture', 'source', 'object', 'embed', 'video', 'audio', 'canvas'], true)
                || isset($attrs['srcset']) || isset($attrs['sizes']) || isset($attrs['poster']) || isset($attrs['srcdoc'])) {
                self::fail();
            }
            if ($tag === 'img') {
                $heightAuto = $systemPreview && ! isset($attrs['height'])
                    && preg_match('/(?:\A|;)\s*height\s*:\s*auto\s*(?:!important\s*)?(?:;|\z)/i', $attrs['style']['value'] ?? '') === 1;
                if (! isset($attrs['src'], $attrs['width'], $attrs['alt']) || isset($attrs['usemap'])
                    || preg_match('/\A(?:[1-9][0-9]{0,3}|100%)\z/D', $attrs['width']['value']) !== 1
                    || ! $heightAuto && preg_match('/\A[1-9][0-9]{0,3}\z/D', $attrs['height']['value'] ?? '') !== 1) {
                    self::fail();
                }
                $images++;
                $local[] = [$attrs['src']['valueStart'], $attrs['src']['valueLength'], $resolve($attrs['src']['value'])];
            } elseif (isset($attrs['src'])) {
                self::fail();
            }
            if (isset($attrs['background'])) {
                $local[] = [$attrs['background']['valueStart'], $attrs['background']['valueLength'], $resolve($attrs['background']['value'])];
            }
            if (isset($attrs['style'])) {
                [, $cssEdits] = self::css($attrs['style']['value'], $resolve);
                if ($cssEdits !== []) {
                    $raw = self::decodedEdits($attrs['style']['raw'], $attrs['style']['value'], $cssEdits);
                    $local[] = [$attrs['style']['valueStart'], $attrs['style']['valueLength'], $raw];
                }
            }
            if ($local !== []) {
                $edits[] = [$offset, strlen($opening), self::replace($opening, $local)];
            }
            if (! in_array($tag, ['area', 'base', 'br', 'col', 'hr', 'img', 'input', 'link', 'meta', 'param', 'track', 'wbr'], true)) {
                $stack[] = ['tag' => $tag, 'template' => $template, 'signature' => $signature, 'quoted' => $quoted, 'system' => $system];
            }
        }
        if ($stack !== []) {
            self::fail();
        }

        return self::replace($html, $edits);
    }

    /** @return array{string, list<array{int,int,string}>} */
    private static function css(string $css, callable $resolve): array
    {
        if (str_contains($css, '\\') || preg_match('/@import\b|\b(?:image-set|image|cross-fade|paint|element|var|attr)\s*\(/i', preg_replace('~/\*.*?\*/|"[^"]*"|\'[^\']*\'~s', '', $css))) {
            self::fail();
        }
        preg_match_all('~/\*.*?\*/|"[^"\\\\]*"|\'[^\'\\\\]*\'|(?<![-\w])url\(\s*(?:(["\'])(.*?)\1|([^)]*?))\s*\)~is', $css, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $edits = [];
        foreach ($tokens as $token) {
            if (! preg_match('/\Aurl\(/i', $token[0][0])) {
                continue;
            }
            [$source, $start] = ($token[2][0] ?? null) !== null ? $token[2] : $token[3];
            $trimmed = trim($source);
            $start += strlen($source) - strlen(ltrim($source));
            $edits[] = [$start, strlen($trimmed), $resolve($trimmed)];
        }

        return [self::replace($css, $edits), $edits];
    }

    /** Map decoded CSS URL offsets back to their original entity-encoded attribute ranges. */
    private static function decodedEdits(string $raw, string $decoded, array $edits): string
    {
        $map = [0 => 0];
        $built = '';
        for ($offset = 0; $offset < strlen($raw);) {
            $length = 1;
            if ($raw[$offset] === '&' && preg_match('/\G&(?:#(?:[xX][0-9A-Fa-f]+|[0-9]+);?|[A-Za-z][A-Za-z0-9]+;)/', $raw, $entity, 0, $offset)) {
                $length = strlen($entity[0]);
            }
            $piece = CssSemantic::decodeHtmlEntitiesOnce(substr($raw, $offset, $length));
            $built .= $piece;
            $offset += $length;
            $map[strlen($built)] = $offset;
        }
        if ($built !== $decoded) {
            self::fail();
        }
        $rawEdits = [];
        foreach ($edits as [$start, $length, $replacement]) {
            if (! isset($map[$start], $map[$start + $length])) {
                self::fail();
            }
            $rawEdits[] = [$map[$start], $map[$start + $length] - $map[$start], $replacement];
        }

        return self::replace($raw, $rawEdits);
    }

    private static function attributes(string $opening): array
    {
        preg_match('~\A<[a-z][a-z0-9:-]*\b~i', $opening, $tag);
        $cursor = strlen($tag[0]);
        $attrs = [];
        while ($cursor < strlen($opening)) {
            if (preg_match('~\G\s*/?>\z~', $opening, $end, 0, $cursor)) {
                break;
            }
            if (! preg_match('~\G\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $opening, $attribute, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $cursor)) {
                self::fail();
            }
            $key = strtolower($attribute[1][0]);
            if (isset($attrs[$key])) {
                self::fail();
            }
            $value = [null, -1];
            foreach ([2, 3, 4] as $index) {
                if (($attribute[$index][0] ?? null) !== null) {
                    $value = $attribute[$index];
                    break;
                }
            }
            $attrs[$key] = ['value' => CssSemantic::decodeHtmlEntitiesOnce($value[0] ?? ''), 'raw' => $value[0] ?? '',
                'start' => $cursor, 'length' => strlen($attribute[0][0]), 'valueStart' => $value[1], 'valueLength' => strlen($value[0] ?? '')];
            $cursor += strlen($attribute[0][0]);
        }

        return $attrs;
    }

    private static function markers(string $html, string $prefix, ?string $prior, ?string $next): string
    {
        $markers = self::markerValues($html, $prefix);
        if ($prior === null ? $markers !== [] : $markers === []) {
            self::fail();
        }
        foreach ($markers as $value) {
            if ($value !== $prior) {
                self::fail();
            }
        }

        return $prior === null ? $html : str_replace($prefix.$prior, $prefix.$next, $html);
    }

    private static function markerValues(string $html, string $prefix): array
    {
        preg_match_all('/'.preg_quote($prefix, '/').'([0-9a-f]{16})(?=\s*(?:-->|<\/)|\z)/D', $html, $markers);
        if (substr_count($html, $prefix) !== count($markers[0])) {
            self::fail();
        }

        return $markers[1];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL) || strtolower($parts['scheme'] ?? '') !== 'https'
            || preg_match('/[\\\\%\x00-\x20\x7f]/', $url) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/iD', $parts['host'] ?? '') !== 1
            || str_contains($parts['host'], '..')) {
            self::fail();
        }
        $port = $parts['port'] ?? 443;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            self::fail();
        }

        return 'https://'.strtolower($parts['host']).($port === 443 ? '' : ':'.$port);
    }

    private static function mapped(string $kind, string $prior, string $salt): string
    {
        $width = match ($kind) {
            'signature', 'mobile-compose' => 16, 'template' => 64, default => 0
        };
        if ($width === 0 || preg_match('/\A[0-9a-f]{'.$width.'}\z/D', $prior) !== 1) {
            self::fail();
        }

        return substr(hash('sha256', $salt."\0".$kind."\0".$prior), 0, $width);
    }

    private static function salt(string $origin, array $fingerprint): string
    {
        return self::REVISION."\0".self::PROFILE."\0".OutlookClientPresentation::REVISION."\0".$origin."\0".json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function catalog(): array
    {
        $public = realpath(dirname(__DIR__, 3).'/public');
        $roots = ['source' => $public === false ? false : realpath($public.'/mail-assets'), 'output' => $public === false ? false : realpath($public.'/m')];
        foreach ($roots as $kind => $root) {
            $expected = $kind === 'source' ? 'mail-assets' : 'm';
            if ($public === false || $root === false || dirname($root) !== $public || basename($root) !== $expected
                || is_link($public.DIRECTORY_SEPARATOR.$expected)) {
                self::fail();
            }
        }
        $sources = $targets = $fingerprint = $pngBytes = $files = [];
        foreach (self::ASSETS as [$sha, $bytes, $mime]) {
            if ($mime === 'image/png') {
                $pngBytes[$sha] = $bytes;
            }
        }
        foreach (self::ASSETS as $filename => [$sha, $bytes, $mime, $width, $height, $output, $pngSha]) {
            $source = $roots['source'].DIRECTORY_SEPARATOR.$filename;
            self::file($source, $roots['source'], $sha, $bytes, $mime, $width, $height);
            if (isset($sources[$sha]) || $output !== '/m/'.substr($pngSha, 0, 10).'.png' || isset($targets[$output]) && $targets[$output] !== $pngSha) {
                self::fail();
            }
            $target = $roots['output'].DIRECTORY_SEPARATOR.basename($output);
            self::file($target, $roots['output'], $pngSha, $pngBytes[$pngSha] ?? 0, 'image/png', $width, $height);
            $sources[$sha] = ['mime' => $mime, 'output' => $output];
            $mtime = filemtime($source);
            if ($mtime === false) {
                self::fail();
            }
            $files[$filename] = ['output' => $output, 'mtime' => (string) $mtime];
            $targets[$output] = $pngSha;
            $fingerprint['map:'.$sha] = $pngSha;
            $fingerprint[$output] = $pngSha;
        }
        if (count($sources) !== 43 || count($targets) !== 31) {
            self::fail();
        }
        ksort($fingerprint);

        return ['sources' => $sources, 'fingerprint' => $fingerprint, 'files' => $files];
    }

    private static function file(string $path, string $root, string $sha, int $bytes, string $mime, int $width, int $height): void
    {
        if ($bytes < 1 || $bytes > self::MAX_MEDIA_BYTES || is_link($path) || ! is_file($path) || ! is_readable($path)
            || dirname((string) realpath($path)) !== $root || filesize($path) !== $bytes || ($binary = @file_get_contents($path)) === false
            || ! hash_equals($sha, hash('sha256', $binary)) || ($image = @getimagesizefromstring($binary)) === false
            || [$image[0], $image[1]] !== [$width, $height] || ($image['mime'] ?? '') !== $mime) {
            self::fail();
        }
        if ($mime === 'image/png') {
            $cursor = 8;
            while ($cursor + 12 <= strlen($binary)) {
                $length = unpack('N', substr($binary, $cursor, 4))[1];
                $type = substr($binary, $cursor + 4, 4);
                if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true) || $cursor + $length + 12 > strlen($binary)) {
                    self::fail();
                }
                $cursor += $length + 12;
                if ($type === 'IEND') {
                    break;
                }
            }
            if ($cursor !== strlen($binary)) {
                self::fail();
            }
        }
    }

    private static function budget(string $html, int $characters, int $cssBytes, int $reserve = 0): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (! mb_check_encoding($html, 'UTF-8') || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + $reserve > $characters
            || array_sum(array_map('strlen', $styles[1])) >= $cssBytes) {
            self::fail();
        }
    }

    private static function replace(string $source, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $edge = strlen($source) + 1;
        foreach ($edits as [$start, $length, $replacement]) {
            if ($start < 0 || $start + $length > $edge) {
                self::fail();
            }
            $source = substr_replace($source, $replacement, $start, $length);
            $edge = $start;
        }

        return $source;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Der statisch verlinkte Outlook-Test besitzt keinen gueltigen Profil-/Medienvertrag.');
    }
}

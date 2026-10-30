<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use RuntimeException;

/** Strict server-linked PNGs and the exact published, animated train variants. */
final class OutlookLinkedAnimatedDelivery
{
    public const PROFILE = 'linked-animated-v1';

    public const MARKER = 'data-rt-linked';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    /** Admitted PNG poster => [immutable GIF filename, complete GIF SHA256]. */
    private const TRAINS = [
        '/m/0b9b8d55f6.png' => ['zug-dampf-outlook-brand-v1-light.gif', '47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d'],
        '/m/d8370fc0cc.png' => ['zug-dampf-outlook-brand-v1-dark.gif', '243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5'],
        '/m/b6d051cacd.png' => ['zug-dampf-outlook-brand-v1-light-mirrored.gif', '67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39'],
        '/m/917e8f055d.png' => ['zug-dampf-outlook-brand-v1-dark-mirrored.gif', 'ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768'],
    ];

    public static function fingerprint(): array
    {
        // The existing immutable catalog already verifies every full GIF byte,
        // MIME type, dimension and source directory, in addition to all PNGs.
        $fingerprint = OutlookStaticLinkedTestDelivery::fingerprint();
        foreach (self::TRAINS as $poster => [$file, $sha]) {
            if (! isset($fingerprint['map:'.$sha], $fingerprint[$poster])) {
                self::fail();
            }
            $fingerprint['animated:/mail-assets/'.$file] = $sha;
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    public static function entry(array $entry, ?string $trustedProfile, string $httpsAppUrl): array
    {
        if ($trustedProfile === null) {
            return $entry;
        }
        if ($trustedProfile !== self::PROFILE) {
            self::fail();
        }
        foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
            if (isset($entry[$field])) {
                $entry[$field] = self::markers($entry[$field]);
            }
        }
        if (isset($entry['signature']['html'])) {
            $entry['signature']['html'] = self::markers($entry['signature']['html']);
        }
        // Delegate complete CID/media/URL/marker/budget validation, retaining
        // the historical static projector without changing its PNG mapping.
        $result = OutlookStaticLinkedTestDelivery::entry($entry, OutlookStaticLinkedTestDelivery::PROFILE, $httpsAppUrl);
        $salt = self::salt($httpsAppUrl);
        foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = self::animated($result[$field], $httpsAppUrl, $salt);
                self::budget($result[$field], $field === 'mobileComposeHtml' || ($result['kind'] ?? '') === 'signature' ? 30000 : 99000);
            }
        }
        if (isset($result['signature']['html'])) {
            $result['signature']['html'] = self::animated($result['signature']['html'], $httpsAppUrl, $salt);
            self::budget($result['signature']['html'], 30000, 168);
            $result['signatureVersion'] = self::mapped('signature', $result['signatureVersion'], $salt);
            if (isset($result['signature']['version'])) {
                $result['signature']['version'] = $result['signatureVersion'];
            }
        }
        if (($result['kind'] ?? '') === 'signature') {
            $result['version'] = self::mapped('signature', $result['version'], $salt);
            self::budget($result['html'], 30000, 168);
        } else {
            $result['hash'] = self::mapped('template', $result['hash'], $salt);
            $result['version'] = substr($result['hash'], 0, 16);
            if (isset($result['mobileComposeVersion'])) {
                $result['mobileComposeVersion'] = self::mapped('mobile-compose', $result['mobileComposeVersion'], $salt);
            }
        }

        return $result;
    }

    public static function renderedPreview(string $html, ?string $trustedProfile, string $httpsAppUrl): string
    {
        if ($trustedProfile === null) {
            return $html;
        }
        if ($trustedProfile !== self::PROFILE) {
            self::fail();
        }
        $result = OutlookStaticLinkedTestDelivery::renderedPreview(self::markers($html, true), OutlookStaticLinkedTestDelivery::PROFILE, $httpsAppUrl);
        $result = self::animated($result, $httpsAppUrl, self::salt($httpsAppUrl), false);
        self::budget($result, 99000);

        return $result;
    }

    /** Explicit published testmail is also a linked delivery, never a CID send. */
    public static function withoutRuntimeMarker(string $html): string
    {
        $removed = 0;
        $result = preg_replace_callback('~<body\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', static function (array $match) use (&$removed): string {
            return preg_replace('~\s+data-rt-system-mail-inline-assets\s*=\s*(?:"1"|\'1\'|1(?=\s|>))~i', '', $match[0], 1, $removed) ?? $match[0];
        }, $html, -1, $bodies);
        if (! is_string($result) || $bodies !== 1 || $removed !== 1) {
            self::fail();
        }

        return $result;
    }

    public static function version(string $kind, string $prior, string $httpsAppUrl): string
    {
        return self::mapped($kind, OutlookStaticLinkedTestDelivery::version($kind, $prior, $httpsAppUrl), self::salt($httpsAppUrl));
    }

    private static function mapped(string $kind, string $prior, string $salt): string
    {
        $width = $kind === 'template' ? 64 : (in_array($kind, ['signature', 'mobile-compose'], true) ? 16 : 0);
        if ($width === 0 || preg_match('/\A[0-9a-f]{'.$width.'}\z/D', $prior) !== 1) {
            self::fail();
        }

        return substr(hash('sha256', $salt."\0".$kind."\0".$prior), 0, $width);
    }

    private static function salt(string $httpsAppUrl): string
    {
        return self::PROFILE."\0".OutlookClientPresentation::REVISION."\0".self::origin($httpsAppUrl)."\0".json_encode(self::fingerprint(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            self::fail();
        }
        $port = $parts['port'] ?? 443;

        return 'https://'.strtolower($parts['host']).($port === 443 ? '' : ':'.$port);
    }

    /** Adapt only the separately admitted new marker to the immutable validator. */
    private static function markers(string $html, bool $system = false): string
    {
        return preg_replace_callback(self::TOKENS, static function (array $match) use ($system): string {
            $tag = $match[0];
            if (! preg_match('~\A<[a-z]~i', $tag)) {
                return $tag;
            }
            if (preg_match('~\sdata-rt-static(?:\s|=|>)~i', $tag)) {
                self::fail();
            }
            if (! preg_match('~\sdata-rt-linked(?:\s|=|>)~i', $tag)) {
                return $tag;
            }
            if (preg_match_all('~\sdata-rt-linked\s*=\s*(?:"animated-v1"|\'animated-v1\'|animated-v1(?=\s|>))(?=\s|>)~i', $tag, $marker) !== 1) {
                self::fail();
            }
            if ($system && preg_match('~\A<table\b~i', $tag)
                && preg_match('~\sclass="([^"]*)"~', $tag, $class)) {
                $classes = preg_split('/\s+/', trim($class[1]));
                if (in_array('rt-shell', $classes, true) && in_array('design-v27', $classes, true)) {
                    return str_replace($marker[0][0], '', $tag);
                }
            }

            return str_replace($marker[0][0], ' data-rt-static="v1"', $tag);
        }, $html);
    }

    private static function animated(string $html, string $httpsAppUrl, string $salt, bool $mapVersions = true): string
    {
        $origin = self::origin($httpsAppUrl);
        $trains = 0;
        $result = preg_replace_callback(self::TOKENS, static function (array $match) use ($origin, &$trains): string {
            $tag = $match[0];
            if (! preg_match('~\A<img\b~i', $tag) || ! preg_match('~\sclass="([^"]*)"~', $tag, $classes)
                || ! in_array('rt-v15-train-image', preg_split('/\s+/', trim($classes[1])), true)) {
                return $tag;
            }
            if (! preg_match('~\ssrc="([^"]*)"~', $tag, $source)) {
                self::fail();
            }
            $asset = self::TRAINS[substr($source[1], strlen($origin))] ?? null;
            if (! str_starts_with($source[1], $origin.'/m/') || $asset === null) {
                self::fail();
            }
            $trains++;

            return str_replace($source[0], ' src="'.$origin.'/mail-assets/'.$asset[0].'"', $tag);
        }, $html);
        $flow = str_contains($html, 'data-rt-desktop-flow="'.OutlookAnimatedSignaturePresentation::FLOW.'"');
        if (($flow && $trains !== 1) || (! $flow && $trains !== 0) || preg_match('/\b(?:cid|data)\s*:/i', $result)) {
            self::fail();
        }
        if (! $mapVersions) {
            return $result;
        }
        foreach (['RT-SIGNATURE-VERSION:' => 'signature', 'RT-MOBILE-COMPOSE-VERSION:' => 'mobile-compose'] as $prefix => $kind) {
            $result = preg_replace_callback('/'.preg_quote($prefix, '/').'([0-9a-f]{16})/', static fn (array $match): string => $prefix.self::mapped($kind, $match[1], $salt), $result);
        }

        return $result;
    }

    private static function budget(string $html, int $limit, int $reserve = 0): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + $reserve > $limit
            || strlen(implode('', $styles[1])) >= ($limit === 30000 ? 12288 : 24576)) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die verlinkte animierte Ausgabe besitzt keinen eindeutigen Profil-/Medienvertrag.');
    }
}

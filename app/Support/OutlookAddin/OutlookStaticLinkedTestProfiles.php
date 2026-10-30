<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Request-fresh admission for explicitly published, paired static test entries. */
final class OutlookStaticLinkedTestProfiles
{
    private const TOKENS = '~<!--.*?-->|<(?:style|script)\b[^>]*>.*?</(?:style|script)\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function payload(array $payload, User $user): array
    {
        try {
            $payloads = app(OutlookAddinPayloadService::class);
            $before = $payloads->sourceFingerprint(self::sourceUser($user));
            if (! is_string($before) || preg_match('/\A[0-9a-f]{64}\z/', $before) !== 1
                || ! is_string($payload['version']['personal'] ?? null)
                || ! hash_equals(substr($before, 0, 16), $payload['version']['personal'])) {
                self::changed();
            }
            $documents = app(PublishedMailDocumentSnapshotStore::class);
            $published = $documents->freshTemplateSnapshots();
            $sources = [];
            foreach ($published as $snapshot) {
                $id = $snapshot['id'] ?? null;
                if (! is_string($id) || ! Str::isUuid($id) || isset($sources[$id]) || ! is_string($snapshot['html'] ?? null)) {
                    self::invalid();
                }
                $sources[$id] = $snapshot;
            }
            if (! is_array($payload['templates'] ?? null) || ! array_is_list($payload['templates'])) {
                self::invalid();
            }
            $result = $payload;
            if (($profile = self::signatureProfile($documents->outlookSignatureSnapshot())) !== null) {
                $signature = self::projectEntry([
                    'kind' => 'signature',
                    'html' => $payload['signature']['html'] ?? null,
                    'media' => $payload['signature']['media'] ?? null,
                    'version' => $payload['version']['signature'] ?? null,
                ], $profile);
                $result['signature']['html'] = $signature['html'];
                $result['signature']['media'] = $signature['media'];
                $result['version']['signature'] = $signature['version'];
            }
            $seen = [];
            $active = [];
            $admission = [];
            foreach ($payload['templates'] as $index => $entry) {
                $id = $entry['id'] ?? null;
                if (! is_array($entry) || ! is_string($id) || ! isset($sources[$id]) || isset($seen[$id])) {
                    self::invalid();
                }
                $seen[$id] = true;
                if (($entry['active'] ?? false) === true) {
                    $active[] = $index;
                }
                try {
                    $admission[$id] = self::admission($sources[$id]);
                    if (! $admission[$id]['marked']) {
                        continue;
                    }
                    if (($entry['signatureDocumentId'] ?? null) !== $admission[$id]['signatureId']) {
                        self::invalid();
                    }
                    $result['templates'][$index] = self::projectEntry($entry, $admission[$id]['profile']);
                } catch (RuntimeException $error) {
                    if ($error instanceof OutlookAddinException && $error->errorCode !== 'outlook_addin_static_profile_invalid') {
                        throw $error;
                    }
                    // An unavailable offered test must not disable the standard
                    // signature. A selected/default or aliased entry still fails.
                    if (($payload['automaticTemplateId'] ?? null) === $id || ($entry['isDefault'] ?? false) === true || ($entry['active'] ?? false) === true) {
                        self::invalid($error);
                    }
                    unset($result['templates'][$index]);
                }
            }
            if (count($active) > 1) {
                self::invalid();
            }
            if ($active !== [] && $admission[$payload['templates'][$active[0]]['id']]['marked']) {
                $old = $payload['templates'][$active[0]];
                $new = $result['templates'][$active[0]];
                if (! is_array($payload['template'] ?? null)
                    || ($payload['template']['html'] ?? null) !== ($old['html'] ?? null)
                    || ($payload['template']['media'] ?? null) !== ($old['media'] ?? null)
                    || ($payload['version']['template'] ?? null) !== ($old['version'] ?? null)) {
                    self::invalid();
                }
                $result['template']['html'] = $new['html'];
                $result['template']['media'] = $new['media'];
                $result['version']['template'] = $new['version'];
            }
            $result['templates'] = array_values($result['templates']);
            $after = $payloads->sourceFingerprint(self::sourceUser($user));
            if (! is_string($after) || ! hash_equals($before, $after)) {
                self::changed();
            }

            return $result;
        } catch (OutlookAddinException $error) {
            throw $error;
        } catch (Throwable $error) {
            self::invalid($error);
        }
    }

    /** The caller supplies an effective published source snapshot, never rendered/request HTML. */
    public static function signatureProfile(?array $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }
        if (! is_string($snapshot['html'] ?? null)) {
            self::invalid();
        }

        return self::profile($snapshot['html'], true);
    }

    /** Reuse the exact admission contract for read-only candidate budget validation. */
    public static function templateProfile(array $snapshot): ?string
    {
        return self::admission($snapshot)['profile'];
    }

    /** Parse only entries actually offered in this response, including their exact published partner. */
    private static function admission(array $snapshot): array
    {
        $templateProfile = self::profile($snapshot['html'], false);
        $signature = $snapshot['publishedSignature'] ?? null;
        $signatureProfile = is_array($signature) && is_string($signature['html'] ?? null) ? self::profile($signature['html'], true) : null;
        $templateMarked = $templateProfile !== null;
        if ($templateProfile !== $signatureProfile || ($templateMarked
            && (($signature['paired'] ?? false) !== true || ! is_string($signature['id'] ?? null)
                || ! Str::isUuid($signature['id'])))) {
            self::invalid();
        }

        return ['marked' => $templateMarked, 'profile' => $templateProfile, 'signatureId' => $signature['id'] ?? null];
    }

    public static function renderedPreview(string $html, ?string $trustedProfile, string $httpsAppUrl): string
    {
        if ($trustedProfile === null) {
            return $html;
        }

        return self::delivery($trustedProfile)::renderedPreview($html, $trustedProfile, $httpsAppUrl);
    }

    private static function projectEntry(array $entry, string $profile): array
    {
        return self::delivery($profile)::entry($entry, $profile, (string) config('app.url'));
    }

    private static function delivery(string $profile): string
    {
        return match ($profile) {
            OutlookStaticLinkedTestDelivery::PROFILE => OutlookStaticLinkedTestDelivery::class,
            OutlookLinkedAnimatedDelivery::PROFILE => OutlookLinkedAnimatedDelivery::class,
            default => self::invalid(),
        };
    }

    private static function profile(string $html, bool $signature): ?string
    {
        $static = self::marked($html, $signature);
        $animated = self::marked($html, $signature, 'data-rt-linked', 'animated-v1');
        if ($static && $animated) {
            self::invalid();
        }

        return $animated ? OutlookLinkedAnimatedDelivery::PROFILE : ($static ? OutlookStaticLinkedTestDelivery::PROFILE : null);
    }

    /** A relation loaded by authentication must not hide a concurrent profile change. */
    private static function sourceUser(User $user): User
    {
        if (! $user->exists) {
            return $user->withoutRelations();
        }

        return $user->fresh('profile') ?? self::changed();
    }

    /** Inspect published source attributes without decoding entities or losing duplicates. */
    private static function marked(string $html, bool $signature, string $attribute = 'data-rt-static', string $value = 'v1'): bool
    {
        preg_match_all(self::TOKENS, $html, $matches);
        $markers = [];
        $targets = [];
        $firstRow = null;
        $quotes = 0;
        $ambiguous = false;
        foreach ($matches[0] as $index => $tag) {
            if (str_starts_with($tag, '<!--') || preg_match('~\A<(?:style|script)\b~i', $tag)) {
                continue;
            }
            if (preg_match('~\A</blockquote\s*>~i', $tag)) {
                $quotes--;

                continue;
            }
            if (! preg_match('~\A<([a-z][a-z0-9:-]*)\b~i', $tag, $name)) {
                continue;
            }
            $name = strtolower($name[1]);
            $quotes += (int) ($name === 'blockquote');
            $attributes = [];
            preg_match_all('~\s+([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', substr($tag, strlen($name) + 1, -1), $pairs, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($pairs as $pair) {
                $key = strtolower($pair[1]);
                if (array_key_exists($key, $attributes) && in_array($key, ['class', $attribute, 'data-rt-artifact-version'], true)) {
                    $ambiguous = true;
                }
                $attributes[$key] = $pair[2] ?? $pair[3] ?? $pair[4] ?? null;
            }
            $classes = preg_split('/\s+/', trim((string) ($attributes['class'] ?? '')));
            if ($name === 'tr' && $firstRow === null) {
                $firstRow = $index;
            }
            if ($signature ? $name === 'tr' && ($attributes['data-rt-artifact-version'] ?? null) === 'v27'
                : $name === 'table' && in_array('rt-shell', $classes, true) && in_array('design-v27', $classes, true)) {
                $targets[] = $index;
            }
            if (array_key_exists($attribute, $attributes)) {
                if ($attributes[$attribute] !== $value || $quotes !== 0) {
                    self::invalid();
                }
                $markers[] = $index;
            }
        }
        if ($markers === []) {
            return false;
        }
        if ($ambiguous || count($markers) !== 1 || count($targets) !== 1 || $markers[0] !== $targets[0]
            || ($signature && $markers[0] !== $firstRow)) {
            self::invalid();
        }

        return true;
    }

    private static function changed(): never
    {
        throw new OutlookAddinException('Die veröffentlichten Outlook-Testprofile wurden inzwischen geändert.', 409, 'outlook_addin_static_profile_source_changed');
    }

    private static function invalid(?Throwable $previous = null): never
    {
        throw new OutlookAddinException('Das veröffentlichte statische Outlook-Testprofil ist nicht eindeutig freigegeben.', 409, 'outlook_addin_static_profile_invalid', $previous);
    }
}

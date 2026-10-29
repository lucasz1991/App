<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery as Delivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookStaticLinkedTestDeliveryTest extends TestCase
{
    private const ORIGIN = 'https://app.rail-time.de';

    public function test_explicit_entry_projects_every_view_and_refreshes_all_version_evidence_atomically(): void
    {
        $input = $this->entry();
        $before = $input;
        $output = Delivery::entry($input, Delivery::PROFILE, self::ORIGIN);
        self::assertSame($before, $input);
        self::assertSame($output, Delivery::entry($input, Delivery::PROFILE, self::ORIGIN));
        $signatureVersion = Delivery::version('signature', $input['signatureVersion'], self::ORIGIN);
        $mobileVersion = Delivery::version('mobile-compose', $input['mobileComposeVersion'], self::ORIGIN);
        self::assertSame($signatureVersion, $output['signatureVersion']);
        self::assertSame($mobileVersion, $output['mobileComposeVersion']);
        self::assertSame(Delivery::version('template', $input['hash'], self::ORIGIN), $output['hash']);
        self::assertSame(substr($output['hash'], 0, 16), $output['version']);
        foreach (['id', 'key', 'label', 'name', 'active', 'signatureDocumentId'] as $key) {
            self::assertSame($input[$key], $output[$key]);
        }
        foreach (['html' => 'media', 'composeHtml' => 'composeMedia', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $html => $media) {
            self::assertSame([], $output[$media]);
            self::assertStringContainsString(self::ORIGIN.'/m/c91d7f93db.png', $output[$html]);
            self::assertDoesNotMatchRegularExpression('/cid:|data:|data-rt-static|\.gif/i', $output[$html]);
            if ($html !== 'composeHtml') {
                self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$signatureVersion, $output[$html]);
            }
        }
        self::assertSame([], $output['signature']['media']);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$signatureVersion, $output['signature']['html']);
        self::assertStringContainsString('RT-MOBILE-COMPOSE-VERSION:'.$mobileVersion, $output['mobileComposeHtml']);
    }

    #[DataProvider('assets')]
    public function test_every_curated_source_binary_maps_to_the_exact_compact_png(string $file, string $path, string $sha): void
    {
        $media = $this->attachment($file);
        $input = ['kind' => 'signature', 'html' => $this->signature($media['contentId']), 'media' => [$media], 'version' => str_repeat('a', 16)];
        $output = Delivery::entry($input, Delivery::PROFILE, self::ORIGIN);
        self::assertStringContainsString('src="'.self::ORIGIN.$path.'"', $output['html']);
        self::assertSame([], $output['media']);
        self::assertSame($sha, hash_file('sha256', dirname(__DIR__, 2).'/public'.$path));
        self::assertSame(Delivery::version('signature', $input['version'], self::ORIGIN), $output['version']);
    }

    public static function assets(): iterable
    {
        $class = new \ReflectionClass(Delivery::class);
        foreach ($class->getConstant('ASSETS') as $file => $asset) {
            yield $file => [$file, $asset[5], $asset[6]];
        }
    }

    public function test_doctype_non_image_style_bytes_mso_comments_links_and_dimensions_are_preserved(): void
    {
        $input = $this->entry();
        $cid = $input['media'][0]['contentId'];
        $url = self::ORIGIN.'/m/c91d7f93db.png';
        $style = '<style data-rt-outlook-signature-css="1">/*keep*/.q{font-family:"A B";background-image: url( \'cid:'.$cid.'\' );color:#123}</style>';
        $inline = '<td style="font-family:&quot;A B&quot;;background:url(&quot;cid:'.$cid.'&quot;);color:#123">';
        $mso = '<!--[if mso]><img src="cid:'.$cid.'" width="220" height="28" alt="" style="display:block;width:220px;height:28px;"><style>.m{background:url(cid:'.$cid.')}</style><![endif]-->';
        $source = '<!DOCTYPE html><html lang="de"><head>'.$style.'</head><body><!--keep original-->'
            .str_replace('<td>', $inline, $input['html']).$mso.'<a href="https://example.test">Website</a></body></html>';
        $input['html'] = $source;
        $output = Delivery::entry($input, Delivery::PROFILE, self::ORIGIN)['html'];
        $expected = str_replace('cid:'.$cid, $url, $source);
        $expected = str_replace(' data-rt-static="v1"', '', $expected);
        $expected = str_replace('RT-SIGNATURE-VERSION:'.$input['signatureVersion'], 'RT-SIGNATURE-VERSION:'.Delivery::version('signature', $input['signatureVersion'], self::ORIGIN), $expected);
        self::assertSame($expected, $output);
    }

    public function test_unadmitted_global_signature_active_alias_and_unmarked_entries_stay_byte_exact(): void
    {
        $global = ['html' => '<p>Global unchanged</p>', 'media' => [], 'version' => 'old'];
        $payload = ['signature' => $global, 'template' => $global, 'templates' => [$this->entry(), ['html' => 'unmarked', 'media' => []]]];
        $before = $payload;
        $payload['templates'][0] = Delivery::entry($payload['templates'][0], Delivery::PROFILE, self::ORIGIN);
        $payload['templates'][1] = Delivery::entry($payload['templates'][1], null, 'not even a URL');
        self::assertSame($before['signature'], $payload['signature']);
        self::assertSame($before['template'], $payload['template']);
        self::assertSame($before['templates'][1], $payload['templates'][1]);
        self::assertSame($global, Delivery::entry($global, null, self::ORIGIN));
    }

    public function test_known_compact_and_protected_mixed_aliases_have_the_same_marker_contract(): void
    {
        foreach (['x1 x9', 'rt-shell x9', 'x1 design-v27'] as $classes) {
            $entry = $this->entry();
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                $entry[$field] = str_replace(['rt-shell design-v27', 'rt-outlook-template'], [$classes, 'x8'], $entry[$field]);
            }
            self::assertSame([], Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN)['media']);
        }
    }

    public function test_mobile_entry_keeps_its_desktop_legacy_version_family_separate_and_consistent(): void
    {
        $entry = $this->entry();
        $entry['signatureVersion'] = str_repeat('d', 16);
        $entry['signature']['html'] = str_replace(['rt-outlook-signature', str_repeat('a', 16)], ['rt-outlook-signature rt-mobile-ledger', str_repeat('d', 16)], $entry['signature']['html']);
        $entry['mobileComposeHtml'] = str_replace(str_repeat('a', 16), str_repeat('d', 16), $entry['mobileComposeHtml']);
        $output = Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN);
        $legacy = Delivery::version('signature', str_repeat('a', 16), self::ORIGIN);
        $mobile = Delivery::version('signature', str_repeat('d', 16), self::ORIGIN);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$legacy, $output['html']);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$legacy, $output['combinedComposeHtml']);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$mobile, $output['mobileComposeHtml']);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$mobile, $output['signature']['html']);
        self::assertSame($mobile, $output['signatureVersion']);
        $entry['combinedComposeHtml'] = str_replace(str_repeat('a', 16), str_repeat('e', 16), $entry['combinedComposeHtml']);
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
    }

    #[DataProvider('badOrigins')]
    public function test_untrusted_origin_is_rejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        Delivery::entry($this->entry(), Delivery::PROFILE, $url);
    }

    public static function badOrigins(): iterable
    {
        foreach (['http://app.rail-time.de', 'https://user:pass@app.rail-time.de', 'https://app.rail-time.de/path', 'https://app.rail-time.de//',
            'https://app.rail-time.de/?q=x', 'https://app.rail-time.de/#f', 'https://app.rail-time.de/%2e', 'https://app.rail-time.de\\evil',
            'https://app.rail-time.de.', 'https://app..rail-time.de', "https://app.rail-time.de\n", 'data:image/png;base64,AA=='] as $url) {
            yield $url => [$url];
        }
    }

    public function test_unknown_profile_and_rendered_marker_forgeries_do_not_admit_an_entry(): void
    {
        foreach (['unknown', '', 'v1'] as $profile) {
            $this->rejected(fn () => Delivery::entry($this->entry(), $profile, self::ORIGIN));
        }
        $entry = $this->entry();
        foreach ([
            [' data-rt-static="v1"', ''],
            ['data-rt-static="v1"', 'data-rt-static="v2"'],
            ['data-rt-static="v1"', 'data-rt-static="v&#49;"'],
            ['data-rt-static="v1"', 'data-rt-static="v1" data-rt-static="v1"'],
            ['class="rt-shell design-v27"', 'class="custom"'],
            ['data-rt-artifact-version="v27"', 'data-rt-artifact-version="v26"'],
            ['<div class="rt-outlook-template">', '<blockquote><div class="rt-outlook-template">'],
        ] as [$from, $to]) {
            $candidate = $entry;
            $candidate['html'] = str_replace($from, $to, $candidate['html']);
            $this->rejected(fn () => Delivery::entry($candidate, Delivery::PROFILE, self::ORIGIN));
        }
    }

    public function test_unknown_missing_duplicate_or_orphan_media_and_alternate_image_sources_are_rejected(): void
    {
        $entry = $this->entry();
        $invalid = [];
        $candidate = $entry;
        $candidate['media'][] = $entry['media'][0];
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['media'] = [];
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['media'][] = $this->attachment('contact-web.png');
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['media'][0]['base64'] .= '!';
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['media'][0]['base64'] = base64_encode('unknown');
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['media'][0]['name'] = 'other.png';
        $invalid[] = $candidate;
        foreach (['https://evil.test/file.png', 'data:image/png;base64,AA==', 'cid:missing.png', 'cid:../file.png'] as $source) {
            $candidate = $entry;
            $candidate['html'] = str_replace('cid:'.$entry['media'][0]['contentId'], $source, $candidate['html']);
            $invalid[] = $candidate;
        }
        $candidate = $entry;
        $candidate['html'] = str_replace('<img ', '<img srcset="https://evil.test/other.png 2x" ', $candidate['html']);
        $invalid[] = $candidate;
        $candidate = $entry;
        $candidate['html'] .= '<style>.x{background:url(https://evil.test/other.png)}</style>';
        $invalid[] = $candidate;
        foreach ($invalid as $input) {
            $before = $input;
            $this->rejected(fn () => Delivery::entry($input, Delivery::PROFILE, self::ORIGIN));
            self::assertSame($before, $input);
        }
    }

    public function test_versions_hashes_and_marker_values_must_match_the_prior_entry_exactly(): void
    {
        foreach ([['version', str_repeat('d', 16)], ['hash', 'bad'], ['signatureVersion', str_repeat('b', 16)],
            ['mobileComposeVersion', str_repeat('d', 16)]] as [$key, $value]) {
            $candidate = $this->entry();
            $candidate[$key] = $value;
            $this->rejected(fn () => Delivery::entry($candidate, Delivery::PROFILE, self::ORIGIN));
        }
        foreach ([['unknown', str_repeat('a', 16)], ['signature', str_repeat('a', 64)], ['mobile-compose', 'bad'], ['template', str_repeat('a', 16)]] as [$kind, $prior]) {
            $this->rejected(fn () => Delivery::version($kind, $prior, self::ORIGIN));
        }
        $entry = $this->entry();
        unset($entry['composeMedia']);
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['kind'] = null;
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['signature']['version'] = null;
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['html'] = str_replace('RT-SIGNATURE-VERSION:'.str_repeat('a', 16), 'RT-SIGNATURE-VERSION:'.str_repeat('a', 16).'Z', $entry['html']);
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
        $output = Delivery::entry($this->entry(), Delivery::PROFILE, self::ORIGIN);
        $this->rejected(fn () => Delivery::entry($output, Delivery::PROFILE, self::ORIGIN));
        self::assertNotSame(Delivery::version('signature', str_repeat('a', 16), self::ORIGIN), Delivery::version('signature', str_repeat('a', 16), 'https://other.example.test'));
    }

    public function test_all_budgets_are_checked_after_url_expansion_with_native_id_reserve_unchanged(): void
    {
        $media = $this->attachment('icon-rt-v19-light.png');
        $signature = ['kind' => 'signature', 'html' => $this->signature($media['contentId']), 'media' => [$media], 'version' => str_repeat('a', 16)];
        $probe = Delivery::entry($signature, Delivery::PROFILE, self::ORIGIN);
        $length = intdiv(strlen(mb_convert_encoding($probe['html'], 'UTF-16LE', 'UTF-8')), 2);
        $signature['html'] .= str_repeat('x', 30000 - 627 - $length);
        self::assertSame(30000 - 627, mb_strlen(Delivery::entry($signature, Delivery::PROFILE, self::ORIGIN)['html']));
        $signature['html'] .= 'x';
        $this->rejected(fn () => Delivery::entry($signature, Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['signature']['html'] = '<style>'.str_repeat('a', 12288).'</style>'.$entry['signature']['html'];
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['mobileComposeHtml'] .= str_repeat('😀', 15000);
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
    }

    public function test_fingerprint_binds_all_source_mappings_and_public_png_files(): void
    {
        $fingerprint = Delivery::fingerprint();
        self::assertCount(50, $fingerprint);
        self::assertCount(29, array_filter(array_keys($fingerprint), static fn (string $key): bool => str_starts_with($key, 'map:')));
        foreach ($fingerprint as $path => $sha) {
            if (str_starts_with($path, '/m/')) {
                self::assertSame('/m/'.substr($sha, 0, 10).'.png', $path);
                self::assertSame($sha, hash_file('sha256', dirname(__DIR__, 2).'/public'.$path));
            }
        }
    }

    #[DataProvider('assets')]
    public function test_rendered_system_preview_accepts_only_pinned_remote_sources_and_current_mtime_queries(string $file, string $path, string $sha): void
    {
        foreach (['', '?v='.filemtime(dirname(__DIR__, 2).'/public/mail-assets/'.$file)] as $query) {
            $source = self::ORIGIN.'/mail-assets/'.$file.$query;
            $input = $this->systemPreview($source);
            $expected = str_replace([$source, ' data-rt-static="v1"'], [self::ORIGIN.$path, ''], $input);
            $output = Delivery::renderedPreview($input, Delivery::PROFILE, self::ORIGIN);
            self::assertSame($expected, $output);
            self::assertSame($sha, hash_file('sha256', dirname(__DIR__, 2).'/public'.$path));
            self::assertDoesNotMatchRegularExpression('/cid:|data:|\.gif|data-rt-static/i', $output);
        }
    }

    public function test_rendered_preview_preserves_doctype_styles_mso_versions_and_auto_height_system_icons(): void
    {
        $source = self::ORIGIN.'/mail-assets/contact-phone.png?v='.filemtime(dirname(__DIR__, 2).'/public/mail-assets/contact-phone.png');
        $input = $this->systemPreview($source);
        $input = str_replace('<head>', '<head><style>/*keep*/.x{background:url(\''.$source.'\');color:#123}</style>', $input);
        $input = str_replace('<td>', '<td style="font-family:&quot;A B&quot;;background:url(&quot;'.$source.'&quot;);color:#123">', $input);
        $input = str_replace('width="300" height="38"', 'width="26" style="display:block;width:26px;height:auto;"', $input);
        $mso = '<!--[if mso]><img src="'.$source.'" width="220" height="28" alt="" style="height:28px;"><style>.m{background:url('.$source.')}</style><![endif]-->';
        $input = str_replace('</body>', $mso.'<!-- RT-SIGNATURE-VERSION:'.str_repeat('a', 16).' --></body>', $input);
        $expected = str_replace([$source, ' data-rt-static="v1"'], [self::ORIGIN.'/m/741a9d43f5.png', ''], $input);
        self::assertSame($expected, Delivery::renderedPreview($input, Delivery::PROFILE, self::ORIGIN));
        self::assertSame($input, Delivery::renderedPreview($input, null, 'not a URL'));
        self::assertSame('<img src="data:image/png;base64,AA==">', Delivery::renderedPreview('<img src="data:image/png;base64,AA==">', null, self::ORIGIN));
    }

    public function test_rendered_preview_rejects_unknown_sources_queries_and_context_forgeries(): void
    {
        $file = 'contact-phone.png';
        $source = self::ORIGIN.'/mail-assets/'.$file;
        $mtime = filemtime(dirname(__DIR__, 2).'/public/mail-assets/'.$file);
        foreach (['cid:known.png', 'data:image/png;base64,AA==', 'https://foreign.test/mail-assets/'.$file,
            self::ORIGIN.'/mail-assets/unknown.gif', self::ORIGIN.'/mail-assets/unknown.apng', self::ORIGIN.'/mail-assets/unknown.png',
            $source.'?', $source.'?v=0', $source.'?v=0'.$mtime, $source.'?v='.$mtime.'&extra=1', $source.'?v='.$mtime.'&v='.$mtime,
            $source.'?v='.$mtime.'#fragment', $source.'#fragment', self::ORIGIN.'/mail-assets/%63ontact-phone.png',
            self::ORIGIN.'/mail-assets/../mail-assets/'.$file, self::ORIGIN.'/mail-assets//'.$file,
            self::ORIGIN.'/m/741a9d43f5.png', 'http://app.rail-time.de/mail-assets/'.$file] as $bad) {
            $this->rejected(fn () => Delivery::renderedPreview($this->systemPreview($bad), Delivery::PROFILE, self::ORIGIN));
        }
        $input = $this->systemPreview($source);
        foreach ([[' data-rt-static="v1"', ''], ['data-rt-static="v1"', 'data-rt-static="v2"'],
            ['data-rt-static="v1"', 'data-rt-static="v1" data-rt-static="v1"'], ['data-rt-static="v1"', 'data-rt-static="v&#49;"'],
            [' data-rt-system-mail-inline-assets="1"', ''], ['data-rt-system-mail-inline-assets="1"', 'data-rt-system-mail-inline-assets="0"'],
            ['rt-system-signature-v27', 'custom'], ['data-rt-artifact-version="v27"', 'data-rt-artifact-version="v26"'],
            ['class="rt-system-signature-v27"', 'class="rt-system-signature-v27 design-personal-columns"'],
            ['<table>', '<table data-rt-static="v1">'], ['<table>', '<blockquote><table>'], ['<!DOCTYPE html>', '']] as [$from, $to]) {
            $this->rejected(fn () => Delivery::renderedPreview(str_replace($from, $to, $input), Delivery::PROFILE, self::ORIGIN));
        }
        $this->rejected(fn () => Delivery::renderedPreview($input, 'unknown', self::ORIGIN));
        $this->rejected(fn () => Delivery::renderedPreview($input, Delivery::PROFILE, 'http://app.rail-time.de'));
    }

    public function test_rendered_preview_keeps_its_full_system_budgets_and_does_not_loosen_cid_entry_rules(): void
    {
        $source = self::ORIGIN.'/mail-assets/contact-phone.png';
        $input = $this->systemPreview($source);
        $this->rejected(fn () => Delivery::renderedPreview(str_replace('<head>', '<head><style>'.str_repeat('a', 24576).'</style>', $input), Delivery::PROFILE, self::ORIGIN));
        $this->rejected(fn () => Delivery::renderedPreview(str_replace('</body>', str_repeat('😀', 50000).'</body>', $input), Delivery::PROFILE, self::ORIGIN));
        $entry = $this->entry();
        $entry['signature']['html'] = str_replace('width="300" height="65"', 'width="300" style="height:auto;"', $entry['signature']['html']);
        $this->rejected(fn () => Delivery::entry($entry, Delivery::PROFILE, self::ORIGIN));
    }

    private function entry(): array
    {
        $media = $this->attachment('icon-rt-v19-light.png');
        $signature = $this->signature($media['contentId']);
        $compose = '<div class="rt-outlook-template"><table class="rt-shell design-v27" data-rt-static="v1"><tbody><tr><td>'
            .'<img src="cid:'.$media['contentId'].'" width="44" height="44" alt=""></td></tr></tbody></table></div>';
        $combined = $compose.$signature;

        return ['id' => 'test-id', 'key' => 'test-id', 'label' => '[TEST] Static', 'name' => '[TEST] Static', 'active' => false, 'signatureDocumentId' => 'signature-id',
            'hash' => str_repeat('c', 64), 'version' => str_repeat('c', 16), 'signatureVersion' => str_repeat('a', 16), 'mobileComposeVersion' => str_repeat('b', 16),
            'html' => $combined, 'media' => [$media], 'composeHtml' => $compose, 'composeMedia' => [$media], 'combinedComposeHtml' => $combined, 'combinedComposeMedia' => [$media],
            'mobileComposeHtml' => $combined.'<!--RT-MOBILE-COMPOSE-VERSION:'.str_repeat('b', 16).'-->', 'mobileComposeMedia' => [$media],
            'signature' => ['html' => $signature, 'media' => [$media]]];
    }

    private function signature(string $cid): string
    {
        return '<div class="rt-outlook-signature"><table><tbody><tr data-rt-artifact-version="v27" data-rt-static="v1"><td>'
            .'<img src="cid:'.$cid.'" width="300" height="65" alt=""></td></tr></tbody></table>'
            .'<!--RT-SIGNATURE-VERSION:'.str_repeat('a', 16).'--></div>';
    }

    private function systemPreview(string $source): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head><body data-rt-system-mail-inline-assets="1">'
            .'<!-- Original template and message --><table><tbody><tr class="rt-system-signature-v27" data-rt-artifact-version="v27" data-rt-static="v1"><td>'
            .'<img src="'.$source.'" width="300" height="38" alt=""></td></tr></tbody></table></body></html>';
    }

    private function attachment(string $file): array
    {
        $bytes = file_get_contents(dirname(__DIR__, 2).'/public/mail-assets/'.$file);
        $cid = 'railtime-'.substr(hash('sha256', $bytes), 0, 20).'.'.pathinfo($file, PATHINFO_EXTENSION);

        return ['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($bytes)];
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('An unknown static delivery contract was accepted.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}

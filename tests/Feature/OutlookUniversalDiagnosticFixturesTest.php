<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticDetails;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookUniversalDiagnosticFixturesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->buildMinimalRailTimeSchema();
        config(['cache.default' => 'array']);
        Cache::flush();
        User::factory()->create(['id' => 5, 'role' => 'staff', 'email' => 'it-technik@rail-time.de']);
        User::factory()->create(['id' => 6, 'role' => 'admin', 'email' => 'admin@example.test']);
    }

    public function test_real_javascript_redactor_output_is_accepted_without_author_quote_or_raw_urls(): void
    {
        $script = <<<'JS'
import fs from 'node:fs';
import { parseManagedHtml } from './resources/js/outlook-addin/managed-template.js';
import { universalComposeDiagnosticFixture } from './resources/js/outlook-addin/u2e-compose.js';
const source = JSON.parse(fs.readFileSync('./tests/Fixtures/mail/u2e-readback-v2/source.json','utf8'));
const html = source.html;
const { elements } = parseManagedHtml(html);
const root = elements.findIndex(node => node.tag === 'table' && /^rt-sig-u2-/.test(node.attrs.id || ''));
const author = elements.find(node => node.tag === 'tr' && node.parent === root);
const modified = (html.slice(0,author.openEnd)+'<td colspan="4">PRIVATE-AUTHOR private@example.test</td>'+html.slice(author.close)).replace(/href="[^"]+"/,'href="javascript:PRIVATE-TOKEN"');
const actual = '<p>PRIVATE-CURRENT</p>'+modified+'<div id="divRplyFwdMsg"><p>PRIVATE-QUOTE</p>'+html+'</div>';
process.stdout.write(JSON.stringify(universalComposeDiagnosticFixture(source,actual,'reply')));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
        $process->mustRun();
        $fixture = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertStringNotContainsString('rt-cta-line', $fixture['expectedHtml']);
        self::assertStringNotContainsString('colspan="5"', $fixture['expectedHtml']);
        self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($fixture));
        self::assertDoesNotMatchRegularExpression('/PRIVATE-|private@example|mailto:|https:\/\//', json_encode($fixture));
        $store = app(OutlookUniversalDiagnosticFixtureStore::class);
        $capture = $store->put(User::findOrFail(5), $fixture, $this->metadata());
        self::assertNotNull($capture);
        self::assertSame($fixture, $store->get(User::findOrFail(5), $capture['id'])['fixture']);
    }

    public function test_unsafe_html_unknown_fields_and_unredacted_values_are_rejected_without_storage(): void
    {
        $fixture = $this->fixture();
        self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($fixture));
        foreach (['<p>PRIVATE-AUTHOR</p>'.$fixture['html'], str_replace('rt-redacted', 'private@example.test', $fixture['html']),
            str_replace('<td>', '<td onclick="alert(1)">', $fixture['html']), str_replace('<td>', '<td data-secret="PRIVATE">', $fixture['html']),
            str_replace('<td>', '<td style="background:url(https://private.example)">', $fixture['html']),
            str_replace('<td>', '<td style="font-family:private@example.test">', $fixture['html']),
            str_replace('<td>', '<td><script>PRIVATE-TOKEN</script>', $fixture['html']),
            str_replace('<td>', '<td><a href="mailto:private@example.test">', $fixture['html']),
            str_replace('</table>', '<blockquote>rt-redacted</blockquote></table>', $fixture['html']),
            str_replace('<tr>', '<tr><td colspan="4">rt-redacted</td></tr><tr>', $fixture['html'])] as $html) {
            $unsafe = array_replace($fixture, ['html' => $html, 'sha256' => hash('sha256', $html)]);
            self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid($unsafe));
            self::assertNull(app(OutlookUniversalDiagnosticFixtureStore::class)->put(User::findOrFail(5), $unsafe, $this->metadata()));
        }
        self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid($fixture + ['body' => 'PRIVATE']));
        self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid(array_replace($fixture, ['sha256' => str_repeat('a', 64)])));
        self::assertNull(app(OutlookUniversalDiagnosticFixtureStore::class)->put(User::findOrFail(6), $fixture, $this->metadata()));
        self::assertNull(app(OutlookUniversalDiagnosticFixtureStore::class)->put(User::findOrFail(5), $fixture, $this->metadata(), 'other@example.test'));
    }

    public function test_fixture_count_and_expiry_are_bounded_and_duplicate_upload_does_not_extend_retention(): void
    {
        $store = app(OutlookUniversalDiagnosticFixtureStore::class);
        $pilot = User::findOrFail(5);
        $first = $store->put($pilot, $this->fixture(), $this->metadata());
        $firstSlot = 'outlook:u2e:fixtures:v1:'.hash('sha256', 'it-technik@rail-time.de').':0';
        self::assertNotNull(Cache::get($firstSlot));
        $this->travel(6)->days();
        self::assertSame($first, $store->put($pilot, $this->fixture(), $this->metadata()));
        $second = $store->put($pilot, $this->fixture(1041), $this->metadata());
        $this->travel(2)->days();
        self::assertNull(Cache::get($firstSlot), 'A later upload must not extend the original artifact cache TTL.');
        self::assertNull($store->get($pilot, $first['id']));
        self::assertNotNull($store->get($pilot, $second['id']));
        foreach ([1042, 1043, 1044] as $width) {
            self::assertNotNull($store->put($pilot, $this->fixture($width), $this->metadata()));
        }
        self::assertCount(3, $store->listingFor($pilot));
        self::assertNull($store->get($pilot, $second['id']));
        self::assertSame([], $store->listingFor(User::findOrFail(6)));
        $this->travel(8)->days();
        self::assertSame([], $store->listingFor($pilot));
    }

    public function test_admin_download_is_an_attachment_and_denies_anonymous_or_non_admin_access(): void
    {
        $capture = app(OutlookUniversalDiagnosticFixtureStore::class)->put(User::findOrFail(5), $this->fixture(), $this->metadata());
        $url = '/administrator/mail-vorlagen/u2e-fixture/5/'.$capture['id'];
        $this->get($url)->assertForbidden();
        $this->actingAs(User::findOrFail(5))->get($url)->assertForbidden();
        $response = $this->actingAs(User::findOrFail(6))->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('u2e-redacted-compose-signature', $response->json('kind'));
        $this->get($url.'?inline=1')->assertNotFound();
        $this->get('/administrator/mail-vorlagen/u2e-fixture/6/'.$capture['id'])->assertNotFound();
    }

    public function test_a_false_cache_write_does_not_claim_a_stored_fixture(): void
    {
        Cache::shouldReceive('get')->times(3)->andReturn(null);
        Cache::shouldReceive('put')->once()->andReturn(false);
        self::assertNull(app(OutlookUniversalDiagnosticFixtureStore::class)->put(User::findOrFail(5), $this->fixture(), $this->metadata()));
    }

    public function test_scheduled_cleanup_removes_expired_file_slots_without_touching_active_or_unrelated_cache(): void
    {
        $directory = storage_path('framework/testing/u2e-fixture-cache-'.bin2hex(random_bytes(8)));
        config(['cache.default' => 'u2e-fixture-file-test', 'cache.stores.u2e-fixture-file-test' => ['driver' => 'file', 'path' => $directory]]);
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            (new Filesystem)->deleteDirectory($directory);
        });
        $cache = Cache::store();
        $fileStore = $cache->getStore();
        $key = 'outlook:u2e:fixtures:v1:'.hash('sha256', 'it-technik@rail-time.de');
        $store = app(OutlookUniversalDiagnosticFixtureStore::class);
        $pilot = User::findOrFail(5);
        self::assertNotNull($store->put($pilot, $this->fixture(), $this->metadata()));
        $expiredPath = $fileStore->path($key.':0');
        $original = file_get_contents($expiredPath);
        $cache->put('unrelated-private-cache', 'unchanged', now()->addDays(30));
        $unrelatedPath = $fileStore->path('unrelated-private-cache');
        $unrelated = file_get_contents($unrelatedPath);

        $this->travel(6)->days();
        self::assertNotNull($store->put($pilot, $this->fixture(1041), $this->metadata()));
        $activePath = $fileStore->path($key.':1');
        $active = file_get_contents($activePath);
        $this->artisan('outlook-addin:purge-diagnostic-fixtures')->expectsOutput('3 private U2e pilot cache slots checked for expiry.')->assertSuccessful();
        self::assertSame($original, file_get_contents($expiredPath), 'An active artifact and its stored expiry remain byte-identical.');

        $this->travel(2)->days();
        self::assertFileExists($expiredPath, 'FileStore retains the expired file until the scheduled check.');
        // The command does not need the user record or any administrator read.
        DB::table('users')->where('id', 5)->delete();
        $this->artisan('outlook-addin:purge-diagnostic-fixtures')->assertSuccessful();
        self::assertFileDoesNotExist($expiredPath);
        self::assertSame($active, file_get_contents($activePath));
        self::assertSame($unrelated, file_get_contents($unrelatedPath));
    }

    public function test_diagnostic_details_allow_only_finite_keys_and_hashed_strings(): void
    {
        $valid = ['checkpoint' => 'evidence.text', 'difference' => ['key' => 'evidence.text.sha256', 'index' => null,
            'expected' => 'sha256:'.str_repeat('a', 64).':13', 'actual' => 'sha256:'.str_repeat('b', 64).':14']];
        self::assertTrue(OutlookUniversalDiagnosticDetails::valid($valid));
        foreach ([['checkpoint' => 'private@example.test'], ['difference' => ['key' => 'evidence.text', 'index' => null, 'expected' => 'PRIVATE-BODY', 'actual' => 13]],
            ['difference' => ['key' => 'evidence.text', 'index' => -1, 'expected' => 12, 'actual' => 13]],
            ['difference' => ['key' => 'evidence.text', 'index' => 1, 'expected' => 1.5, 'actual' => 13]]] as $change) {
            self::assertFalse(OutlookUniversalDiagnosticDetails::valid(array_replace($valid, $change)));
        }
    }

    public function test_frontend_and_backend_use_the_same_finite_diagnostic_vocabulary(): void
    {
        $script = <<<'JS'
import * as d from './resources/js/outlook-addin/u2e-readback-diagnostics.js';
process.stdout.write(JSON.stringify({checkpoints:d.U2E_READBACK_CHECKPOINTS,keys:d.U2E_READBACK_DIFFERENCE_KEYS,
tags:d.U2E_REDACTED_TAGS,attrs:d.U2E_REDACTED_ATTRIBUTES,styles:d.U2E_REDACTED_STYLE_PROPERTIES,classes:d.U2E_REDACTED_CLASSES,words:d.U2E_REDACTED_CSS_WORDS}));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
        $process->mustRun();
        $lists = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($lists['checkpoints'], OutlookUniversalDiagnosticDetails::CHECKPOINTS);
        self::assertSame($lists['keys'], OutlookUniversalDiagnosticDetails::KEYS);
        $reflection = new \ReflectionClass(OutlookUniversalDiagnosticFixtureValidator::class);
        foreach (['tags' => 'TAGS', 'attrs' => 'ATTRIBUTES', 'styles' => 'STYLE_PROPERTIES', 'classes' => 'CLASSES', 'words' => 'CSS_WORDS'] as $key => $constant) {
            self::assertSame($lists[$key], $reflection->getConstant($constant));
        }
    }

    public function test_javascript_word_centering_context_and_cell_only_marker_cross_the_php_boundary(): void
    {
        $script = <<<'JS'
import {parseHTML} from 'linkedom';
import fs from 'node:fs';
import {universalRedactedReadbackFixture} from './resources/js/outlook-addin/u2e-readback-diagnostics.js';
const source=JSON.parse(fs.readFileSync('./tests/Fixtures/mail/u2e-readback-v2/legacy-v26/source.json','utf8')),html=source.html;
const {document}=parseHTML(html),shell=document.querySelector('table[width="1040"]');
shell.removeAttribute('id');shell.removeAttribute('align');shell.setAttribute('style',(shell.getAttribute('style')||'').split(';').filter(d=>!/^\s*margin(?:-left|-right)?\s*:/i.test(d)).join(';'));
const actual='<div align="center" data-private="PRIVATE-ATTRIBUTE"><p>PRIVATE-BEFORE</p>'+shell.outerHTML+'<p>PRIVATE-AFTER</p></div>';
process.stdout.write(JSON.stringify(universalRedactedReadbackFixture(source.html,actual)));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
        $process->mustRun();
        $fixture = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertStringNotContainsString('rt-cta-line', $fixture['expectedHtml']);
        self::assertStringNotContainsString('colspan="5"', $fixture['expectedHtml']);
        self::assertStringStartsWith('<div align="center"><table ', $fixture['html']);
        self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($fixture));
        self::assertDoesNotMatchRegularExpression('/PRIVATE-|data-private/', json_encode($fixture));
    }

    public function test_centering_wrapper_and_marker_mirror_reject_extra_context_or_conflicting_identity(): void
    {
        $fixture = $this->fixture();
        $marker = 'rt-sig-u2-'.str_repeat('a', 24);
        $cellOnly = str_replace(' id="'.$marker.'"', '', $fixture['html']);
        $cellOnly = preg_replace('/<td>/', '<td id="'.$marker.'-cell">', $cellOnly, 1);
        $wrapped = '<div align="center">'.$cellOnly.'</div>';
        $pair = array_replace($fixture, ['html' => $wrapped, 'sha256' => hash('sha256', $wrapped)]);
        self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($pair));
        foreach (['<div align="center" title="rt-redacted">'.$cellOnly.'</div>', '<div align="left">'.$cellOnly.'</div>',
            '<div align="center">rt-redacted'.$cellOnly.'</div>', '<div align="center"><div align="center">'.$cellOnly.'</div></div>',
            '<div align="center">'.$cellOnly.'<p>rt-redacted</p></div>', $wrapped.'<p>rt-redacted</p>',
            str_replace('<table ', '<table id="rt-sig-u2-'.str_repeat('b', 24).'" ', $wrapped),
            str_replace($marker.'-cell', 'PRIVATE-ID', $wrapped),
            preg_replace('/<td>/', '<td id="'.$marker.'-cell">', $wrapped, 1),
            str_replace(' id="'.$marker.'-cell"', '', $wrapped)] as $html) {
            self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid(array_replace($pair, ['html' => $html, 'sha256' => hash('sha256', $html)])));
        }
    }

    private function metadata(): array
    {
        return ['host' => 'Outlook', 'code' => 'SIGNATURE_LAYOUT_UNCONFIRMED', 'phase' => 'readback', 'sourceLength' => 13822,
            'bodyLength' => 56686, 'checkpoint' => 'images.src', 'difference' => null];
    }

    private function fixture(int $width = 1040): array
    {
        $html = '<table id="rt-sig-u2-'.str_repeat('a', 24).'" width="'.$width.'"><tr>'.str_repeat('<td>rt-redacted</td>', 4)
            .'</tr><tr><td colspan="3"></td></tr><tr><td colspan="4">rt-redacted</td></tr></table>';

        return ['schema' => 1, 'format' => 'u2e-redacted-html-v1', 'html' => $html, 'expectedHtml' => $html,
            'sourceLength' => strlen($html), 'sha256' => hash('sha256', $html), 'expectedSha256' => hash('sha256', $html)];
    }
}

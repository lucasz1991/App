<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/** Real canonical builders and fresh DB admission, with no fingerprint service double. */
final class OutlookStaticLinkedTestProfilesWireTest extends TestCase
{
    public function test_real_fingerprint_reloads_an_authenticated_profile_before_and_after_admission(): void
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        [$canonical, $user] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        config(['app.url' => 'https://mail.example.test']);
        $user->load('profile');
        $priorName = $user->profile->first_name;
        $payloads = app(OutlookAddinPayloadService::class);
        $canonical['version']['personal'] = substr($payloads->sourceFingerprint($user), 0, 16);
        $wire = OutlookClientPresentation::payload($canonical);
        $probe = new class($payloads, (int) $user->id)
        {
            public int $calls = 0;

            public function __construct(private OutlookAddinPayloadService $real, private int $userId) {}

            public function sourceFingerprint(User $current): string
            {
                $fingerprint = $this->real->sourceFingerprint($current);
                $this->calls++;
                if ($this->calls === 1) {
                    UserProfile::query()->where('user_id', $this->userId)->firstOrFail()->forceFill(['first_name' => 'Concurrent change'])->saveQuietly();
                }

                return $fingerprint;
            }
        };
        app()->instance(OutlookAddinPayloadService::class, $probe);
        try {
            OutlookStaticLinkedTestProfiles::payload($wire, $user);
            self::fail('A concurrent real profile change must invalidate the result.');
        } catch (OutlookAddinException $error) {
            self::assertSame('outlook_addin_static_profile_source_changed', $error->errorCode, $error->getPrevious()?->getMessage() ?? '');
            self::assertSame(409, $error->httpStatus);
        }
        self::assertSame(2, $probe->calls);
        self::assertSame($priorName, $user->profile->first_name, 'The authentication model and loaded relation remain unchanged.');
    }

    public function test_actual_desktop_and_mobile_wires_project_only_the_separately_published_test_pair(): void
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        [, $user] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        $global = MailDocument::query()->where('kind', 'signature')->where('outlook_default', true)->firstOrFail();
        $signature = $global->replicate();
        $signature->public_id = (string) Str::uuid();
        $signature->name = 'Synthetic static linked test partner';
        $signature->outlook_default = null;
        $signature->is_active = null;
        $markedSignature = preg_replace('~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~', '$0 data-rt-static="v1"', $signature->published_html, -1, $signatureCount);
        self::assertSame(1, $signatureCount);
        $signature->html = $signature->published_html = $markedSignature;
        $signature->saveQuietly();
        $template = MailDocument::query()->where('kind', 'template')->where('outlook_default', null)->firstOrFail();
        $markedTemplate = preg_replace('~<table\b(?=[^>]*\bclass="[^"]*\brt-shell\b)(?=[^>]*\bclass="[^"]*\bdesign-v27\b)~', '$0 data-rt-static="v1"', $template->published_html, -1, $templateCount);
        self::assertSame(1, $templateCount);
        $template->forceFill(['html' => $markedTemplate, 'published_html' => $markedTemplate,
            'signature_document_id' => $signature->id, 'published_signature_document_id' => $signature->id])->saveQuietly();
        $this->app->forgetScopedInstances();
        $payloads = app(OutlookAddinPayloadService::class);
        $fingerprint = $payloads->sourceFingerprint($user);
        $canonical = $payloads->forUser($user);
        $canonical['version']['personal'] = substr($fingerprint, 0, 16);
        $documents = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $device) {
            $wire = OutlookClientPresentation::payload($device);
            $before = $wire;
            $entry = collect($wire['templates'])->firstWhere('id', $template->public_id);
            $markerVersions = [];
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                preg_match_all('/RT-SIGNATURE-VERSION:([0-9a-f]{16})/', $entry[$field] ?? '', $matches);
                $markerVersions[$field] = array_values(array_unique($matches[1]));
            }
            self::assertCount(1, $markerVersions['html']);
            self::assertSame($markerVersions['html'], $markerVersions['combinedComposeHtml']);
            if (isset($entry['mobileComposeHtml'])) {
                self::assertSame([$entry['signatureVersion']], $markerVersions['mobileComposeHtml']);
            }
            if (! str_contains($wire['signature']['html'], 'data-rt-mobile-hybrid="v2"')) {
                self::assertSame([$entry['signatureVersion']], $markerVersions['html']);
            }
            $projected = OutlookStaticLinkedTestProfiles::payload($wire, $user);
            self::assertSame($before, $wire);
            self::assertSame($wire['signature'], $projected['signature']);
            self::assertSame($wire['template'], $projected['template']);
            self::assertSame($wire['version'], $projected['version']);
            foreach ($wire['templates'] as $index => $entry) {
                $result = $projected['templates'][$index];
                if ($entry['id'] !== $template->public_id) {
                    self::assertSame($entry, $result);

                    continue;
                }
                self::assertSame($signature->public_id, $result['signatureDocumentId']);
                self::assertNotSame($entry['hash'], $result['hash']);
                self::assertNotSame($entry['signatureVersion'], $result['signatureVersion']);
                foreach (['html' => 'media', 'composeHtml' => 'composeMedia', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $html => $media) {
                    if (! isset($result[$html])) {
                        continue;
                    }
                    self::assertSame([], $result[$media]);
                    self::assertStringNotContainsString('cid:', $result[$html]);
                    self::assertStringNotContainsString('data-rt-static', $result[$html]);
                    self::assertStringContainsString('https://mail.example.test/m/', $result[$html]);
                    self::assertLessThanOrEqual($html === 'mobileComposeHtml' ? 30000 : 99000, intdiv(strlen(mb_convert_encoding($result[$html], 'UTF-16LE', 'UTF-8')), 2));
                }
                self::assertSame([], $result['signature']['media']);
                self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$result['signatureVersion'], $result['signature']['html']);
                self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($result['signature']['html'], 'UTF-16LE', 'UTF-8')), 2) + (str_contains($result['signature']['html'], 'data-rt-mobile-hybrid="v2"') ? 0 : 627));
            }
        }
        self::assertSame($fingerprint, $payloads->sourceFingerprint($user));
        self::assertSame($documents, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_real_half_marked_offer_is_quarantined_without_blocking_standard_or_signature_only_delivery(): void
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        [, $user] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        $test = MailDocument::query()->where('kind', 'template')->whereNull('outlook_default')->firstOrFail();
        $marked = preg_replace('~<table\b(?=[^>]*\bclass="[^"]*\brt-shell\b)(?=[^>]*\bclass="[^"]*\bdesign-v27\b)~', '$0 data-rt-static="v1"', $test->published_html, -1, $count);
        self::assertSame(1, $count);
        $test->forceFill(['html' => $marked, 'published_html' => $marked])->saveQuietly();
        $this->app->forgetScopedInstances();
        $payloads = app(OutlookAddinPayloadService::class);
        $fingerprint = $payloads->sourceFingerprint($user);
        $canonical = $payloads->forUser($user);
        $canonical['version']['personal'] = substr($fingerprint, 0, 16);
        $documents = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $device) {
            $wire = OutlookClientPresentation::payload($device);
            $expected = $wire;
            $expected['templates'] = array_values(array_filter($wire['templates'], static fn (array $entry): bool => $entry['id'] !== $test->public_id));
            self::assertCount(count($wire['templates']) - 1, $expected['templates']);
            self::assertSame($expected, OutlookStaticLinkedTestProfiles::payload($wire, $user));

            $signatureOnly = [
                'schema' => $wire['schema'], 'marker' => $wire['marker'],
                'signature' => $wire['signature'], 'templates' => [], 'automaticTemplateId' => null,
                'version' => array_intersect_key($wire['version'], ['signature' => true, 'personal' => true]),
            ];
            self::assertSame($signatureOnly, OutlookStaticLinkedTestProfiles::payload($signatureOnly, $user));

            $selected = $wire;
            $selected['automaticTemplateId'] = $test->public_id;
            foreach ($selected['templates'] as &$entry) {
                $entry['isDefault'] = $entry['id'] === $test->public_id;
            }
            unset($entry);
            try {
                OutlookStaticLinkedTestProfiles::payload($selected, $user);
                self::fail('An actually selected half-marked test must never fall back to dynamic or partial media.');
            } catch (OutlookAddinException $error) {
                self::assertSame(409, $error->httpStatus);
                self::assertSame('outlook_addin_static_profile_invalid', $error->errorCode);
            }
        }
        self::assertSame($fingerprint, $payloads->sourceFingerprint($user));
        self::assertSame($documents, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Admission uses the real fresh published store; only fingerprint timing is a container test double. */
final class OutlookStaticLinkedTestProfilesTest extends TestCase
{
    private const DEFAULT_ID = '11111111-1111-4111-8111-111111111111';

    private const TEST_ID = '22222222-2222-4222-8222-222222222222';

    private const GLOBAL_SIGNATURE_ID = '33333333-3333-4333-8333-333333333333';

    private const TEST_SIGNATURE_ID = '44444444-4444-4444-8444-444444444444';

    private const FINGERPRINT = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private User $user;

    private MailDocument $template;

    private MailDocument $signature;

    private object $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test', 'outlook_addin.snapshots.auto_refresh' => false]);
        Schema::create('mail_documents', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('kind');
            $table->string('status')->default('published');
            $table->string('name');
            $table->boolean('is_active')->nullable();
            $table->boolean('is_outlook_template')->default(false);
            $table->boolean('outlook_released')->default(false);
            $table->boolean('outlook_default')->nullable();
            $table->unsignedInteger('delivery_revision')->default(1);
            $table->unsignedInteger('signature_document_id')->nullable();
            $table->unsignedInteger('published_signature_document_id')->nullable();
            $table->text('html');
            $table->text('css')->default('');
            $table->text('published_html');
            $table->text('published_css')->default('');
            $table->timestamp('published_at');
            $table->timestamps();
        });
        $global = $this->document('signature', self::GLOBAL_SIGNATURE_ID, $this->sourceSignature(false), ['outlook_default' => true]);
        $this->signature = $this->document('signature', self::TEST_SIGNATURE_ID, $this->sourceSignature(true));
        $this->document('template', self::DEFAULT_ID, $this->sourceTemplate(false), [
            'is_active' => true, 'outlook_released' => true, 'outlook_default' => true,
            'signature_document_id' => $global->id, 'published_signature_document_id' => $global->id,
        ]);
        $this->template = $this->document('template', self::TEST_ID, $this->sourceTemplate(true), [
            'is_outlook_template' => true, 'outlook_released' => true,
            'signature_document_id' => $this->signature->id, 'published_signature_document_id' => $this->signature->id,
        ]);
        $this->user = new User;
        $this->user->forceFill(['id' => 99, 'name' => 'Synthetic employee']);
        $this->fingerprints = new class
        {
            public int $calls = 0;

            public array $values = [];

            public function sourceFingerprint(User $user): string
            {
                $this->calls++;

                return $this->values[$this->calls - 1] ?? str_repeat('e', 64);
            }
        };
        $this->app->instance(OutlookAddinPayloadService::class, $this->fingerprints);
    }

    public function test_only_fresh_marked_pair_is_projected_and_all_global_default_and_personal_fields_stay_exact(): void
    {
        $payload = $this->payload();
        $before = $payload;
        $documents = MailDocument::query()->orderBy('id')->get()->toArray();
        $result = OutlookStaticLinkedTestProfiles::payload($payload, $this->user);
        self::assertSame($before, $payload);
        self::assertSame($documents, MailDocument::query()->orderBy('id')->get()->toArray());
        foreach (['signature', 'template', 'automaticTemplateId', 'marker', 'metadata'] as $field) {
            self::assertSame($before[$field], $result[$field], $field);
        }
        self::assertSame($before['version'], $result['version']);
        self::assertSame($before['templates'][0], $result['templates'][0]);
        self::assertSame(2, $this->fingerprints->calls);
        $marked = $result['templates'][1];
        foreach (['media', 'composeMedia', 'combinedComposeMedia', 'mobileComposeMedia'] as $field) {
            self::assertSame([], $marked[$field]);
        }
        self::assertSame([], $marked['signature']['media']);
        foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
            self::assertStringContainsString('https://mail.example.test/m/c91d7f93db.png', $marked[$field]);
            self::assertStringNotContainsString('cid:', $marked[$field]);
            self::assertStringNotContainsString('data-rt-static', $marked[$field]);
        }
        self::assertNotSame($before['templates'][1]['version'], $marked['version']);
        self::assertSame(substr($marked['hash'], 0, 16), $marked['version']);
        self::assertNotSame($before['templates'][1]['signatureVersion'], $marked['signatureVersion']);
        self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$marked['signatureVersion'], $marked['signature']['html']);
        self::assertStringContainsString('RT-MOBILE-COMPOSE-VERSION:'.$marked['mobileComposeVersion'], $marked['mobileComposeHtml']);
        self::assertSame($result, OutlookStaticLinkedTestProfiles::payload($before, $this->user));
        Http::assertNothingSent();
    }

    public function test_projected_active_entry_updates_only_its_current_legacy_template_alias(): void
    {
        $payload = $this->payload();
        $payload['templates'][0]['active'] = false;
        $payload['templates'][1]['active'] = true;
        $payload['template'] = array_intersect_key($payload['templates'][1], ['html' => true, 'media' => true]);
        $payload['version']['template'] = $payload['templates'][1]['version'];
        $result = OutlookStaticLinkedTestProfiles::payload($payload, $this->user);
        self::assertSame($result['templates'][1]['html'], $result['template']['html']);
        self::assertSame([], $result['template']['media']);
        self::assertSame($result['templates'][1]['version'], $result['version']['template']);
        self::assertSame($payload['signature'], $result['signature']);
        self::assertSame($payload['version']['personal'], $result['version']['personal']);
        self::assertSame($payload['version']['signature'], $result['version']['signature']);
    }

    public function test_active_alias_must_match_the_actual_entry_before_it_can_be_updated(): void
    {
        $payload = $this->payload();
        $payload['templates'][0]['active'] = false;
        $payload['templates'][1]['active'] = true;
        $this->assertDenied($payload);
    }

    public function test_published_source_is_reloaded_even_if_the_scoped_snapshot_cache_was_already_read(): void
    {
        $this->template->updateQuietly(['published_html' => $this->sourceTemplate(false)]);
        $this->signature->updateQuietly(['published_html' => $this->sourceSignature(false)]);
        $store = app(PublishedMailDocumentSnapshotStore::class);
        self::assertStringNotContainsString('data-rt-static', collect($store->templateSnapshots())->firstWhere('id', self::TEST_ID)['html']);
        $this->template->updateQuietly(['published_html' => $this->sourceTemplate(true)]);
        $this->signature->updateQuietly(['published_html' => $this->sourceSignature(true)]);
        $result = OutlookStaticLinkedTestProfiles::payload($this->payload(), $this->user);
        self::assertSame([], $result['templates'][1]['media']);
    }

    public function test_draft_markers_and_payload_flags_cannot_admit_an_unmarked_publication(): void
    {
        $this->template->updateQuietly(['published_html' => $this->sourceTemplate(false)]);
        $this->signature->updateQuietly(['published_html' => $this->sourceSignature(false)]);
        $payload = $this->payload();
        $payload['templates'][1] = $this->entry(self::TEST_ID, self::TEST_SIGNATURE_ID, false);
        $payload['templates'][1]['staticProfile'] = OutlookStaticLinkedTestDelivery::PROFILE;
        $payload['staticProfile'] = OutlookStaticLinkedTestDelivery::PROFILE;
        $payload['staticOrigin'] = 'https://attacker.example';
        self::assertSame($payload, OutlookStaticLinkedTestProfiles::payload($payload, $this->user));
        self::assertStringContainsString('data-rt-static', $this->template->fresh()->html);
    }

    public function test_signature_only_payload_never_activates_the_marked_library_partner_globally(): void
    {
        $payload = $this->payload();
        $payload['templates'] = [];
        unset($payload['template'], $payload['version']['template']);
        self::assertSame($payload, OutlookStaticLinkedTestProfiles::payload($payload, $this->user));
    }

    public function test_comments_and_attribute_text_do_not_count_as_source_marker_attributes(): void
    {
        $this->template->updateQuietly(['published_html' => '<!-- data-rt-static="v1" -->'.$this->sourceTemplate(false)]);
        $this->signature->updateQuietly(['published_html' => str_replace('<td>', '<td title=\'data-rt-static="v1"\'>', $this->sourceSignature(false))]);
        $payload = $this->payload();
        $payload['templates'][1] = $this->entry(self::TEST_ID, self::TEST_SIGNATURE_ID, false);
        self::assertSame($payload, OutlookStaticLinkedTestProfiles::payload($payload, $this->user));
    }

    public function test_stale_or_missing_personal_version_is_rejected_before_projection(): void
    {
        $payload = $this->payload();
        $payload['version']['personal'] = str_repeat('f', 16);
        $this->assertDenied($payload, 'outlook_addin_static_profile_source_changed');
        self::assertSame(1, $this->fingerprints->calls);
        unset($payload['version']['personal']);
        $this->assertDenied($payload, 'outlook_addin_static_profile_source_changed');
    }

    public function test_fingerprint_change_during_projection_rejects_the_whole_result_without_mutating_input(): void
    {
        $this->fingerprints->values = [self::FINGERPRINT, str_repeat('f', 64)];
        $payload = $this->payload();
        $before = $payload;
        $this->assertDenied($payload, 'outlook_addin_static_profile_source_changed');
        self::assertSame($before, $payload);
        self::assertSame(2, $this->fingerprints->calls);
    }

    public function test_configured_origin_is_used_and_helper_failures_become_bounded_409_errors(): void
    {
        config(['app.url' => 'http://mail.example.test']);
        $this->assertDenied($this->selectedTestPayload());
        $this->assertQuarantined($this->payload());
    }

    public function test_unknown_or_duplicated_entry_ids_and_wrong_signature_pair_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['templates'][1]['id'] = '99999999-9999-4999-8999-999999999999';
        $this->assertDenied($payload);
        $payload = $this->payload();
        $payload['templates'][] = $payload['templates'][1];
        $this->assertDenied($payload);
        $payload = $this->payload();
        $payload['templates'][1]['signatureDocumentId'] = self::GLOBAL_SIGNATURE_ID;
        $this->assertQuarantined($payload);
        $payload['automaticTemplateId'] = self::TEST_ID;
        $this->assertDenied($payload);
    }

    public static function invalidSources(): array
    {
        return [
            'unknown value' => ['template', '<table class="rt-shell design-v27" data-rt-static="v2"><tr><td>x</td></tr></table>'],
            'entity value' => ['template', '<table class="rt-shell design-v27" data-rt-static="v&#49;"><tr><td>x</td></tr></table>'],
            'boolean marker' => ['template', '<table class="rt-shell design-v27" data-rt-static><tr><td>x</td></tr></table>'],
            'duplicate marker' => ['template', '<table class="rt-shell design-v27" data-rt-static="v1" data-rt-static="v1"><tr><td>x</td></tr></table>'],
            'duplicate class' => ['template', '<table class="rt-shell design-v27" class="rt-shell design-v27" data-rt-static="v1"><tr><td>x</td></tr></table>'],
            'wrong element' => ['template', '<div class="rt-shell design-v27" data-rt-static="v1">x</div>'],
            'wrong class' => ['template', '<table class="rt-shell design-v26" data-rt-static="v1"><tr><td>x</td></tr></table>'],
            'two candidate shells' => ['template', '<table class="rt-shell design-v27" data-rt-static="v1"><tr><td>x</td></tr></table><table class="rt-shell design-v27"><tr><td>y</td></tr></table>'],
            'quoted template' => ['template', '<blockquote><table class="rt-shell design-v27" data-rt-static="v1"><tr><td>x</td></tr></table></blockquote>'],
            'missing template marker' => ['template', '<table class="rt-shell design-v27"><tr><td>x</td></tr></table>'],
            'missing signature marker' => ['signature', '<tr data-rt-artifact-version="v27"><td>x</td></tr>'],
            'second signature row' => ['signature', '<tr><td>first</td></tr><tr data-rt-artifact-version="v27" data-rt-static="v1"><td>x</td></tr>'],
            'two signature artifacts' => ['signature', '<tr data-rt-artifact-version="v27" data-rt-static="v1"><td>x</td></tr><tr data-rt-artifact-version="v27"><td>y</td></tr>'],
            'wrong signature artifact' => ['signature', '<tr data-rt-artifact-version="v26" data-rt-static="v1"><td>x</td></tr>'],
            'unknown inner marker' => ['signature', '<tr data-rt-artifact-version="v27"><td data-rt-static="v1">x</td></tr>'],
            'quoted signature' => ['signature', '<blockquote><tr data-rt-artifact-version="v27" data-rt-static="v1"><td>x</td></tr></blockquote>'],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_unknown_multiple_or_mixed_published_markers_are_denied(string $kind, string $html): void
    {
        ($kind === 'template' ? $this->template : $this->signature)->updateQuietly(['published_html' => $html]);
        $this->assertDenied($this->selectedTestPayload());
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_offered_test_is_quarantined_without_changing_standard_or_signature_only_bytes(string $kind, string $html): void
    {
        ($kind === 'template' ? $this->template : $this->signature)->updateQuietly(['published_html' => $html]);
        $payload = $this->payload();
        $this->assertQuarantined($payload);

        $standard = $payload;
        $standard['templates'] = [$standard['templates'][0]];
        self::assertSame($standard, OutlookStaticLinkedTestProfiles::payload($standard, $this->user));

        $signatureOnly = $payload;
        $signatureOnly['templates'] = [];
        $signatureOnly['automaticTemplateId'] = null;
        unset($signatureOnly['template'], $signatureOnly['version']['template']);
        self::assertSame($signatureOnly, OutlookStaticLinkedTestProfiles::payload($signatureOnly, $this->user));
    }

    public function test_marked_template_requires_an_explicit_published_signature_pair(): void
    {
        $this->template->updateQuietly(['published_signature_document_id' => null]);
        $this->assertQuarantined($this->payload());
        $this->assertDenied($this->selectedTestPayload());
    }

    public function test_quarantine_reindexes_only_the_list_and_preserves_later_unmarked_entries(): void
    {
        $thirdId = '55555555-5555-4555-8555-555555555555';
        $this->document('template', $thirdId, $this->sourceTemplate(false), [
            'is_outlook_template' => true, 'outlook_released' => true,
            'published_signature_document_id' => MailDocument::query()->where('public_id', self::GLOBAL_SIGNATURE_ID)->value('id'),
        ]);
        $this->signature->updateQuietly(['published_html' => $this->sourceSignature(false)]);
        $payload = $this->payload();
        $third = $this->entry($thirdId, self::GLOBAL_SIGNATURE_ID, false);
        $payload['templates'][] = $third;
        $expected = $payload;
        $expected['templates'] = [$payload['templates'][0], $third];
        self::assertSame($expected, OutlookStaticLinkedTestProfiles::payload($payload, $this->user));
    }

    public static function selectedReferences(): array
    {
        return [['automaticTemplateId'], ['isDefault'], ['active']];
    }

    #[DataProvider('selectedReferences')]
    public function test_each_current_selection_or_alias_reference_to_a_bad_test_still_fails_closed(string $reference): void
    {
        $this->signature->updateQuietly(['published_html' => $this->sourceSignature(false)]);
        $payload = $this->payload();
        if ($reference === 'automaticTemplateId') {
            $payload[$reference] = self::TEST_ID;
        } else {
            $payload['templates'][0][$reference] = false;
            $payload['templates'][1][$reference] = true;
        }
        $this->assertDenied($payload);
    }

    public function test_invalid_offered_media_is_never_returned_as_a_fallback_but_selected_media_is_denied(): void
    {
        $payload = $this->payload();
        $payload['templates'][1]['media'][0]['base64'] = base64_encode('not the approved source bytes');
        $this->assertQuarantined($payload);
        $payload['automaticTemplateId'] = self::TEST_ID;
        $this->assertDenied($payload);
    }

    private function selectedTestPayload(): array
    {
        $payload = $this->payload();
        $payload['automaticTemplateId'] = self::TEST_ID;
        $payload['templates'][0]['isDefault'] = false;
        $payload['templates'][1]['isDefault'] = true;

        return $payload;
    }

    private function assertQuarantined(array $payload): void
    {
        $before = $payload;
        $documents = MailDocument::query()->orderBy('id')->get()->toArray();
        $expected = $payload;
        $expected['templates'] = [$payload['templates'][0]];
        self::assertSame($expected, OutlookStaticLinkedTestProfiles::payload($payload, $this->user));
        self::assertSame($before, $payload);
        self::assertSame($documents, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    private function assertDenied(array $payload, string $code = 'outlook_addin_static_profile_invalid'): void
    {
        try {
            OutlookStaticLinkedTestProfiles::payload($payload, $this->user);
            self::fail('Expected a bounded admission failure.');
        } catch (OutlookAddinException $error) {
            self::assertSame(409, $error->httpStatus);
            self::assertSame($code, $error->errorCode);
            self::assertStringNotContainsString('base64', $error->getMessage());
        }
    }

    private function document(string $kind, string $id, string $html, array $attributes = []): MailDocument
    {
        return MailDocument::withoutEvents(static fn () => MailDocument::query()->create($attributes + [
            'public_id' => $id, 'kind' => $kind, 'status' => 'published', 'name' => 'Synthetic '.$kind,
            'html' => $html, 'published_html' => $html, 'css' => '', 'published_css' => '', 'published_at' => now(),
        ]));
    }

    private function sourceTemplate(bool $marked): string
    {
        return '<table class="rt-shell design-v27"'.($marked ? ' data-rt-static="v1"' : '').'><tr><td>Published template</td></tr></table>';
    }

    private function sourceSignature(bool $marked): string
    {
        return '<tr data-rt-artifact-version="v27"'.($marked ? ' data-rt-static="v1"' : '').'><td>Published signature</td></tr>';
    }

    private function payload(): array
    {
        $default = $this->entry(self::DEFAULT_ID, self::GLOBAL_SIGNATURE_ID, false);
        $default['active'] = $default['isDefault'] = true;

        return ['schema' => 1, 'marker' => 'RT-SIGNATURE-MANAGED-V1', 'metadata' => ['preserve' => 'exact'],
            'signature' => $default['signature'], 'template' => ['html' => $default['html'], 'media' => $default['media']],
            'templates' => [$default, $this->entry(self::TEST_ID, self::TEST_SIGNATURE_ID, true)], 'automaticTemplateId' => self::DEFAULT_ID,
            'version' => ['personal' => substr(self::FINGERPRINT, 0, 16), 'signature' => $default['signatureVersion'], 'template' => $default['version']]];
    }

    private function entry(string $id, string $signatureId, bool $marked): array
    {
        $bytes = file_get_contents(public_path('mail-assets/icon-rt-v19-light.png'));
        $cid = 'railtime-'.substr(hash('sha256', $bytes), 0, 20).'.png';
        $media = [['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($bytes)]];
        $image = '<img src="cid:'.$cid.'" width="44" height="44" alt="">';
        $marker = $marked ? ' data-rt-static="v1"' : '';
        $signatureVersion = str_repeat('a', 16);
        $mobileVersion = str_repeat('b', 16);
        $signature = '<div class="rt-outlook-signature"><table><tr data-rt-artifact-version="v27"'.$marker.'><td>'.$image.'</td></tr></table><!--RT-SIGNATURE-VERSION:'.$signatureVersion.'--></div>';
        $compose = '<div class="rt-outlook-template"><table class="rt-shell design-v27"'.$marker.'><tr><td>'.$image.'</td></tr></table></div>';
        $hash = hash('sha256', $id);

        return ['id' => $id, 'key' => $id, 'name' => 'Synthetic test', 'label' => 'Synthetic test', 'active' => false, 'isDefault' => false,
            'html' => $compose.$signature, 'media' => $media, 'composeHtml' => $compose, 'composeMedia' => $media,
            'combinedComposeHtml' => $compose.$signature, 'combinedComposeMedia' => $media,
            'mobileComposeHtml' => $compose.$signature.'<!--RT-MOBILE-COMPOSE-VERSION:'.$mobileVersion.'-->', 'mobileComposeMedia' => $media,
            'mobileComposeVersion' => $mobileVersion, 'hash' => $hash, 'version' => substr($hash, 0, 16), 'signatureVersion' => $signatureVersion,
            'signatureDocumentId' => $signatureId, 'signature' => ['html' => $signature, 'media' => $media]];
    }
}

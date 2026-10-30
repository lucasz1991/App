<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookOptionalWordingBudget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

final class OutlookOptionalWordingBudgetTest extends TestCase
{
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        [, $this->employee] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        $this->employee->forceFill(['role' => 'staff', 'status' => true, 'email_verified_at' => now()])->saveQuietly();
        config(['app.url' => 'https://mail.example.test']);
        if (! Schema::hasTable('activity_log')) {
            Schema::create('activity_log', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->nullableMorphs('subject');
                $table->string('event')->nullable();
                $table->nullableMorphs('causer');
                $table->json('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();
            });
        }

        $signature = MailDocument::query()->where('kind', 'signature')->where('outlook_default', true)->firstOrFail();
        $signatureHtml = preg_replace('~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~', '$0 data-rt-linked="animated-v1"', $signature->published_html, -1, $count);
        self::assertSame(1, $count);
        $signature->forceFill(['html' => $signatureHtml, 'published_html' => $signatureHtml])->saveQuietly();
        $standard = MailDocument::query()->where('kind', 'template')->where('outlook_default', true)->firstOrFail();
        $html = preg_replace('~<table\b(?=[^>]*\bclass="[^"]*\brt-shell\b)(?=[^>]*\bclass="[^"]*\bdesign-v27\b)~', '$0 data-rt-linked="animated-v1"', $standard->published_html, -1, $count);
        self::assertSame(1, $count);
        $standard->forceFill(['html' => $html, 'published_html' => $html])->saveQuietly();
        foreach (MailDocument::query()->where('kind', 'template')->whereKeyNot($standard->id)->get() as $other) {
            $other->forceFill(['outlook_released' => false, 'outlook_default' => null, 'is_active' => null])->saveQuietly();
        }
        app()->forgetScopedInstances();
        Auth::login($this->employee);
        app()->bind(OutlookAddinUserSnapshotStore::class, static fn () => throw new \LogicException('Candidate rendering must not persist/cache a user artifact.'));
    }

    private function settings(): string
    {
        return DB::table('settings')->orderBy('id')->get()->toJson();
    }

    public function test_candidate_override_is_pure_null_and_disabled_paths_remain_byte_identical(): void
    {
        $service = app(OutlookAddinPayloadService::class);
        $before = $service->forUser($this->employee);
        self::assertSame($before, $service->forUser($this->employee, null));
        $candidate = app(OutlookAddinWordingPolicy::class)->readForUser($this->employee);
        self::assertSame($before, $service->forUser($this->employee, $candidate));
        $settings = $this->settings();
        $documents = DB::table('mail_documents')->orderBy('id')->get()->toJson();
        $writes = [];
        DB::listen(static function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        app(OutlookOptionalWordingBudget::class)->validate($this->employee, $candidate + ['unused' => true]);
        $candidate['showGreeting'] = $candidate['showFarewell'] = true;
        $candidate['greeting'] = $candidate['farewell'] = str_repeat('&', 120);
        app(OutlookOptionalWordingBudget::class)->validate($this->employee, $candidate);
        self::assertSame([], $writes);
        self::assertSame($settings, $this->settings());
        self::assertSame($documents, DB::table('mail_documents')->orderBy('id')->get()->toJson());
    }

    public function test_extreme_name_baseline_signature_still_fits_but_unrenderable_new_wording_is_rejected_before_any_write(): void
    {
        UserProfile::query()->where('user_id', $this->employee->id)->firstOrFail()->forceFill(['first_name' => str_repeat('"', 120), 'last_name' => str_repeat("'", 120)])->saveQuietly();
        $employee = $this->employee->fresh();
        $service = app(OutlookAddinPayloadService::class);
        $baseline = OutlookClientPresentation::payload(OutlookMobileSignature::payload($service->forUser($employee)));
        $signature = OutlookLinkedAnimatedDelivery::entry(['kind' => 'signature', 'html' => $baseline['signature']['html'],
            'media' => $baseline['signature']['media'], 'version' => $baseline['version']['signature']], OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test');
        self::assertLessThan(30000, intdiv(strlen(mb_convert_encoding($signature['html'], 'UTF-16LE', 'UTF-8')), 2) + 168);
        $before = $this->settings();
        $policy = app(OutlookAddinWordingPolicy::class);
        $current = $policy->readForUser($employee);
        try {
            $policy->updateForSession($employee, str_repeat('😀', 120), str_repeat('🚂', 120), $current['revision'], true, true);
            self::fail('An unrenderable candidate must not be persisted.');
        } catch (ValidationException $error) {
            self::assertSame(422, $error->status);
            self::assertStringContainsString('zu lang für die mobile Signatur', $error->errors()['wording'][0]);
        }
        self::assertSame($before, $this->settings());
        self::assertSame($current, $policy->readForUser($employee));
        self::assertSame(0, DB::table('activity_log')->count());
        // Disabling cannot be blocked by publication/budget validation.
        $policy->updateForSession($employee, $current['greeting'], $current['farewell'], $current['revision'], false, false);
        self::assertFalse($policy->readForUser($employee)['showGreeting']);
    }
}

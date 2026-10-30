<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\OutlookLegacyWordingMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookLegacyWordingMigrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $employee;

    private MailDocument $legacy;

    private MailDocument $standard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
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
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true, 'email_verified_at' => now()]);
        $this->employee = User::factory()->create(['role' => 'staff']);
        $this->legacy = MailDocument::create(['kind' => MailDocumentKind::Template, 'name' => 'Legacy', 'html' => '<p>Draft unchanged</p>',
            'published_html' => '<p>Published legacy</p>', 'css' => '', 'published_css' => '', 'version' => 1, 'builder_data' => [], 'content_hash' => str_repeat('a', 64)]);
        $this->standard = MailDocument::create(['kind' => MailDocumentKind::Template, 'name' => 'Standard', 'html' => '<p>Blank draft</p>',
            'published_html' => '<p>Blank published</p>', 'css' => '', 'published_css' => '', 'version' => 1, 'builder_data' => [], 'content_hash' => str_repeat('a', 64)]);
        Setting::setValue('outlook_addin', 'template_choices_user_'.$this->employee->id, ['schema' => 1,
            'newMessages' => ['mode' => 'document', 'documentId' => $this->legacy->public_id],
            'replies' => ['mode' => 'none', 'documentId' => null], 'keep' => ['unchanged' => true]]);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->employee->id, ['schema' => 1, 'newMessages' => ['signature' => false, 'template' => false]]);
    }

    private function preview(): array
    {
        return app(OutlookLegacyWordingMigration::class)->preview($this->actor, $this->legacy->public_id, $this->standard->public_id);
    }

    public function test_preview_is_read_only_and_apply_preserves_automatic_preferences_documents_custom_texts_and_explicit_flags(): void
    {
        $key = 'template_wording_user_'.$this->employee->id;
        Setting::setValue('outlook_addin', $key, ['schema' => 1, 'greeting' => 'Hallo Team,', 'farewell' => 'Beste Grüße,', 'showGreeting' => false, 'keep' => 'exact']);
        $documents = DB::table('mail_documents')->get()->toJson();
        $before = DB::table('settings')->get()->toJson();
        $automatic = Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->employee->id);
        $plan = $this->preview();
        self::assertSame($before, DB::table('settings')->get()->toJson());
        self::assertSame(0, DB::table('activity_log')->count());
        self::assertSame(['showFarewell'], $plan['accounts'][0]['initializeVisibility']);
        self::assertStringNotContainsString('Hallo Team', json_encode($plan));
        app(OutlookLegacyWordingMigration::class)->apply($this->actor, $this->legacy->public_id, $this->standard->public_id, $plan['planHash']);
        $wording = Setting::getValueUncached('outlook_addin', $key);
        self::assertFalse($wording['showGreeting']);
        self::assertTrue($wording['showFarewell']);
        self::assertSame('Hallo Team,', $wording['greeting']);
        self::assertSame('exact', $wording['keep']);
        $choice = Setting::getValueUncached('outlook_addin', 'template_choices_user_'.$this->employee->id);
        self::assertSame($this->standard->public_id, $choice['newMessages']['documentId']);
        self::assertSame(['mode' => 'none', 'documentId' => null], $choice['replies']);
        self::assertSame(['unchanged' => true], $choice['keep']);
        self::assertSame($automatic, Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->employee->id));
        self::assertSame($documents, DB::table('mail_documents')->get()->toJson());
        self::assertSame(0, $this->preview()['accountCount']);
    }

    public function test_apply_initializes_missing_legacy_flags_and_can_roll_back_with_outer_archive_transaction(): void
    {
        $plan = $this->preview();
        try {
            DB::transaction(function () use ($plan): void {
                app(OutlookLegacyWordingMigration::class)->apply($this->actor, $this->legacy->public_id, $this->standard->public_id, $plan['planHash']);
                $wording = Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->employee->id);
                self::assertTrue($wording['showGreeting']);
                self::assertTrue($wording['showFarewell']);
                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException $error) {
            self::assertSame('outer rollback', $error->getMessage());
        }
        self::assertSame($plan, $this->preview());
        self::assertSame(0, DB::table('activity_log')->count());
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->employee->id));
    }

    public function test_plain_legacy_template_remaps_without_initializing_any_wording_and_the_plan_binds_this_mode(): void
    {
        $service = app(OutlookLegacyWordingMigration::class);
        $plan = $service->preview($this->actor, $this->legacy->public_id, $this->standard->public_id, initializeWording: false);
        self::assertSame([], $plan['accounts'][0]['initializeVisibility']);
        self::assertNotSame($plan['planHash'], $this->preview()['planHash']);
        $service->apply($this->actor, $this->legacy->public_id, $this->standard->public_id, $plan['planHash'], initializeWording: false);
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->employee->id));
        self::assertSame($this->standard->public_id, Setting::getValueUncached('outlook_addin', 'template_choices_user_'.$this->employee->id)['newMessages']['documentId']);
    }

    public function test_stale_plan_rejects_all_writes_and_ineligible_operator_cannot_preview(): void
    {
        $plan = $this->preview();
        Setting::setValue('outlook_addin', 'unrelated', ['changed' => true]);
        $before = DB::table('settings')->get()->toJson();
        try {
            app(OutlookLegacyWordingMigration::class)->apply($this->actor, $this->legacy->public_id, $this->standard->public_id, $plan['planHash']);
            self::fail('Stale CAS must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Dry-run', $error->getMessage());
        }
        self::assertSame($before, DB::table('settings')->get()->toJson());
        self::assertSame(0, DB::table('activity_log')->count());
        $this->expectException(RuntimeException::class);
        app(OutlookLegacyWordingMigration::class)->preview($this->employee, $this->legacy->public_id, $this->standard->public_id);
    }
}

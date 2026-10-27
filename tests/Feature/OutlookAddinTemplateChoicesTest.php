<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\Mail\TemplateDocumentContract;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAddinTemplateChoicesPolicy;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookAddinTemplateChoicesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const URL = '/api/outlook-addin/account-template-choices';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    private const OBJECT = '33333333-3333-4333-8333-333333333333';

    private ?string $privateKey = null;

    private MailDocument $a;

    private MailDocument $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        Storage::fake('private');
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        config(['outlook_addin.enabled' => true, 'outlook_addin.deployed' => true,
            'outlook_addin.addin_id' => self::CLIENT, 'outlook_addin.base_url' => 'https://example.test',
            'outlook_addin.entra.tenant_id' => self::TENANT, 'outlook_addin.entra.client_id' => self::CLIENT,
            'outlook_addin.entra.audience' => self::CLIENT, 'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => 'api://'.self::CLIENT.'/Signature.Read']);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            foreach (['external_id', 'tenant_id', 'principal', 'email', 'lifecycle_status'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        foreach ([5, 6] as $id) {
            User::factory()->create(['id' => $id, 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        }
        EmployeeIdentityAccount::create(['user_id' => 5, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'own@company.test',
            'email' => 'alias@company.test', 'lifecycle_status' => 'active']);
        $this->library();
        $this->app->bind(EmployeeWorkplaceProvisioner::class, static fn () => throw new \LogicException('Wording API must never activate accounts.'));
        $this->app->bind(OutlookAddinPayloadService::class, static fn () => throw new \LogicException('Wording API must never compile mail.'));
    }

    public function test_missing_choice_row_preserves_legacy_template_bools_without_writes(): void
    {
        $before = $this->rows();
        $this->getJson(self::URL, $this->headers())->assertOk()
            ->assertJsonPath('templateChoicesPreferences.newMessages.selection', 'default')
            ->assertJsonPath('templateChoicesPreferences.replies.selection', 'default');
        self::assertSame($before, $this->rows());
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['schema' => 2,
            'newMessages' => ['signature' => true, 'template' => false], 'replies' => ['signature' => false, 'template' => true]]);
        $this->getJson(self::URL, $this->headers())->assertOk()
            ->assertJsonPath('templateChoicesPreferences.newMessages.selection', 'none')
            ->assertJsonPath('templateChoicesPreferences.replies.selection', 'default');
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_choices_user_5'));
    }

    public function test_context_a_b_and_none_are_independent_sync_only_legacy_template_bool_and_preserve_metadata(): void
    {
        Setting::setValue('outlook_addin', 'template_choices_user_5', ['schema' => 1, 'keep' => ['exact' => 'metadata'],
            'newMessages' => ['mode' => 'default', 'documentId' => null, 'keep' => 'nested']]);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['schema' => 2, 'keep' => 'automation',
            'newMessages' => ['signature' => false, 'template' => true], 'replies' => ['signature' => true, 'template' => true]]);
        Setting::setValue('outlook_addin', 'template_choices_user_6', ['schema' => 1, 'keep' => 'other']);
        $before = $this->rows();
        $this->choose('newMessages', $this->a->public_id)->assertOk();
        $this->choose('replies', $this->b->public_id)->assertOk();
        $response = $this->choose('newMessages', 'none')->assertOk()
            ->assertJsonPath('templateChoicesPreferences.newMessages.selection', 'none')
            ->assertJsonPath('templateChoicesPreferences.replies.selection', $this->b->public_id)
            ->assertJsonPath('automaticInsertionPreferences.newMessages.template', false)
            ->assertJsonPath('automaticInsertionPreferences.newMessages.signature', false)
            ->assertJsonPath('automaticInsertionPreferences.replies.signature', true);
        $stored = Setting::getValueUncached('outlook_addin', 'template_choices_user_5');
        self::assertSame(['exact' => 'metadata'], $stored['keep']);
        self::assertSame('nested', $stored['newMessages']['keep']);
        $after = $this->rows();
        foreach (['users', 'identities', 'documents'] as $key) {
            self::assertSame($before[$key], $after[$key]);
        }
        self::assertSame(['schema' => 1, 'keep' => 'other'], Setting::getValueUncached('outlook_addin', 'template_choices_user_6'));
        self::assertSame('automation', Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_5')['keep']);
        self::assertSame(3, DB::table('activity_log')->where('description', 'outlook_addin_template_choice_changed')->count());
        $this->choose('newMessages', 'none')->assertOk();
        self::assertSame($after, $this->rows());
    }

    public function test_stale_revision_or_unreleased_signature_foreign_document_and_unknown_fields_never_write(): void
    {
        $revision = $this->revision();
        $this->choose('newMessages', $this->a->public_id)->assertOk();
        $before = $this->rows();
        $this->patchJson(self::URL, ['group' => 'replies', 'selection' => $this->b->public_id, 'revision' => $revision], $this->headers())
            ->assertStatus(409)->assertJsonPath('error', 'outlook_addin_template_choices_conflict')
            ->assertJsonPath('templateChoicesPreferences.newMessages.selection', $this->a->public_id);
        self::assertSame($before, $this->rows());
        $this->b->forceFill(['outlook_released' => false])->saveQuietly();
        $signature = MailDocument::query()->where('kind', 'signature')->sole();
        foreach ([$this->b->public_id, $signature->public_id, (string) Str::uuid(), 'invalid'] as $selection) {
            $before = $this->rows();
            $this->choose('replies', $selection)->assertUnprocessable()->assertJsonPath('error', 'outlook_addin_template_choices_invalid');
            self::assertSame($before, $this->rows());
        }
        $before = $this->rows();
        $this->patchJson(self::URL, ['group' => 'replies', 'selection' => 'none', 'revision' => $this->revision(), 'user_id' => 6], $this->headers())->assertUnprocessable();
        self::assertSame($before, $this->rows());
    }

    public function test_a_failure_after_legacy_bool_update_rolls_back_both_settings_and_audits(): void
    {
        $revision = $this->revision();
        $before = $this->rows();
        Event::listen('eloquent.saving: '.Setting::class, static function (Setting $setting): void {
            if ($setting->key === 'template_choices_user_5') {
                throw new \RuntimeException('Synthetic rollback probe');
            }
        });
        $this->patchJson(self::URL, ['group' => 'newMessages', 'selection' => 'none', 'revision' => $revision], $this->headers())->assertStatus(500);
        self::assertSame($before, $this->rows());
    }

    public function test_authentication_context_mailbox_and_target_cannot_be_overridden(): void
    {
        $before = $this->rows();
        $this->actingAs(User::findOrFail(5));
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL, array_replace($this->headers(), ['X-RailTime-Outlook-Context' => 'taskpane']))->assertForbidden();
        $this->getJson(self::URL, array_replace($this->headers(), ['X-RailTime-Outlook-Mailbox' => 'foreign@company.test']))->assertForbidden();
        $this->getJson(self::URL.'?user_id=6', $this->headers())->assertUnprocessable();
        self::assertSame($before, $this->rows());
        EmployeeIdentityAccount::query()->whereKey(1)->update(['lifecycle_status' => 'inactive']);
        $before = $this->rows();
        $this->choose('newMessages', 'none')->assertForbidden();
        self::assertSame($before, $this->rows());
    }

    public function test_actual_v3_bootstrap_compiles_different_context_templates_and_none_without_touching_cached_library(): void
    {
        $this->choose('newMessages', $this->a->public_id)->assertOk();
        $this->choose('replies', $this->b->public_id)->assertOk();
        // Bootstrap deliberately uses the real compiler/cache; API tests above retain tripwires.
        $this->app->bind(EmployeeWorkplaceProvisioner::class, EmployeeWorkplaceProvisioner::class);
        $this->app->bind(OutlookAddinPayloadService::class, OutlookAddinPayloadService::class);
        $documents = $this->rows()['documents'];
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $canonical = $snapshots->currentForUser(User::findOrFail(5));
        $cached = Storage::disk('private')->get($snapshots->pathForUser(5));
        foreach (['newMail' => [$this->a, 'CHOICE-A-VISIBLE'], 'forward' => [$this->a, 'CHOICE-A-VISIBLE'], 'reply' => [$this->b, 'CHOICE-B-VISIBLE']] as $type => [$document,$text]) {
            $response = $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders($type))->assertOk();
            $response->assertJsonPath('automaticTemplateId', $document->public_id)
                ->assertJsonPath('automaticTemplateChoice.templateId', $document->public_id);
            $selected = array_values(array_filter($response->json('templates'), static fn (array $row): bool => $row['isDefault'] === true));
            self::assertCount(1, $selected);
            self::assertSame($document->public_id, $selected[0]['id']);
            self::assertStringContainsString($text, $selected[0]['composeHtml']);
            self::assertStringContainsString($text, $selected[0]['combinedComposeHtml']);
        }
        $manual = $this->getJson('/api/outlook-addin/bootstrap', array_replace($this->eventHeaders('newMail'), ['X-RailTime-Outlook-Context' => 'taskpane']))->assertOk();
        $manual->assertJsonPath('automaticTemplateId', $canonical['automaticTemplateId']);
        self::assertArrayNotHasKey('automaticTemplateChoice', $manual->json());
        $this->choose('replies', 'none')->assertOk();
        $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders('reply'))->assertOk()
            ->assertJsonPath('templates', [])->assertJsonPath('automaticTemplateId', null)
            ->assertJsonPath('automaticInsertion.template', false);
        foreach (['1', '2'] as $old) {
            $legacy = $this->getJson('/api/outlook-addin/bootstrap', array_replace($this->eventHeaders('newMail'), ['X-RailTime-Automatic-Policy' => $old]))->assertOk();
            $legacy->assertJsonPath('automaticInsertionEnabled', false);
            self::assertArrayNotHasKey('templates', $legacy->json());
        }
        self::assertSame($cached, Storage::disk('private')->get($snapshots->pathForUser(5)));
        self::assertSame($documents, $this->rows()['documents']);
    }

    public function test_withdrawn_saved_choice_is_visible_but_never_silently_substituted_in_events(): void
    {
        $this->choose('replies', $this->b->public_id)->assertOk();
        $this->b->forceFill(['outlook_released' => false])->saveQuietly();
        $this->getJson(self::URL, $this->headers())->assertOk()
            ->assertJsonPath('templateChoicesPreferences.replies.selection', $this->b->public_id)
            ->assertJsonPath('templateChoicesPreferences.replies.available', false);
        $this->app->bind(EmployeeWorkplaceProvisioner::class, EmployeeWorkplaceProvisioner::class);
        $this->app->bind(OutlookAddinPayloadService::class, OutlookAddinPayloadService::class);
        $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders('reply'))->assertOk()
            ->assertJsonPath('automaticTemplateChoice.selection', 'none')
            ->assertJsonPath('automaticInsertion.template', false)->assertJsonPath('templates', []);
    }

    public static function unavailableDefaultLibraries(): array
    {
        return ['default flag removed' => [false], 'no published templates' => [true]];
    }

    #[DataProvider('unavailableDefaultLibraries')]
    public function test_missing_released_default_never_falls_back_but_keeps_the_signature_allowed(bool $withdrawAll): void
    {
        MailDocument::query()->where('kind', 'template')->update($withdrawAll ? ['outlook_released' => false] : ['outlook_default' => null]);
        $before = $this->rows();
        $this->getJson(self::URL, $this->headers())->assertOk()
            ->assertJsonPath('templateChoicesPreferences.newMessages.selection', 'default')
            ->assertJsonPath('templateChoicesPreferences.newMessages.available', false);
        $this->choose('newMessages', 'default')->assertUnprocessable();
        self::assertSame($before, $this->rows());
        $this->app->bind(EmployeeWorkplaceProvisioner::class, EmployeeWorkplaceProvisioner::class);
        $this->app->bind(OutlookAddinPayloadService::class, OutlookAddinPayloadService::class);
        $response = $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders('newMail'))->assertOk()
            ->assertJsonPath('automaticInsertion.signature', true)->assertJsonPath('automaticInsertion.template', false)
            ->assertJsonPath('automaticTemplateChoice.selection', 'none')->assertJsonPath('templates', []);
        self::assertNotEmpty($response->json('signature.html'));
        self::assertArrayNotHasKey('template', $response->json());
        self::assertSame($before['documents'], $this->rows()['documents']);
    }

    private function revision(): string
    {
        return app(OutlookAddinTemplateChoicesPolicy::class)->preferencesForUser(User::findOrFail(5))['revision'];
    }

    private function choose(string $group, string $selection): TestResponse
    {
        return $this->patchJson(self::URL, ['group' => $group, 'selection' => $selection, 'revision' => $this->revision()], $this->headers());
    }

    private function eventHeaders(string $type): array
    {
        return array_replace($this->headers(), ['X-RailTime-Outlook-Context' => 'event', 'X-RailTime-Outlook-Sender' => 'own@company.test',
            'X-RailTime-Automatic-Policy' => '3', 'X-RailTime-Compose-Type' => $type]);
    }

    private function rows(): array
    {
        $result = [];
        foreach (['users' => 'users', 'identities' => 'employee_identity_accounts', 'settings' => 'settings', 'audits' => 'activity_log', 'documents' => 'mail_documents'] as $key => $table) {
            $result[$key] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }

    private function library(): void
    {
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php', '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php', '2026_09_08_120000_add_mail_document_signature_pairing.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        // Reuse the established synthetic canonical fixture, not a live source or renderer mock.
        $case = new EmailTemplatesPageTest('test_outlook_addin_validates_a_real_entra_rs256_access_token');
        (new ReflectionMethod($case, 'createCanonicalMailDocuments'))->invoke($case);
        DB::table('mail_documents')->update(['outlook_default' => true]);
        DB::table('mail_documents')->where('kind', 'template')->update(['outlook_released' => true]);
        // The current V27 fixture exercises native standalone + combined compilers,
        // whereas the shared bootstrap helper also covers older legacy families.
        $family = new \ReflectionClass(V27PersonalLayoutTest::class);
        $signature = str_replace('design-train-logo', 'design-train-logo design-personal-columns', $family->getConstant('SOURCE'));
        MailDocument::query()->where('kind', 'signature')->update(['html' => $signature, 'published_html' => $signature]);
        $this->a = MailDocument::query()->where('kind', 'template')->sole();
        $source = preg_replace('~</td>~', '<span>CHOICE-A-VISIBLE</span></td>', $family->getConstant('TEMPLATE'), 1);
        TemplateDocumentContract::assertValid($source);
        $this->a->forceFill(['name' => 'Synthetic A', 'html' => $source, 'published_html' => $source,
            'css' => $family->getConstant('TEMPLATE_CSS'), 'published_css' => $family->getConstant('TEMPLATE_CSS')])->saveQuietly();
        $this->b = $this->a->replicate(['public_id', 'is_active', 'outlook_default']);
        $source = str_replace('CHOICE-A-VISIBLE', 'CHOICE-B-VISIBLE', $source);
        $this->b->forceFill(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic B', 'is_active' => null, 'outlook_default' => null,
            'html' => $source, 'published_html' => $source, 'outlook_released' => true])->saveQuietly();
        $this->app->forgetScopedInstances();
    }

    private function headers(): array
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertTrue(openssl_pkey_export($key, $private));
            $this->privateKey = $private;
            $details = openssl_pkey_get_details($key);
            $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            Http::fake(['https://login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys' => Http::response(['keys' => [[
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'template-choices-test', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => 'own@company.test'], $this->privateKey, 'RS256', 'template-choices-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => 'own@company.test',
            'X-RailTime-Compose-Contract' => 'native-signature-v1', 'X-RailTime-Outlook-Context' => 'account-settings'];
    }
}

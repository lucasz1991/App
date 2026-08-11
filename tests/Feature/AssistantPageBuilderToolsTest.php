<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Livewire\Tools\Chatbot;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\MailDocument;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Ai\AssistantPageBuilderTools;
use App\Services\Ai\AssistantPendingActionStore;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingStudioService;
use App\Support\PageHelpCatalog;
use Database\Seeders\MailDocumentSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class AssistantPageBuilderToolsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
        config()->set('app.url', 'https://railtime.test');
    }

    public function test_tools_exist_only_for_admins_on_the_two_exact_editor_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $tools = app(AssistantPageBuilderTools::class);

        $marketingNames = $this->toolNames($tools->toolDefinitions($admin, 'admin.marketing.creatives.editor'));
        $mailNames = $this->toolNames($tools->toolDefinitions($admin, 'admin.mail-documents.editor'));

        $this->assertContains(AssistantPageBuilderTools::STATUS_TOOL, $marketingNames);
        $this->assertContains(AssistantPageBuilderTools::IMAGE_SEARCH_TOOL, $marketingNames);
        $this->assertContains(AssistantPageBuilderTools::IMAGE_TOOL, $marketingNames);
        $this->assertContains(AssistantPageBuilderTools::REDESIGN_TOOL, $marketingNames);
        $this->assertContains(AssistantPageBuilderTools::STATUS_TOOL, $mailNames);
        $this->assertNotContains(AssistantPageBuilderTools::IMAGE_SEARCH_TOOL, $mailNames);
        $this->assertNotContains(AssistantPageBuilderTools::IMAGE_TOOL, $mailNames);
        $this->assertNotContains(AssistantPageBuilderTools::REDESIGN_TOOL, $mailNames);
        $this->assertSame([], $tools->toolDefinitions($staff, 'admin.marketing.creatives.editor'));
        $this->assertSame([], $tools->toolDefinitions($admin, 'admin.marketing.creatives.index'));
        $this->assertSame([], $tools->toolDefinitions($admin, 'email-templates.index'));
    }

    public function test_unsaved_client_revisions_are_allowed_but_effects_are_bound_to_the_exact_browser_revision_and_selection(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $tools = app(AssistantPageBuilderTools::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 37, unsaved: true);

        $safe = $tools->normalizeContext($admin, 'admin.marketing.creatives.editor', $context);
        $this->assertSame(37, $safe['client_revision']);
        $this->assertSame($variant->version, $safe['persisted_version']);
        $this->assertTrue($safe['unsaved']);

        $result = $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => 'Sicher auf der Schiene'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        );

        $this->assertSame('scheduled', $result['payload']['state']);
        $this->assertSame(37, $result['effect']['client_revision']);
        $this->assertSame($variant->version, $result['effect']['persisted_version']);
        $this->assertNotSame($result['effect']['client_revision'], $result['effect']['persisted_version']);
        $this->assertNotNull($tools->normalizeBrowserEffect(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
        ));
        $this->assertTrue($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $context,
        ));

        $newLocalRevision = $context;
        $newLocalRevision['client_revision']++;
        $this->assertFalse($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $newLocalRevision,
        ));

        $newSelection = $context;
        $newSelection['selection']['fingerprint'] = str_repeat('b', 64);
        $this->assertFalse($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $newSelection,
        ));
    }

    public function test_complete_marketing_redesign_is_one_document_wide_confirmed_effect(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 41, unsaved: true);

        $result = $tools->execute(
            AssistantPageBuilderTools::REDESIGN_TOOL,
            ['preset' => 'railtime_modern'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        );

        $this->assertSame('scheduled', $result['payload']['state']);
        $this->assertSame('redesign_document', $result['effect']['command']);
        $this->assertSame('railtime_modern', $result['effect']['preset']);
        $this->assertSame(41, $result['effect']['client_revision']);
        $this->assertArrayNotHasKey('selection_nonce', $result['effect']);
        $this->assertArrayNotHasKey('selection_fingerprint', $result['effect']);
        $this->assertTrue($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $context,
        ));

        $otherSelection = $context;
        $otherSelection['selection']['fingerprint'] = str_repeat('b', 64);
        $this->assertTrue($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $otherSelection,
        ));

        $newerWorkspace = $context;
        $newerWorkspace['client_revision']++;
        $this->assertFalse($tools->browserEffectMatchesContext(
            $admin,
            'admin.marketing.creatives.editor',
            $result['effect'],
            $newerWorkspace,
        ));

        $withoutCapability = $context;
        $withoutCapability['capabilities'] = array_values(array_diff(
            $withoutCapability['capabilities'],
            ['redesign_document'],
        ));
        $this->assertSame('capability_unavailable', $tools->execute(
            AssistantPageBuilderTools::REDESIGN_TOOL,
            ['preset' => 'railtime_modern'],
            $admin,
            'admin.marketing.creatives.editor',
            $withoutCapability,
        )['payload']['error']);

        $this->assertSame('invalid_redesign_preset', $tools->execute(
            AssistantPageBuilderTools::REDESIGN_TOOL,
            ['preset' => 'provider_invented'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);

        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $result['effect']);
        $this->assertStringContainsString('Komplettes RailTime-Redesign', $pending['label']);
        $this->assertStringContainsString('Story (1080 × 1920)', $pending['detail']);
        $this->assertStringContainsString('Post (1080 × 1080)', $pending['detail']);
        $this->assertStringContainsString('Web (1200 × 630)', $pending['detail']);
        $this->assertStringContainsString('gemeinsame Texte', $pending['detail']);
        $this->assertStringContainsString('Freigabe wird zurückgesetzt', $pending['detail']);
        $this->assertStringContainsString('keine Veröffentlichung', $pending['detail']);
    }

    public function test_server_rejects_stale_persisted_state_and_adversarial_editor_values(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $tools = app(AssistantPageBuilderTools::class);
        $context = $this->marketingContext($creative, $variant);

        $this->assertSame('invalid_text', $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => '<img src=x onerror=alert(1)>'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('invalid_style', $tools->execute(
            AssistantPageBuilderTools::STYLE_TOOL,
            ['property' => 'background-color', 'value' => 'url(https://evil.test/a.png)'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('invalid_style', $tools->execute(
            AssistantPageBuilderTools::STYLE_TOOL,
            ['property' => 'position', 'value' => 'fixed'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('invalid_animation', $tools->execute(
            AssistantPageBuilderTools::ANIMATION_TOOL,
            ['field' => 'framerate', 'value' => 60],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);

        $variant->forceFill([
            'version' => $variant->version + 1,
            'content_hash' => str_repeat('c', 64),
        ])->save();

        $this->assertSame([], $tools->normalizeContext($admin, 'admin.marketing.creatives.editor', $context));
    }

    public function test_mail_tokens_signature_structure_and_image_scope_are_immutable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        (new MailDocumentSeeder)->run();
        $document = MailDocument::query()->where('kind', MailDocumentKind::Signature->value)->firstOrFail();
        $tools = app(AssistantPageBuilderTools::class);
        $context = $this->mailContext($document);
        $context['selection']['gif'] = true;

        $safe = $tools->normalizeContext($admin, 'admin.mail-documents.editor', $context);
        $this->assertTrue($safe['selection']['protected']);
        $this->assertFalse($safe['selection']['motion_allowed']);
        $this->assertTrue($safe['selection']['gif']);
        $this->assertSame('restart_gif', $tools->execute(
            AssistantPageBuilderTools::PREVIEW_TOOL,
            ['action' => 'restart_gif'],
            $admin,
            'admin.mail-documents.editor',
            $context,
        )['effect']['command']);
        $this->assertSame('protected_selection', $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => 'Neuer Name'],
            $admin,
            'admin.mail-documents.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('invalid_text', $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => 'Hallo {{VORNAME_NACHNAME}}'],
            $admin,
            'admin.mail-documents.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('invalid_text', $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => '&#123;&#123;EMPFAENGER_E_MAIL&#125;&#125;'],
            $admin,
            'admin.mail-documents.editor',
            $context,
        )['payload']['error']);
        $this->assertSame('unknown_tool', $tools->execute(
            AssistantPageBuilderTools::IMAGE_TOOL,
            ['file_id' => 1],
            $admin,
            'admin.mail-documents.editor',
            $context,
        )['payload']['error']);

        $unprotected = $context;
        $unprotected['selection']['block_id'] = 'rt-mail-paragraph';
        $unprotected['selection']['text'] = 'Ein normaler Absatz';
        $unprotected['selection']['protected'] = false;
        $this->assertSame('invalid_block', $tools->execute(
            AssistantPageBuilderTools::BLOCK_TOOL,
            ['block_id' => 'rt-mail-image', 'position' => 'after'],
            $admin,
            'admin.mail-documents.editor',
            $unprotected,
        )['payload']['error']);
    }

    public function test_marketing_image_search_and_replace_never_escape_the_selected_filepool_folder(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $pool = FilePool::company();
        $selected = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Marketing']);
        $outside = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Intern']);
        $allowed = $this->createImageFile($pool, $selected, $admin, 'wagenmeister.png', 0x17324D);
        $blocked = $this->createImageFile($pool, $outside, $admin, 'intern.png', 0x981B2D);
        app(MarketingFileSourceService::class)->setSelectedFolder($selected->id, $admin);

        $tools = app(AssistantPageBuilderTools::class);
        $context = $this->marketingContext($creative, $variant, selectionTag: 'img');
        $search = $tools->execute(
            AssistantPageBuilderTools::IMAGE_SEARCH_TOOL,
            ['query' => 'wagen'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        );

        $this->assertSame(12, $search['payload']['limit']);
        $this->assertSame([[
            'file_id' => $allowed->id,
            'name' => 'wagenmeister.png',
            'category' => 'Marketing',
            'width' => 12,
            'height' => 12,
        ]], $search['payload']['images']);
        $this->assertArrayNotHasKey('src', $search['payload']['images'][0]);
        $this->assertSame('image_scope_denied', $tools->execute(
            AssistantPageBuilderTools::IMAGE_TOOL,
            ['file_id' => $blocked->id],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['payload']['error']);

        $replace = $tools->execute(
            AssistantPageBuilderTools::IMAGE_TOOL,
            ['file_id' => $allowed->id],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        );
        $this->assertSame('scheduled', $replace['payload']['state']);
        $this->assertSame($allowed->id, $replace['effect']['file_id']);
        $this->assertArrayNotHasKey('url', $replace['effect']);
        $this->assertArrayNotHasKey('src', $replace['effect']);
    }

    public function test_pending_actions_recheck_local_revision_and_issue_a_single_use_receipt(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 9);
        $effect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];
        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $effect);

        $stale = $context;
        $stale['client_revision'] = 10;
        $this->assertNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $pending['token'],
            [],
            $stale,
        ));

        $freshEffect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $stale,
        )['effect'];
        $freshPending = $store->create($admin, 'admin.marketing.creatives.editor', $freshEffect);
        $consumed = $store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $freshPending['token'],
            [],
            $stale,
        );

        $this->assertSame('save', $consumed['command']);
        $this->assertNull($store->acceptReceipt(
            $admin,
            $freshPending['token'],
            'applied',
            'admin.marketing.creatives.editor',
        ));
        $claimed = $store->claimPageBuilderEffect($admin, $freshPending['token']);
        $this->assertSame('save', $claimed['command']);
        $this->assertSame($freshPending['token'], $claimed['action_token']);
        $this->assertNull($store->claimPageBuilderEffect($admin, $freshPending['token']));
        $this->assertNotNull($store->acceptReceipt(
            $admin,
            $freshPending['token'],
            'applied',
            'admin.marketing.creatives.editor',
        ));
        $this->assertNull($store->acceptReceipt(
            $admin,
            $freshPending['token'],
            'applied',
            'admin.marketing.creatives.editor',
        ));

        $uncertainEffect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $stale,
        )['effect'];
        $uncertainPending = $store->create($admin, 'admin.marketing.creatives.editor', $uncertainEffect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $uncertainPending['token'],
            [],
            $stale,
        ));
        $this->assertNotNull($store->claimPageBuilderEffect($admin, $uncertainPending['token']));
        $uncertainReceipt = $store->acceptReceipt(
            $admin,
            $uncertainPending['token'],
            'reload_required',
            'admin.marketing.creatives.editor',
        );
        $this->assertSame('reload_required', $uncertainReceipt['status'] ?? null);
    }

    public function test_confirmed_pagebuilder_effect_is_claimed_only_through_the_authenticated_one_time_endpoint(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 6);
        $effect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];
        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $pending['token'],
            [],
            $context,
        ));

        $this->postJson(route('assistant.pagebuilder-actions.claim'), [
            'action_token' => str_repeat('F', 48),
        ])->assertUnprocessable();

        $response = $this->postJson(route('assistant.pagebuilder-actions.claim'), [
            'action_token' => $pending['token'],
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('action.type', AssistantPageBuilderTools::EFFECT_TYPE)
            ->assertJsonPath('action.command', 'save')
            ->assertJsonPath('action.action_token', $pending['token']);
        $this->assertArrayNotHasKey('html', $response->json('action'));
        $this->assertArrayNotHasKey('css', $response->json('action'));

        $this->postJson(route('assistant.pagebuilder-actions.claim'), [
            'action_token' => $pending['token'],
        ])->assertUnprocessable();
    }

    public function test_discarded_pagebuilder_actions_cannot_be_consumed_or_claimed_later(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 8);
        $effect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];

        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertFalse($store->discard($admin, $pending['token'], 'admin.mail-documents.editor'));
        $this->assertTrue($store->discard($admin, $pending['token'], 'admin.marketing.creatives.editor'));
        $this->assertNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $pending['token'],
            [],
            $context,
        ));

        $confirmed = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $confirmed['token'],
            [],
            $context,
        ));
        $this->assertTrue($store->discard($admin, $confirmed['token'], 'admin.marketing.creatives.editor'));
        $this->assertNull($store->claimPageBuilderEffect($admin, $confirmed['token']));
    }

    public function test_claim_and_discard_share_the_same_token_lock_and_discard_wins_before_a_late_claim(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 12);
        $effect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];
        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $pending['token'],
            [],
            $context,
        ));

        $discardLock = $store->tokenLock($admin, $pending['token']);
        $this->assertTrue($discardLock->get());
        try {
            $this->postJson(route('assistant.pagebuilder-actions.claim'), [
                'action_token' => $pending['token'],
            ])->assertUnprocessable();
        } finally {
            $discardLock->release();
        }

        $this->assertTrue($store->tokenLock($admin, $pending['token'])->get(
            fn (): bool => $store->discard($admin, $pending['token'], 'admin.marketing.creatives.editor'),
        ));
        $this->postJson(route('assistant.pagebuilder-actions.claim'), [
            'action_token' => $pending['token'],
        ])->assertUnprocessable();
    }

    public function test_claim_failure_revokes_the_receipt_and_interrupted_history_recovers_to_discardable_error(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant, clientRevision: 13);
        $effect = $tools->execute(
            AssistantPageBuilderTools::SAVE_TOOL,
            [],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];
        $pending = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $pending['token'],
            [],
            $context,
        ));

        $chatbot = new Chatbot;
        $chatbot->pageRouteName = 'admin.marketing.creatives.editor';
        $chatbot->chatHistory = [[
            'key' => 'interrupted-claim',
            'role' => 'assistant',
            'content' => 'Änderung vorbereitet.',
            'created_at' => now()->toIso8601String(),
            'actions' => [[
                'kind' => 'pending_tool',
                'token' => $pending['token'],
                'label' => 'Arbeitsstand speichern',
                'route_name' => 'admin.marketing.creatives.editor',
                'status' => 'claiming',
            ]],
        ]];
        $recover = new ReflectionMethod($chatbot, 'recoverInterruptedPageBuilderClaims');
        $recover->setAccessible(true);
        $recover->invoke($chatbot, $admin);

        $action = $chatbot->chatHistory[0]['actions'][0];
        $this->assertSame('error', $action['status']);
        $this->assertNotSame('', $action['error']);
        $this->assertNull($store->claimPageBuilderEffect($admin, $pending['token']));

        $timedOut = $store->create($admin, 'admin.marketing.creatives.editor', $effect);
        $this->assertNotNull($store->consume(
            $admin,
            'admin.marketing.creatives.editor',
            $timedOut['token'],
            [],
            $context,
        ));
        $chatbot->chatHistory = [[
            'key' => 'timed-out-claim',
            'role' => 'assistant',
            'content' => 'Änderung vorbereitet.',
            'created_at' => now()->toIso8601String(),
            'actions' => [[
                'kind' => 'pending_tool',
                'token' => $timedOut['token'],
                'label' => 'Arbeitsstand speichern',
                'route_name' => 'admin.marketing.creatives.editor',
                'status' => 'claiming',
            ]],
        ]];
        $chatbot->recordAssistantActionClaimFailure([
            'action_token' => $timedOut['token'],
            'reason' => 'claim_timeout',
        ]);

        $this->assertSame('error', $chatbot->chatHistory[0]['actions'][0]['status']);
        $this->assertStringContainsString('abgebrochen', $chatbot->chatHistory[0]['actions'][0]['error']);
        $this->assertNull($store->claimPageBuilderEffect($admin, $timedOut['token']));
    }

    public function test_pending_pagebuilder_confirmation_names_the_exact_safe_change(): void
    {
        [$admin, $creative, $variant] = $this->creative();
        $this->actingAs($admin);
        $tools = app(AssistantPageBuilderTools::class);
        $store = app(AssistantPendingActionStore::class);
        $context = $this->marketingContext($creative, $variant);

        $proposedText = str_repeat('Sicherer Inhalt ', 55).'VOLLSTÄNDIG SICHTBARES ENDE';
        $textEffect = $tools->execute(
            AssistantPageBuilderTools::TEXT_TOOL,
            ['text' => $proposedText],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];
        $styleEffect = $tools->execute(
            AssistantPageBuilderTools::STYLE_TOOL,
            ['property' => 'font-size', 'value' => '72px'],
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        )['effect'];

        $pool = FilePool::company();
        $file = $this->createImageFile($pool, null, $admin, 'wagenmeister-team.png', 0x17324D);
        $imageContext = $this->marketingContext($creative, $variant, selectionTag: 'img');
        $imageEffect = $tools->execute(
            AssistantPageBuilderTools::IMAGE_TOOL,
            ['file_id' => $file->id],
            $admin,
            'admin.marketing.creatives.editor',
            $imageContext,
        )['effect'];

        $textPending = $store->create($admin, 'admin.marketing.creatives.editor', $textEffect);
        $this->assertStringContainsString('Textänderung', $textPending['label']);
        $this->assertStringContainsString($proposedText, $textPending['detail']);
        $this->assertStringEndsWith('VOLLSTÄNDIG SICHTBARES ENDE', $textPending['detail']);
        $chatbot = new Chatbot;
        $chatbot->pageRouteName = 'admin.marketing.creatives.editor';
        $chatbot->pageBuilderAssistantContext = $tools->normalizeContext(
            $admin,
            'admin.marketing.creatives.editor',
            $context,
        );
        $decorate = new ReflectionMethod($chatbot, 'decoratePageBuilderPendingAction');
        $decorate->setAccessible(true);
        $decorated = $decorate->invoke($chatbot, $textPending, $textEffect, $admin);
        $this->assertSame('admin.marketing.creatives.editor', $decorated['route_name']);
        $this->assertSame('pending', $decorated['status']);
        $this->assertNotSame('', $decorated['segment']);
        $this->assertNotSame('', $decorated['before']);
        $this->assertSame($proposedText, $decorated['after']);
        $styleLabel = $store->create($admin, 'admin.marketing.creatives.editor', $styleEffect)['label'];
        $this->assertStringContainsString('font-size', $styleLabel);
        $this->assertStringContainsString('72px', $styleLabel);
        $this->assertStringContainsString('wagenmeister-team.png', $store->create($admin, 'admin.marketing.creatives.editor', $imageEffect)['label']);
        $this->assertStringContainsString(
            'Vorgeschlagene Änderung',
            (string) file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php')),
        );
    }

    public function test_contextual_help_describes_both_fullscreen_editors(): void
    {
        $catalog = app(PageHelpCatalog::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $marketing = $catalog->forRoute('admin.marketing.creatives.editor');
        $mail = $catalog->forRoute('admin.mail-documents.editor');
        $this->assertSame('Marketing-Motiv-Editor', $marketing['title']);
        $this->assertSame('E-Mail- und Signatur-Editor', $mail['title']);
        $this->assertSame('/administrator/marketing/motive', route($marketing['route'], [], false));
        $this->assertSame('/administrator/mail-vorlagen', route($mail['route'], [], false));
        $this->assertNotEmpty($catalog->forUser($admin));
    }

    /** @return array{User, MarketingCreative, MarketingCreativeVariant} */
    private function creative(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);

        return [$admin, $creative, $variant];
    }

    /** @return array<string, mixed> */
    private function marketingContext(
        MarketingCreative $creative,
        MarketingCreativeVariant $variant,
        int $clientRevision = 1,
        bool $unsaved = false,
        string $selectionTag = 'p',
    ): array {
        return [
            'version' => 1,
            'route_name' => 'admin.marketing.creatives.editor',
            'mode' => 'marketing',
            'resource_id' => $creative->public_id,
            'format_or_kind' => 'story',
            'workspace_nonce' => str_repeat('w', 24),
            'fullscreen_open' => true,
            'editor_ready' => true,
            'read_only' => false,
            'persisted_content_hash' => $variant->content_hash,
            'persisted_version' => $variant->version,
            'client_revision' => $clientRevision,
            'unsaved' => $unsaved,
            'selection' => [
                'selection_nonce' => str_repeat('s', 24),
                'fingerprint' => str_repeat('a', 64),
                'tag' => $selectionTag,
                'block_id' => 'rt-marketing-headline',
                'text' => 'Wagenmeister gesucht',
                'styles' => ['font-size' => '64px'],
                'protected' => false,
                'motion_allowed' => true,
                'gif' => false,
            ],
            'capabilities' => [
                'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
                'replace_image', 'add_block', 'undo', 'redo', 'preview', 'save',
                'animation', 'gif_preview', 'redesign_document',
            ],
            'available_block_ids' => ['rt-marketing-headline', 'rt-marketing-hero'],
            'validation' => ['state' => 'valid', 'issues' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function mailContext(MailDocument $document): array
    {
        return [
            'version' => 1,
            'route_name' => 'admin.mail-documents.editor',
            'mode' => 'mail',
            'resource_id' => $document->public_id,
            'format_or_kind' => $document->kind->value,
            'workspace_nonce' => str_repeat('m', 24),
            'fullscreen_open' => true,
            'editor_ready' => true,
            'read_only' => false,
            'persisted_content_hash' => $document->content_hash,
            'persisted_version' => $document->version,
            'client_revision' => 12,
            'unsaved' => true,
            'selection' => [
                'selection_nonce' => str_repeat('n', 24),
                'fingerprint' => str_repeat('d', 64),
                'tag' => 'span',
                'block_id' => 'rt-mail-signature',
                'text' => '{{VORNAME_NACHNAME}}',
                'styles' => ['font-size' => '25px'],
                'protected' => false,
                'motion_allowed' => true,
                'gif' => false,
            ],
            'capabilities' => [
                'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
                'add_block', 'undo', 'redo', 'preview', 'save', 'animation', 'gif_preview',
            ],
            'available_block_ids' => ['rt-mail-section', 'rt-mail-paragraph'],
            'validation' => ['state' => 'warning', 'issues' => ['Testversand empfohlen']],
        ];
    }

    private function createImageFile(
        FilePool $pool,
        ?FileFolder $folder,
        User $actor,
        string $name,
        int $color,
    ): File {
        $image = imagecreatetruecolor(12, 12);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);
        $path = 'uploads/files/'.uniqid('assistant-', true).'-'.$name;
        Storage::disk('private')->put($path, $contents);

        return $pool->files()->create([
            'folder_id' => $folder?->id,
            'user_id' => $actor->id,
            'name' => $name,
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => strlen($contents),
        ]);
    }

    /** @param list<array<string, mixed>> $tools @return list<string> */
    private function toolNames(array $tools): array
    {
        return array_map(static fn (array $tool): string => (string) $tool['function']['name'], $tools);
    }
}

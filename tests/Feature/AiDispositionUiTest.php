<?php

namespace Tests\Feature;

use App\Livewire\Admin\AiDispositionConfiguration;
use App\Livewire\Admin\Operations\Customers;
use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\PageWorkspace;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsPages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiDispositionUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->admin);
        $this->withoutVite();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_configuration_does_not_expose_secrets_and_requires_actual_superadmin(): void
    {
        AiDispositionSettings::save(['imap_password' => 'synthetic-secret'], $this->admin);
        Livewire::test(AiDispositionConfiguration::class)->assertSee('AI-Disposition')->assertDontSee('synthetic-secret')->assertSet('form.imap_password', AiDispositionSettings::SECRET_MASK);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        Livewire::actingAs($otherAdmin)->test(AiDispositionConfiguration::class)->assertForbidden();
        Livewire::actingAs($this->admin)->test(AiDispositionConfiguration::class)->set('form.from_name', 'Disposition QA')->call('save')->assertHasNoErrors()->assertSet('form.imap_password', AiDispositionSettings::SECRET_MASK);
    }

    public function test_configuration_rechecks_deactivation_before_actions(): void
    {
        $component = Livewire::test(AiDispositionConfiguration::class);
        $this->admin->update(['status' => false]);
        $component->call('probe')->assertForbidden();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_manual_input_is_preserved_when_automation_disabled_without_native_or_mail_side_effects(): void
    {
        Livewire::test(AiIntakeInbox::class)->call('openCapture')->set('text', 'Eine neue Anfrage für einen Einsatz.')->call('submit')->assertHasNoErrors()->assertSet('captureOpen', false);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame('Eine neue Anfrage für einen Einsatz.', AiIntake::first()->messages()->first()->body);
        $this->assertSame(0, OperationInquiry::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_customer_embedding_rejects_other_customer_intake_and_proposal_actions(): void
    {
        $first = Customer::create(['company_name' => 'First fixture', 'is_active' => true]);
        $second = Customer::create(['company_name' => 'Second fixture', 'is_active' => true]);
        $record = AiIntake::create(['source_type' => 'manual', 'customer_id' => $second->id, 'title' => 'Other customer private request', 'supervising_user_id' => $this->admin->id]);
        $component = Livewire::test(AiIntakeInbox::class, ['customerId' => $first->id])->assertDontSee('Other customer private request');
        $this->expectException(ModelNotFoundException::class);
        $component->call('select', $record->id);
    }

    public function test_received_content_is_escaped_in_details_and_native_links_keep_intake_scope(): void
    {
        $record = AiIntake::create(['source_type' => 'manual', 'title' => 'Fixture source', 'supervising_user_id' => $this->admin->id]);
        $record->messages()->create(['direction' => 'inbound', 'body' => '<script>alert("fixture")</script>', 'metadata' => []]);
        Livewire::test(AiIntakeInbox::class)->call('select', $record->id)->assertSee('Fixture source')->assertDontSeeHtml('<script>alert("fixture")</script>')->assertSee('&lt;script&gt;', false);
    }

    public function test_worklist_reports_only_assigned_review_and_overdue_responses_with_live_target(): void
    {
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $own = AiIntake::create(['source_type' => 'manual', 'title' => 'Check this request', 'status' => 'review', 'supervising_user_id' => $this->admin->id]);
        AiIntake::create(['source_type' => 'manual', 'title' => 'Other operator request', 'status' => 'failed', 'supervising_user_id' => $other->id]);
        $early = AiIntake::create(['source_type' => 'manual', 'title' => 'Response still in time', 'status' => 'waiting_customer', 'last_analyzed_at' => now()->utc()->subHours(49), 'supervising_user_id' => $this->admin->id]);
        $late = AiIntake::create(['source_type' => 'manual', 'title' => 'Response overdue', 'status' => 'waiting_customer', 'last_analyzed_at' => now()->utc()->subHours(49), 'supervising_user_id' => $this->admin->id]);
        foreach ([[$early, 47], [$late, 49]] as [$record,$hours]) {
            AiIntakeDelivery::create(['intake_id' => $record->id, 'recipient_email' => 'customer@example.test', 'subject' => 'Fixture question', 'body' => 'Please clarify', 'status' => 'sent', 'dedup_key' => hash('sha256', 'ui-question-'.$record->id), 'settings_revision' => 1, 'intake_revision' => 1, 'source_revision' => 1, 'question_round' => 0, 'sent_at' => now()->utc()->subHours($hours)]);
        }
        $service = app(UnifiedOperationsInboxService::class);
        $items = $service->items($this->admin);
        $this->assertEqualsCanonicalizing(['ai-intake-'.$own->id, 'ai-intake-'.$late->id], $items->pluck('id')->all());
        $this->assertSame(OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake', 'source' => 'ai-intake', 'record' => $own->id]), $service->destination('ai-intake-'.$own->id, $this->admin));
    }

    public function test_superseded_proposals_show_review_reason_instead_of_applicable_actions(): void
    {
        $record = AiIntake::create(['source_type' => 'manual', 'title' => 'Updated fixture', 'source_revision' => 2, 'missing_fields' => ['starts_at', 'customer_id'], 'supervising_user_id' => $this->admin->id]);
        AiIntakeProposal::create(['intake_id' => $record->id, 'source_revision' => 1, 'position_index' => 0, 'status' => 'proposed', 'payload' => ['demand' => ['title' => 'Older position'], 'segments' => []]]);
        Livewire::test(AiIntakeInbox::class)->call('select', $record->id)
            ->assertSee('Ein neuerer Eingang liegt vor.')
            ->assertSee('Kundenzuordnung')->assertSee('Beginn')
            ->assertDontSee('Angaben korrigieren')->assertDontSee('Vorschlag freigeben');
    }

    public function test_new_customer_is_only_created_on_native_human_confirmation_and_attached_atomically(): void
    {
        $record = AiIntake::create(['source_type' => 'manual', 'title' => 'Unknown customer fixture', 'revision' => 1, 'analysis' => ['customer_draft' => ['company_name' => 'Prepared railway fixture', 'contact_name' => 'Prepared contact']], 'supervising_user_id' => $this->admin->id]);
        $before = Customer::count();
        $component = Livewire::test(Customers::class, ['embedded' => true, 'modalOnly' => true, 'startCreating' => true, 'workspaceRevision' => $record->revision, 'draftIntakeId' => $record->id])
            ->assertSet('companyName', 'Prepared railway fixture')->assertSet('contactName', 'Prepared contact');
        $this->assertSame($before, Customer::count());
        $component->call('saveCustomer')->assertHasNoErrors();
        $this->assertSame($before + 1, Customer::count());
        $this->assertSame('Prepared railway fixture', $record->fresh()->customer->company_name);
        Mail::assertNothingSent();
    }

    public function test_stale_customer_draft_cannot_create_a_second_customer(): void
    {
        $record = AiIntake::create(['source_type' => 'manual', 'title' => 'Stale fixture', 'revision' => 1, 'supervising_user_id' => $this->admin->id]);
        $component = Livewire::test(Customers::class, ['embedded' => true, 'modalOnly' => true, 'startCreating' => true, 'workspaceRevision' => $record->revision, 'draftIntakeId' => $record->id])->set('companyName', 'Should not be created');
        $record->increment('revision');
        $before = Customer::count();
        $component->call('saveCustomer')->assertStatus(409);
        $this->assertSame($before, Customer::count());
        $this->assertNull($record->fresh()->customer_id);
    }

    public function test_old_shift_page_redirects_to_authorized_cases_tab_preserving_calendar_and_period(): void
    {
        Livewire::withQueryParams(['view' => 'calendar', 'from' => '2026-10-12', 'until' => '2026-10-18'])->test(PageWorkspace::class, ['page' => 'shifts'])->assertRedirect(OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'calendar', 'from' => '2026-10-12', 'until' => '2026-10-18']));
        $this->assertArrayHasKey('shifts', CaseWorkspace::availableViews($this->admin));
        $this->assertArrayHasKey('ai-intake', CaseWorkspace::availableSections($this->admin));
    }
}

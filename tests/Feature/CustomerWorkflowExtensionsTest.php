<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminStorageController;
use App\Livewire\Admin\UserProfile\EmployeeDocuments;
use App\Livewire\Operations\CommercialOffers;
use App\Livewire\Operations\CustomerRelations;
use App\Livewire\Operations\InquiryInbox;
use App\Livewire\Operations\PersonnelDocuments;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\EmployeeDocumentRequirement;
use App\Models\EmployeeDocumentVersion;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\PersonnelResponsibility;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Operations\CommercialOfferService;
use App\Services\Operations\CustomerWorkflowService;
use App\Services\Operations\EmployeeDocumentVersionService;
use App\Services\Operations\InquiryWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerWorkflowExtensionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_07_22_000002_create_employee_document_requirements_table.php'))->up();
        (require database_path('migrations/2026_10_04_121000_create_customer_workflow_extensions.php'))->up();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
        Storage::fake('private');
        Storage::fake('public');
        // Marketing dependency scanning is outside this isolated personnel schema.
        $marketing = \Mockery::mock(app(MarketingFileSourceService::class));
        $marketing->shouldReceive('assertFileCanMoveTo')->andReturnNull();
        $marketing->shouldReceive('handleFileContentMutation')->andReturnNull();
        app()->instance(MarketingFileSourceService::class, $marketing);
        $this->travelTo(now()->setDate(2027, 5, 12)->setTime(12, 0)->utc());
    }

    private function inquiry(): OperationInquiry
    {
        return app(InquiryWorkflowService::class)->save(null, ['channel' => 'manual', 'title' => 'Nachteinsatz', 'original' => 'Original bleibt erhalten',
            'customer_id' => $this->customer->id, 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00',
            'role_name' => 'Tf', 'location_name' => 'Hamburg', 'required_staff' => 2], $this->admin);
    }

    private function position(array $overrides = []): array
    {
        return $overrides + ['title' => 'Tf', 'unit' => 'Stunde', 'quantity' => '2.125', 'price' => '12.34', 'kind' => 'standard'];
    }

    private function draft(OperationInquiry $inquiry, ?int $latestId = null): CommercialOfferRevision
    {
        return app(CommercialOfferService::class)->draft($inquiry, $latestId, ['kind' => 'offer', 'terms' => 'Vereinbarte Leistung',
            'valid_until' => '2027-05-20', 'positions' => [$this->position()]], $this->admin);
    }

    private function validation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Validation exception expected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(callable $callback, int $status = 403): void
    {
        try {
            $callback();
            $this->fail('HTTP rejection expected.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $status);
        }
    }

    public function test_offer_calculation_uses_fixed_point_rounding_and_explicit_optional_positions(): void
    {
        $result = app(CommercialOfferService::class)->positions([
            $this->position(), $this->position(['kind' => 'alternative']), $this->position(['kind' => 'eventual']),
            $this->position(['kind' => 'eventual_included', 'quantity' => '1', 'price' => '1.01']),
        ], $this->customer->id, '2027-05-14');
        $this->assertSame(2622, $result['positions'][0]['total_cents']);
        $this->assertSame(2723, $result['amount_cents']);
        $this->assertFalse($result['positions'][1]['included']);
    }

    public function test_versions_are_retained_and_stale_draft_issue_is_rejected(): void
    {
        $inquiry = $this->inquiry();
        $draft = $this->draft($inquiry);
        $second = $this->draft($inquiry, $draft->id);
        $this->validation(fn () => app(CommercialOfferService::class)->issue($draft->id, 1, $this->admin));
        $this->validation(fn () => $this->draft($inquiry, $draft->id));
        $this->assertSame(2, $second->revision);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, CommercialOfferRevision::count());
    }

    public function test_rejected_inquiry_cannot_create_a_new_offer_by_bypassing_the_ui(): void
    {
        $inquiry = $this->inquiry();
        app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'reject', ['note' => 'Kunde hat abgesagt'], $this->admin);
        $this->validation(fn () => $this->draft($inquiry->fresh()));
        $this->assertSame(0, CommercialOfferRevision::count());
    }

    public function test_offer_issue_accept_and_conversion_preserve_accepted_customer_snapshot(): void
    {
        $inquiry = $this->inquiry();
        app(InquiryWorkflowService::class)->transition($inquiry, 1, 'verify', [], $this->admin);
        $draft = $this->draft($inquiry->fresh());
        $issued = app(CommercialOfferService::class)->issue($draft->id, 1, $this->admin);
        app(CommercialOfferService::class)->accept($draft->id, $issued->state_version, 'Frau Test, 12.05., E-Mail 123 bestätigt', true, $this->admin);
        $accepted = $inquiry->fresh();
        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(2622, $accepted->offer['amount_cents']);
        $converted = app(InquiryWorkflowService::class)->transition($accepted, $accepted->revision, 'convert', [], $this->admin);
        $this->assertSame('converted', $converted->status);
        $orderOffer = CommercialOfferRevision::where('subject_type', 'Order')->firstOrFail();
        $this->assertSame($accepted->offer['positions'], $orderOffer->snapshot['positions']);
        $this->assertSame('Original bleibt erhalten', $converted->original);
    }

    public function test_changes_invalidate_offer_and_old_snapshot_is_not_overwritten(): void
    {
        $inquiry = $this->inquiry();
        app(InquiryWorkflowService::class)->transition($inquiry, 1, 'verify', [], $this->admin);
        $draft = $this->draft($inquiry->fresh());
        $inquiry->forceFill(['revision' => 2])->save();
        $this->validation(fn () => app(CommercialOfferService::class)->issue($draft->id, 1, $this->admin));
        $this->assertSame(1, $draft->fresh()->snapshot['source_revision']);
    }

    public function test_dated_conditions_do_not_overlap_or_silently_replace_offer_price(): void
    {
        $service = app(CustomerWorkflowService::class);
        $condition = $service->condition($this->customer, ['code' => 'TF', 'label' => 'Tf', 'unit' => 'Stunde', 'price' => '45.00', 'valid_from' => '2027-01-01'], $this->admin);
        $this->validation(fn () => $service->condition($this->customer, ['code' => 'TF', 'label' => 'Tf', 'unit' => 'Stunde', 'price' => '50.00', 'valid_from' => '2027-05-01'], $this->admin));
        $service->endCondition($condition->id, 1, '2027-05-31', $this->admin);
        $next = $service->condition($this->customer, ['code' => 'TF', 'label' => 'Tf', 'unit' => 'Stunde', 'price' => '50.00', 'valid_from' => '2027-06-01'], $this->admin);
        $this->assertSame(5000, $next->unit_price_cents);
        $this->validation(fn () => app(CommercialOfferService::class)->positions([$this->position(['condition_id' => $next->id, 'price' => '50.00'])], $this->customer->id, '2027-05-14'));
    }

    public function test_contacts_enforce_customer_scope_and_stale_revision(): void
    {
        $service = app(CustomerWorkflowService::class);
        $contact = $service->contact($this->customer, null, null, ['name' => 'Besteller', 'roles' => ['ordering', 'dispatch'], 'is_active' => true], $this->admin);
        $this->validation(fn () => $service->contact($this->customer, $contact->id, 9, ['name' => 'Andere', 'roles' => ['billing'], 'is_active' => true], $this->admin));
        $foreignCustomer = Customer::create(['company_name' => 'Foreign', 'is_active' => true]);
        $foreignContact = CustomerContact::create(['customer_id' => $foreignCustomer->id, 'name' => 'Foreign person', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $inquiry = $this->inquiry();
        $this->denied(fn () => $service->process($inquiry, null, ['customer_contact_id' => $foreignContact->id, 'priority' => 'normal', 'timezone' => 'Europe/Berlin'], $this->admin), 422);
    }

    public function test_wiedervorlage_is_independent_of_demand_and_completion_is_revision_guarded(): void
    {
        $inquiry = $this->inquiry();
        $service = app(CustomerWorkflowService::class);
        $follow = $service->followUp($inquiry, ['title' => 'Anrufen', 'kind' => 'call', 'due_at' => '2027-05-13T09:00', 'timezone' => 'Europe/Berlin', 'assignee_id' => $this->admin->id], $this->admin);
        $service->completeFollowUp($follow->id, 1, 'Telefonisch geklärt', $this->admin);
        $this->validation(fn () => $service->completeFollowUp($follow->id, 1, 'Erneut abgeschlossen', $this->admin));
        $this->assertSame(1, $inquiry->fresh()->revision);
        $this->assertSame('completed', $follow->fresh()->status);
    }

    public function test_private_document_replacement_retains_bytes_and_acknowledges_exact_current_version(): void
    {
        $service = app(EmployeeDocumentVersionService::class);
        $first = $service->save($this->employee->id, 'employment_contract', UploadedFile::fake()->create('contract-v1.pdf', 2, 'application/pdf'), $this->admin);
        $firstPath = $first->file->path;
        $service->acknowledge($first->id, $this->employee);
        $second = $service->save($this->employee->id, 'employment_contract', UploadedFile::fake()->create('contract-v2.pdf', 2, 'application/pdf'), $this->admin, $first->file_id);
        Storage::disk('private')->assertExists($firstPath);
        $this->assertSame(2, $second->revision);
        $this->assertNull($second->acknowledged_at);
        $this->assertNotNull($first->fresh()->acknowledged_at);
        $this->denied(fn () => $service->acknowledge($first->id, $this->employee), 422);
        $response = $service->download($this->employee->id, 'employment_contract', $first->id, $this->employee);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $service->withdraw($this->employee->id, 'employment_contract', $this->admin, $second->file_id);
        $this->assertNull(EmployeeDocumentRequirement::first()->file);
        Storage::disk('private')->assertExists($second->file->path);
    }

    public function test_personnel_files_have_no_team_or_public_preview_and_no_generic_delete(): void
    {
        $service = app(EmployeeDocumentVersionService::class);
        $version = $service->save($this->employee->id, 'identity_card', UploadedFile::fake()->create('identity.pdf', 2, 'application/pdf'), $this->admin);
        $file = $version->file;
        $foreign = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->denied(fn () => $service->download($this->employee->id, 'identity_card', $version->id, $foreign));
        $this->denied(fn () => $service->acknowledge($version->id, $this->admin));
        $this->denied(fn () => $file->getEphemeralPublicUrl());
        $this->denied(fn () => $file->download());
        $this->denied(fn () => $file->delete());
        $this->denied(fn () => $file->forceFill(['filepool_id' => 123])->save());
        $this->assertFalse($file->isVisibleForTeams($this->admin));
        $this->assertFalse($file->isPubliclyVisible($this->employee));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_components_render_standard_lists_and_modals_and_recheck_access(): void
    {
        $inquiry = $this->inquiry();
        Livewire::actingAs($this->admin)->test(InquiryInbox::class)->call('select', $inquiry->id)->assertSee('Angebotsstände')->assertSee('Wiedervorlage');
        Livewire::actingAs($this->admin)->test(CommercialOffers::class, ['subjectType' => 'OperationInquiry', 'subjectId' => $inquiry->id])->call('create')->assertSet('formOpen', true)->assertSeeHtml('role="dialog"')->assertSee('Einzelpreis');
        Livewire::actingAs($this->admin)->test(CustomerRelations::class, ['customerId' => $this->customer->id])->call('create', 'contact')->assertSee('Kontaktrollen');
        Livewire::actingAs($this->employee)->test(PersonnelDocuments::class)->assertSee('Keine Unterlagen');
        Livewire::actingAs($this->admin)->test(EmployeeDocuments::class, ['userId' => $this->employee->id])->assertSee('Dokumentversionen');
    }

    public function test_missing_extension_schema_does_not_break_inquiry_list_or_legacy_download(): void
    {
        Schema::drop('commercial_offer_revisions');
        Schema::drop('customer_conditions');
        Livewire::actingAs($this->admin)->test(InquiryInbox::class)->assertSee('Posteingang');
        $this->assertFalse(CommercialOfferService::ready());
        $this->assertFalse(CustomerWorkflowService::ready());
    }

    public function test_existing_private_legacy_document_is_lazily_archived_when_replaced(): void
    {
        $requirement = EmployeeDocumentRequirement::create(['user_id' => $this->employee->id, 'document_type' => 'drivers_license']);
        Storage::disk('private')->put('uploads/employee-documents/legacy.pdf', 'Legacy document bytes');
        $file = $requirement->file()->create(['user_id' => $this->admin->id, 'name' => 'legacy.pdf', 'path' => 'uploads/employee-documents/legacy.pdf', 'disk' => 'private', 'mime_type' => 'application/pdf', 'type' => 'employee-document', 'size' => 21]);
        $version = app(EmployeeDocumentVersionService::class)->save($this->employee->id, 'drivers_license', UploadedFile::fake()->create('new.pdf', 2, 'application/pdf'), $this->admin, $file->id);
        $this->assertSame(2, $version->revision);
        $legacy = EmployeeDocumentVersion::where('file_id', $file->id)->firstOrFail();
        $this->assertSame(hash('sha256', 'Legacy document bytes'), $legacy->snapshot['sha256']);
        $this->assertSame(EmployeeDocumentVersion::class, $file->fresh()->fileable_type);
        Storage::disk('private')->assertExists('uploads/employee-documents/legacy.pdf');
    }

    public function test_stale_document_upload_or_withdraw_does_not_replace_current_version(): void
    {
        $service = app(EmployeeDocumentVersionService::class);
        $first = $service->save($this->employee->id, 'identity_card', UploadedFile::fake()->create('first.pdf', 2, 'application/pdf'), $this->admin);
        $this->validation(fn () => $service->save($this->employee->id, 'identity_card', UploadedFile::fake()->create('stale.pdf', 2, 'application/pdf'), $this->admin));
        $this->validation(fn () => $service->withdraw($this->employee->id, 'identity_card', $this->admin, 999));
        $this->assertSame($first->file_id, EmployeeDocumentRequirement::first()->file->id);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_raw_storage_api_cannot_resolve_or_delete_personnel_paths(): void
    {
        $version = app(EmployeeDocumentVersionService::class)->save($this->employee->id, 'identity_card', UploadedFile::fake()->create('private.pdf', 2, 'application/pdf'), $this->admin);
        Setting::setValue('api', 'base_api_key', 'synthetic-test-key');
        $controller = app(AdminStorageController::class);
        foreach ([$version->file->path, './'.$version->file->path, 'uploads/other/../employee-documents/test.pdf'] as $path) {
            $request = Request::create('/synthetic', 'POST', ['url' => $path]);
            $request->headers->set('X-API-KEY', 'synthetic-test-key');
            $this->denied(fn () => $controller->resolveFileUrl($request));
        }
        $request = Request::create('/synthetic', 'DELETE', ['path' => $version->file->path]);
        $request->headers->set('X-API-KEY', 'synthetic-test-key');
        $this->denied(fn () => $controller->destroy($request));
        Storage::disk('private')->assertExists($version->file->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_order_amendment_remains_customer_scope_without_changing_shifts_or_times(): void
    {
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Bestehender Auftrag', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00', 'required_staff' => 1]);
        $service = app(CommercialOfferService::class);
        $draft = $service->draft($order, null, ['kind' => 'amendment', 'reason' => 'Zusätzliches Personal angefragt', 'terms' => 'Zusätzliche zwei Stunden', 'positions' => [$this->position()]], $this->admin);
        $issued = $service->issue($draft->id, 1, $this->admin);
        $service->accept($draft->id, $issued->state_version, 'Berechtigter Besteller bestätigt per Telefon', true, $this->admin);
        $this->assertSame('accepted', $draft->fresh()->status);
        $this->assertSame(1, $order->fresh()->required_staff);
        $this->assertSame(0, $order->shifts()->count());
        $this->assertDatabaseCount('work_time_entries', 0);
        $second = $service->draft($order, $draft->id, ['kind' => 'amendment', 'reason' => 'Neuer Umfang nach Änderung', 'terms' => 'Ergänzung', 'positions' => [$this->position()]], $this->admin);
        $order->forceFill(['customer_id' => Customer::create(['company_name' => 'Other customer', 'is_active' => true])->id])->save();
        $this->validation(fn () => $service->issue($second->id, 1, $this->admin));
    }

    public function test_position_interval_and_offer_expiry_are_checked_on_server(): void
    {
        $service = app(CommercialOfferService::class);
        $this->validation(fn () => $service->positions([$this->position(['starts_at' => '2027-05-15T06:00', 'ends_at' => '2027-05-14T22:00', 'timezone' => 'Europe/Berlin'])], $this->customer->id, '2027-05-14'));
        $inquiry = $this->inquiry();
        app(InquiryWorkflowService::class)->transition($inquiry, 1, 'verify', [], $this->admin);
        $draft = $this->draft($inquiry->fresh());
        $issued = $service->issue($draft->id, 1, $this->admin);
        $this->travelTo(now()->setDate(2027, 5, 21));
        $this->validation(fn () => $service->accept($draft->id, $issued->state_version, 'Zusage nach abgelaufener Frist', true, $this->admin));
        $this->assertSame('offered', $draft->fresh()->status);
    }

    public function test_disabled_actor_is_denied_and_permissions_are_checked_in_services(): void
    {
        $this->denied(fn () => app(CustomerWorkflowService::class)->contact($this->customer, null, null, ['name' => 'Not permitted', 'roles' => ['dispatch'], 'is_active' => true], $this->employee));
        $inquiry = $this->inquiry();
        $this->admin->forceFill(['status' => false])->save();
        $this->denied(fn () => $this->draft($inquiry));
        $this->denied(fn () => app(EmployeeDocumentVersionService::class)->download($this->employee->id, 'identity_card', null, $this->admin));
    }

    public function test_private_documents_respect_scoped_personnel_responsibility_without_denormalizing_owner_access(): void
    {
        Schema::create('personnel_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('responsible_user_id');
            $table->unsignedBigInteger('user_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('abilities');
            $table->timestamps();
        });
        $reviewer = User::factory()->create(['role' => 'staff', 'status' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('employees.master-data.view', fn ($user) => true);
        Gate::define('employees.master-data.edit', fn ($user) => true);
        PersonnelResponsibility::create(['responsible_user_id' => $reviewer->id, 'user_id' => $other->id, 'starts_on' => '2027-01-01', 'abilities' => ['employees.master-data.view', 'employees.master-data.edit']]);
        $service = app(EmployeeDocumentVersionService::class);
        $this->denied(fn () => $service->authorize($reviewer, $this->employee->id));
        $this->denied(fn () => $service->authorize($reviewer, $this->employee->id, true));
        $service->authorize($reviewer, $other->id);
        $service->authorize($this->employee, $this->employee->id);
        $this->assertTrue(true);
    }

    public function test_offer_form_uses_only_conditions_matching_customer_and_service_date(): void
    {
        $condition = app(CustomerWorkflowService::class)->condition($this->customer, ['code' => 'TF', 'label' => 'Triebfahrzeugführer', 'unit' => 'Stunde', 'price' => '45.50', 'valid_from' => '2027-01-01'], $this->admin);
        $inquiry = $this->inquiry();
        Livewire::actingAs($this->admin)->test(CommercialOffers::class, ['subjectType' => 'OperationInquiry', 'subjectId' => $inquiry->id])
            ->call('create')->set('form.positions.0.condition_id', $condition->id)->assertSet('form.positions.0.price', '45.50')->assertSet('form.positions.0.title', 'Triebfahrzeugführer');
    }

    public function test_migration_rollback_does_not_discard_historical_workflow_data(): void
    {
        $this->draft($this->inquiry());
        try {
            (require database_path('migrations/2026_10_04_121000_create_customer_workflow_extensions.php'))->down();
            $this->fail('Historical data must block rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('history must be retained', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('employee_document_versions'));
        $this->assertDatabaseCount('commercial_offer_revisions', 1);
    }

    public function test_customer_and_offer_non_submit_actions_render_as_buttons_without_literal_blade_or_disabled_numbers(): void
    {
        $inquiry = $this->inquiry();
        $offer = Livewire::actingAs($this->admin)->test(CommercialOffers::class, ['subjectType' => 'OperationInquiry', 'subjectId' => $inquiry->id])->call('create');
        $customer = Livewire::actingAs($this->admin)->test(CustomerRelations::class, ['customerId' => $this->customer->id])->call('create', 'condition');
        foreach ([$offer, $customer] as $component) {
            $html = $component->html();
            $this->assertStringNotContainsString('@js(', $html);
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($dom);
            $actions = 0;
            foreach ($xpath->query('//form//button') as $button) {
                if ($button->getAttribute('wire:click') !== '') {
                    $this->assertSame('button', $button->getAttribute('type'), $button->getAttribute('wire:click').' must not submit the form.');
                    $actions++;
                }
            }
            $this->assertGreaterThan(0, $actions);
            $numbers = $xpath->query('//form//input[@role="spinbutton"]');
            $this->assertGreaterThan(0, $numbers->length);
            foreach ($numbers as $number) {
                $this->assertFalse($number->hasAttribute('disabled'), 'Editable price and quantity inputs must not inherit a truthy string disabled prop.');
            }
        }
    }
}

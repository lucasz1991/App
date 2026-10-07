<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeMessage;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\Operations\AiIntakeCustomerSuggestions;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeCustomerSuggestionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        Queue::fake();
    }

    private function intake(string $company = '', string $sender = 'unknown@rail.example.test'): AiIntake
    {
        $intake = AiIntake::create(['source_type' => 'email', 'status' => 'review', 'revision' => 1, 'source_revision' => 1, 'analysis' => ['customer_draft' => ['company_name' => $company, 'contact_name' => 'Synthetic person']], 'supervising_user_id' => $this->admin->id]);
        $message = AiIntakeMessage::create(['intake_id' => $intake->id, 'direction' => 'inbound', 'sender_email' => $sender, 'body' => 'Synthetic received company '.$company]);
        $intake->update(['latest_inbound_message_id' => $message->id]);

        return $intake;
    }

    public function test_exact_company_normalizes_case_and_whitespace_without_auto_assignment(): void
    {
        $company = Customer::create(['company_name' => 'Müller  Rail GmbH', 'is_active' => true]);
        $intake = $this->intake('  MÜLLER Rail   GmbH  ');
        $result = app(AiIntakeCustomerSuggestions::class)->suggest($intake, $this->admin);
        $this->assertSame([['customer_id' => $company->id, 'reasons' => ['company_name_exact']]], $result);
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame(1, $intake->fresh()->revision);
        $this->assertSame(1, Customer::count());
        Queue::assertNothingPushed();
    }

    public function test_main_and_active_contact_domain_propose_ids_without_exposing_addresses(): void
    {
        $main = Customer::create(['company_name' => 'Synthetic Main', 'email' => 'office@rail.example.test', 'is_active' => true]);
        $contactCustomer = Customer::create(['company_name' => 'Synthetic Contact', 'email' => 'other@different.test', 'is_active' => true]);
        CustomerContact::create(['customer_id' => $contactCustomer->id, 'name' => 'Private name', 'email' => 'contact@rail.example.test', 'is_active' => true, 'roles' => ['ordering'], 'updated_by' => $this->admin->id]);
        $result = app(AiIntakeCustomerSuggestions::class)->suggest($this->intake(), $this->admin);
        $this->assertSame([['customer_id' => $main->id, 'reasons' => ['sender_domain']], ['customer_id' => $contactCustomer->id, 'reasons' => ['sender_domain']]], $result);
        $this->assertStringNotContainsString('@', json_encode($result));
        $this->assertStringNotContainsString('Private name', json_encode($result));
    }

    public function test_public_mail_domains_and_inactive_customers_contacts_are_not_domain_matches(): void
    {
        $public = Customer::create(['company_name' => 'Synthetic public', 'email' => 'private@gmail.com', 'is_active' => true]);
        $this->assertSame([], app(AiIntakeCustomerSuggestions::class)->suggest($this->intake('', 'another@gmail.com'), $this->admin));
        Customer::create(['company_name' => 'Synthetic inactive', 'email' => 'office@rail.example.test', 'is_active' => false]);
        CustomerContact::create(['customer_id' => $public->id, 'name' => 'Inactive contact', 'email' => 'inactive@rail.example.test', 'is_active' => false, 'roles' => ['ordering'], 'updated_by' => $this->admin->id]);
        $this->assertSame([], app(AiIntakeCustomerSuggestions::class)->suggest($this->intake(), $this->admin));
    }

    public function test_company_matches_have_priority_and_all_results_are_bounded_stable_and_deduplicated(): void
    {
        $customers = [];
        for ($index = 0; $index < 12; $index++) {
            $customers[] = Customer::create(['company_name' => 'Synthetic '.$index, 'email' => 'office'.$index.'@rail.example.test', 'is_active' => true]);
        }
        $intake = $this->intake('Synthetic 11');
        $service = app(AiIntakeCustomerSuggestions::class);
        $result = $service->suggest($intake, $this->admin);
        $this->assertCount(10, $result);
        $this->assertSame($customers[11]->id, $result[0]['customer_id']);
        $this->assertSame('company_name_exact', $result[0]['reasons'][0]);
        $this->assertSame($result, $service->suggest($intake, $this->admin));
        $this->assertCount(10, array_unique(array_column($result, 'customer_id')));
    }

    public function test_company_suffixes_and_similar_names_are_not_fuzzy_matches_and_known_customer_needs_no_suggestions(): void
    {
        $customer = Customer::create(['company_name' => 'Synthetic Rail GmbH', 'is_active' => true]);
        $intake = $this->intake('Synthetic Rail AG');
        $service = app(AiIntakeCustomerSuggestions::class);
        $this->assertSame([], $service->suggest($intake, $this->admin));
        $intake->update(['customer_id' => $customer->id]);
        $this->assertSame([], $service->suggest($intake, $this->admin));
    }

    public function test_permissions_are_rechecked_from_current_user(): void
    {
        $intake = $this->intake();
        User::whereKey($this->admin->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        app(AiIntakeCustomerSuggestions::class)->suggest($intake, $this->admin);
    }
}

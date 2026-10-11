<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileCompactHeaderUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();

        foreach ([
            '2026_07_22_000002_create_employee_document_requirements_table.php',
            '2026_07_18_000001_create_activity_log_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
            '2026_08_17_070000_create_device_management_tables.php',
            '2026_10_11_120200_create_employee_location_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'name' => 'Compact Header Synthetic Fixture',
            'email' => 'compact-header@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_device_and_account_icons_share_one_compact_segment_on_both_native_hosts(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $xpath = $this->xpath($component->html());
        $sections = $xpath->query('//*[@data-profile-identity]//*[@data-profile-status]');
        $this->assertCount(1, $sections);
        $section = $sections->item(0);
        $headings = $xpath->query('./h2', $section);
        $this->assertCount(1, $headings);
        $this->assertSame('Einrichtungs Status', $this->text($headings->item(0)));
        $this->assertSame($headings->item(0)->getAttribute('id'), $section->getAttribute('aria-labelledby'));
        $this->assertCount(2, $xpath->query('./div/dl', $section));
        $phone = $xpath->query('.//dl[@data-profile-phone-status]', $section);
        $this->assertCount(1, $phone);
        $this->assertCount(1, $xpath->query('following-sibling::dl[contains(@class,"employee-profile__facts")]', $phone->item(0)));
        $this->assertCount(6, $xpath->query('.//button[@data-profile-status-trigger]', $section));
        foreach ($xpath->query('.//button[@data-profile-status-trigger]', $section) as $trigger) {
            $this->assertSame('', $this->text($trigger), 'Status captions must remain in accessible disclosures, not the visible icon rail.');
            $this->assertSame('button', $trigger->getAttribute('type'));
            $this->assertNotSame('', $trigger->getAttribute('aria-label'));
            $this->assertSame('dialog', $trigger->getAttribute('aria-haspopup'));
            $this->assertCount(1, $xpath->query('./i[@aria-hidden="true"]', $trigger));
            $this->assertCount(1, $xpath->query('./span[contains(@class,"employee-profile__status-signal") and @aria-hidden="true"]', $trigger));
            $this->assertFalse($trigger->hasAttribute('wire:click'));
            $this->assertFalse($trigger->hasAttribute('wire:model'));
        }
        foreach (['employee-profile__phone-setup', 'employee-profile__account', 'employee-profile__phone-heading'] as $oldClass) {
            $this->assertCount(0, $xpath->query('//*[@data-profile-identity]//*[contains(concat(" ",normalize-space(@class)," ")," '.$oldClass.' ")]'));
        }
        $this->assertCount(0, $xpath->query('//*[@data-profile-identity]//h2[contains(.,"Haupthandy") or contains(.,"Konto")]'));
        $this->assertDatabaseCount('user_profiles', 0);
        Http::assertNothingSent();
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    #[DataProvider('profileModes')]
    public function test_identity_uses_name_and_record_then_email_and_existing_context_in_two_native_rows(bool $embedded): void
    {
        Profile::create(['user_id' => $this->employee->id, 'position' => 'Wagenmeister Fixture']);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Region Nord Fixture', 'personal_team' => false]);
        $this->employee->forceFill(['current_team_id' => $team->id])->save();
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $xpath = $this->xpath($component->html());
        $copy = $xpath->query('//*[@data-profile-identity]//*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__person-copy ")]');
        $this->assertCount(1, $copy);
        $rows = $xpath->query('./div', $copy->item(0));
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('employee-profile__name-line', $rows->item(0)->getAttribute('class'));
        $this->assertStringContainsString('employee-profile__meta-line', $rows->item(1)->getAttribute('class'));
        $this->assertCount(1, $xpath->query('.//*[@id="employee-header-name" and @*[name()="wire:model"]="inlineValues.name"]', $rows->item(0)));
        $this->assertCount(1, $xpath->query('.//*[@for="employee-header-name" and @data-native-inline-edit-trigger]', $rows->item(0)));
        $this->assertCount(1, $xpath->query('.//*[contains(@class,"employee-profile__record-number")]', $rows->item(0)));
        $this->assertStringContainsString('#'.$this->employee->id, $this->text($rows->item(0)));
        $recordLabel = $xpath->query('.//*[contains(@class,"employee-profile__eyebrow")]/span[contains(@class,"sr-only")]', $rows->item(0));
        $this->assertCount(1, $recordLabel);
        $this->assertSame('Personalakte', $this->text($recordLabel->item(0)));
        $this->assertCount(0, $xpath->query('.//*[contains(@class,"employee-profile__eyebrow")]/span[not(contains(@class,"sr-only")) and not(contains(@class,"employee-profile__record-number"))]', $rows->item(0)));
        $this->assertCount(1, $xpath->query('.//*[@id="employee-header-email" and @*[name()="wire:model"]="inlineValues.email"]', $rows->item(1)));
        $this->assertCount(1, $xpath->query('.//*[@for="employee-header-email" and @data-native-inline-edit-trigger]', $rows->item(1)));
        $this->assertStringContainsString($this->employee->email, $this->text($rows->item(1)));
        $this->assertSame([__('app.role_staff'), 'Wagenmeister Fixture', 'Region Nord Fixture'], array_map(
            fn (DOMElement $badge): string => $this->text($badge),
            iterator_to_array($xpath->query('.//*[contains(@class,"employee-profile__identity-labels")]//*[contains(concat(" ",normalize-space(@class)," ")," rt-ui-badge ")]', $rows->item(1))),
        ));
        $this->assertCount(1, $xpath->query('//*[@data-profile-navigation]//*[@data-profile-marker="main"]'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-navigation]//*[@data-profile-marker="sub"]'));
        $this->assertCount(1, $xpath->query('//*[@data-autosave-scope]'));
        Http::assertNothingSent();
    }

    public function test_each_icon_uses_existing_hover_keyboard_touch_and_shared_layer_disclosure_contracts(): void
    {
        $component = $this->profileComponent();
        $html = $component->html();
        $xpath = $this->xpath($html);
        // DOMDocument drops Alpine shorthand attributes beginning with @.
        // Check native handlers in the delivered HTML rather than pretending
        // libxml exposes them through getAttribute().
        foreach ([
            '@keydown.escape.window="handleWindowEscape($event)"',
            '@rt-navigation:prepare.window="close()"',
            '@click="toggle()"',
            '@keydown.arrow-down.prevent.stop="focusHoverPanel()"',
            '@focusin="retainHoverFocus()"',
            '@click="close(true)"',
        ] as $handler) {
            $this->assertStringContainsString($handler, $html);
        }
        $keys = ['phone-installation', 'phone-push', 'phone-location', 'account-status', 'account-member', 'account-activity'];
        $ids = [];
        foreach ($keys as $key) {
            $triggers = $xpath->query('//*[@data-profile-status]//button[@data-profile-status-trigger="'.$key.'"]');
            $this->assertCount(1, $triggers);
            $items = $xpath->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__status-item ")]', $triggers->item(0));
            $this->assertCount(1, $items);
            $this->assertSame('employee-profile-'.$this->employee->id.'-status-'.$key, $items->item(0)->getAttribute('wire:key'));
            $roots = $xpath->query('ancestor::*[@data-rt-dropdown-root]', $triggers->item(0));
            $this->assertCount(1, $roots);
            $root = $roots->item(0);
            $id = 'rt-dropdown-employee-profile-'.$this->employee->id.'-status-'.$key;
            $ids[] = $id;
            $this->assertSame($id, $root->getAttribute('data-rt-dropdown-id'));
            $this->assertStringContainsString('rtAnchorDropdown(', $root->getAttribute('x-data'));
            $this->assertStringContainsString('openOnHover', $root->getAttribute('x-data'));
            $this->assertStringContainsString('true', $root->getAttribute('x-data'));
            $this->assertStringContainsString('employee-profile-'.$this->employee->id.'-status', $root->getAttribute('x-data'));
            $this->assertCount(1, $xpath->query('./template[@x-teleport="body"]', $root));
            $dialogs = $xpath->query('.//*[@id="'.$id.'-content" and @role="dialog"]', $root);
            $this->assertCount(1, $dialogs);
            $this->assertNotSame('', $dialogs->item(0)->getAttribute('aria-label'));
            $this->assertStringContainsString($dialogs->item(0)->getAttribute('aria-label'), $triggers->item(0)->getAttribute('aria-label'));
            $this->assertCount(0, $xpath->query('.//input|.//a', $dialogs->item(0)));
            $close = $xpath->query('.//button[@aria-label="Statusdetails schließen" and @type="button"]', $dialogs->item(0));
            $this->assertCount(1, $close);
            $this->assertStringContainsString('h-11 w-11', $close->item(0)->getAttribute('class'));
            $this->assertFalse($close->item(0)->hasAttribute('wire:click'));
        }
        $this->assertCount(count($keys), array_unique($ids));
        $this->assertCount(1, $xpath->query('//*[@data-profile-navigation]//*[@data-profile-marker="main"]'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-navigation]//*[@data-profile-marker="sub"]'));
        Http::assertNothingSent();
    }

    public function test_unknown_device_receipts_and_never_activity_are_explicit_without_false_success_signals(): void
    {
        $xpath = $this->xpath($this->profileComponent()->html());
        foreach (['installation', 'push', 'location'] as $key) {
            $rows = $xpath->query('//*[@data-profile-phone-status]//*[@data-phone-status="'.$key.'" and @data-status="unknown"]');
            $this->assertCount(1, $rows);
            $values = $xpath->query('./dd[contains(@class,"sr-only")]', $rows->item(0));
            $this->assertCount(1, $values);
            $this->assertSame('Nicht gemeldet', $this->text($values->item(0)));
            $triggers = $xpath->query('.//button[@data-state="unknown"]', $rows->item(0));
            $this->assertCount(1, $triggers);
            $this->assertStringContainsString('Nicht gemeldet', $triggers->item(0)->getAttribute('aria-label'));
            $this->assertCount(1, $xpath->query('.//span[contains(@class,"employee-profile__status-signal")]//i[contains(@class,"fa-question")]', $triggers->item(0)));
            $this->assertCount(0, $xpath->query('.//*[@data-state="granted" or @data-state="installed" or @data-state="ready"]', $rows->item(0)));
        }
        $this->assertCount(1, $xpath->query('//*[@data-profile-fact="activity"]//button[@data-state="unknown"]'));
        $this->assertSame(__('app.never'), $this->text($xpath->query('//*[@data-profile-fact="activity"]/dd')->item(0)));
        $installation = $xpath->query('//*[@data-profile-status]//*[@role="dialog" and @aria-label="Web-App"]')->item(0);
        $this->assertStringContainsString('Haupthandy nicht festgelegt', $installation->textContent);
        $this->assertStringContainsString('App-/Berechtigungsnachweise sind noch nicht hinterlegt', $installation->textContent);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertDatabaseCount('employee_location_consents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('accountStates')]
    public function test_account_indicator_uses_exact_account_state_not_recent_online_presence(bool $active): void
    {
        $this->employee->forceFill(['status' => $active])->save();
        DB::table(config('activitylog.table_name'))->insert([
            'log_name' => 'compact-fixture', 'description' => 'Synthetic recent activity',
            'causer_type' => User::class, 'causer_id' => $this->employee->id,
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);
        $xpath = $this->xpath($this->profileComponent()->html());
        $state = $active ? 'active' : 'inactive';
        $value = $active ? 'Aktiv' : 'Inaktiv';
        $trigger = $xpath->query('//*[@data-profile-fact="status"]//button[@data-profile-status-trigger="account-status"]')->item(0);
        $this->assertSame($state, $trigger->getAttribute('data-state'));
        $this->assertStringContainsString('Kontostatus: '.$value, $trigger->getAttribute('aria-label'));
        $this->assertCount(1, $xpath->query('.//span[contains(@class,"employee-profile__status-signal")]//i[contains(@class,"'.($active ? 'fa-check' : 'fa-times').'")]', $trigger));
        $fact = $xpath->query('//*[@data-profile-fact="status"]/dd[@data-profile-account-status="'.$state.'"]');
        $this->assertCount(1, $fact);
        $this->assertSame($value, $this->text($fact->item(0)));
        $this->assertSame($active ? 'true' : 'false', $xpath->query('//*[@data-profile-identity]//*[@data-online]')->item(0)->getAttribute('data-online'));
        $presence = $xpath->query('//*[@data-profile-identity]//*[@data-online]')->item(0);
        $this->assertCount(1, $xpath->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__portrait ")]', $presence));
        $this->assertCount(1, $xpath->query('./span[contains(@class,"sr-only")]', $presence));
        $this->assertCount(0, $xpath->query('//*[@data-profile-identity]//*[contains(@class,"employee-profile__identity-labels")]//*[@data-online]'));
        $this->assertSame(now()->subMinutes(2)->format('d.m.Y H:i'), $this->text($xpath->query('//*[@data-profile-fact="activity"]/dd')->item(0)));
        $this->assertSame($active, $this->employee->fresh()->status);
        Http::assertNothingSent();
    }

    public static function accountStates(): array
    {
        return ['active account' => [true], 'inactive account with recent activity' => [false]];
    }

    public function test_personnel_membership_and_activity_remain_exact_accessible_facts_without_caption_rows(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'SYNTHETIC-COMPACT-NR']);
        $this->employee->forceFill(['created_at' => CarbonImmutable::parse('2024-02-29T12:00:00Z')])->save();
        $xpath = $this->xpath($this->profileComponent()->html());
        foreach (['personnel' => 'SYNTHETIC-COMPACT-NR', 'member' => '29.02.2024', 'activity' => __('app.never')] as $key => $value) {
            $rows = $xpath->query('//*[@data-profile-status]//*[@data-profile-fact="'.$key.'"]');
            $this->assertCount(1, $rows);
            $this->assertCount(1, $xpath->query('./dd[contains(@class,"sr-only")]', $rows->item(0)));
            $this->assertSame($value, $this->text($xpath->query('./dd', $rows->item(0))->item(0)));
            $trigger = $xpath->query('.//button[@data-profile-status-trigger="account-'.$key.'"]', $rows->item(0))->item(0);
            $this->assertStringContainsString($value, $trigger->getAttribute('aria-label'));
            $this->assertSame('', $this->text($trigger));
        }
        $this->assertCount(7, $xpath->query('//*[@data-profile-status]//button[@data-profile-status-trigger]'));
        Http::assertNothingSent();
    }

    public function test_profile_reader_without_device_and_master_scope_keeps_account_icons_without_private_device_or_personnel_disclosures(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'PRIVATE-PERSONNEL-FIXTURE']);
        $reader = $this->reader(['employees.view']);
        $component = Livewire::actingAs($reader)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('PRIVATE-PERSONNEL-FIXTURE');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-profile-status]'));
        $this->assertCount(0, $xpath->query('//*[@data-profile-phone-status]'));
        $this->assertCount(0, $xpath->query('//*[@data-profile-status]//*[@data-phone-status]'));
        $this->assertCount(0, $xpath->query('//*[@data-profile-fact="personnel"]'));
        $this->assertCount(3, $xpath->query('//*[@data-profile-status]//button[@data-profile-status-trigger]'));
        $this->assertCount(0, $xpath->query('//*[@data-profile-identity]//*[@data-native-inline-edit-trigger]'));
        $this->assertCount(0, $xpath->query('//*[@data-panel-tab="locations"]'));
        Http::assertNothingSent();
    }

    public function test_missing_device_storage_keeps_unknown_indicators_and_omits_a_fabricated_zero_phone_count(): void
    {
        Schema::drop('device_assignments');
        $xpath = $this->xpath($this->profileComponent()->html());
        $this->assertCount(3, $xpath->query('//*[@data-profile-phone-status]//*[@data-status="unknown"]'));
        $dialog = $xpath->query('//*[@data-profile-status]//*[@role="dialog" and @aria-label="Web-App"]')->item(0);
        $this->assertStringContainsString('Haupthandy nicht festgelegt', $dialog->textContent);
        $this->assertStringNotContainsString('0 Handy', $dialog->textContent);
        $this->assertStringNotContainsString('Installiert', $dialog->textContent);
        $this->assertStringNotContainsString('Genehmigt', $dialog->textContent);
        Http::assertNothingSent();
    }

    public function test_assigned_phone_count_is_available_only_in_detail_and_never_implies_primary_or_app_permissions(): void
    {
        foreach ([$this->employee, $this->employee, User::factory()->create(['role' => 'staff'])] as $owner) {
            $phone = Device::create(['display_name' => 'PRIVATE-HARDWARE-NAME', 'form_factor' => 'phone',
                'platform' => 'android', 'lifecycle_status' => 'assigned',
                'metadata' => ['is_primary' => true, 'standalone' => true, 'location_permission' => 'granted']]);
            DeviceAssignment::create(['device_id' => $phone->id, 'user_id' => $owner->id,
                'status' => 'active', 'assigned_at' => now()->subHour()]);
        }
        $component = $this->profileComponent()->assertDontSee('PRIVATE-HARDWARE-NAME');
        $xpath = $this->xpath($component->html());
        $dialog = $xpath->query('//*[@data-profile-status]//*[@role="dialog" and @aria-label="Web-App"]')->item(0);
        $this->assertStringContainsString('2 Handys zugewiesen', $this->text($dialog));
        $this->assertStringContainsString('Haupthandy nicht festgelegt', $dialog->textContent);
        $this->assertCount(3, $xpath->query('//*[@data-profile-phone-status]//*[@data-status="unknown"]'));
        $this->assertStringNotContainsString('is_primary', $component->html());
        $this->assertStringNotContainsString('location_permission', $component->html());
        $this->assertDatabaseCount('device_assignments', 3);
        $this->assertDatabaseCount('employee_location_consents', 0);
        Http::assertNothingSent();
    }

    public function test_long_html_like_personnel_and_identity_values_are_escaped_in_icon_labels_and_details(): void
    {
        $personnel = 'Synthetic <img src=x onerror=compactFixture()> & "number"';
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => $personnel]);
        $xpath = $this->xpath($this->profileComponent()->html());
        $trigger = $xpath->query('//*[@data-profile-status]//button[@data-profile-status-trigger="account-personnel"]')->item(0);
        $this->assertStringContainsString($personnel, $trigger->getAttribute('aria-label'));
        $this->assertSame($personnel, $this->text($xpath->query('//*[@data-profile-fact="personnel"]/dd')->item(0)));
        $this->assertCount(0, $xpath->query('//*[@data-profile-status]//img|//*[@data-profile-status]//script|//*[@data-profile-status]//*[@onerror]'));
        foreach (['employee-header-name' => 'name', 'employee-header-email' => 'email'] as $id => $field) {
            $input = $xpath->query('//*[@data-profile-identity]//*[@id="'.$id.'"]')->item(0);
            $this->assertSame('inlineValues.'.$field, $input->getAttribute('wire:model'));
            $this->assertCount(1, $xpath->query('//*[@data-profile-identity]//*[@for="'.$id.'" and @data-native-inline-edit-trigger]'));
        }
        Http::assertNothingSent();
    }

    public function test_compact_status_rendering_keeps_employee_records_and_sensitive_receipts_untouched_and_unread(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'SYNTHETIC-READONLY-NR']);
        $this->employee->updatePushSubscription('https://push.example.invalid/PRIVATE-PUSH', 'PRIVATE-KEY', 'PRIVATE-TOKEN');
        $beforeUser = $this->employee->fresh()->getRawOriginal();
        $beforeProfile = Profile::firstWhere('user_id', $this->employee->id)->getRawOriginal();
        $beforePush = DB::table('push_subscriptions')->get()->toJson();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = $this->profileComponent()->assertDontSee('PRIVATE-PUSH')->assertDontSee('PRIVATE-KEY')->assertDontSee('PRIVATE-TOKEN');
            $component->call('$refresh')->assertSuccessful();
            $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:from|join)\s+["`]?\b(?:push_subscriptions|employee_location_(?:points|consents|consent_events)|mobile_location_(?:installations|codes))\b/i',
                $query['query'],
            ));
            $this->assertSame([], array_values($reads));
            $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:insert\s+into|update|delete\s+from|replace\s+into)\s+["`]?\b(?:users|user_profiles|devices|device_assignments|push_subscriptions|employee_location_\w+|mobile_location_\w+)\b/i',
                $query['query'],
            ));
            $this->assertSame([], array_values($writes));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($beforeUser, $this->employee->fresh()->getRawOriginal());
        $this->assertSame($beforeProfile, Profile::firstWhere('user_id', $this->employee->id)->getRawOriginal());
        $this->assertSame($beforePush, DB::table('push_subscriptions')->get()->toJson());
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertDatabaseCount('employee_location_consents', 0);
        Http::assertNothingSent();
    }

    public function test_compact_geometry_is_profile_scoped_with_wrapping_mobile_icons_and_full_touch_targets(): void
    {
        $css = file_get_contents(resource_path('css/employee-profile-shell.css'));
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__identity\s*\{[^}]*padding:\s*8px 16px !important;/s', $css);
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__identity-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) auto;/s', $css);
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__portrait\s*\{[^}]*width:\s*56px;[^}]*height:\s*56px;/s', $css);
        $this->assertStringContainsString('.employee-profile__name-line { display: flex; align-items: center;', $css);
        $this->assertStringContainsString('.employee-profile__meta-line { display: flex; align-items: center; flex-wrap: wrap;', $css);
        $this->assertStringContainsString('.employee-profile__identity-labels { gap: 4px; margin: 0; }', $css);
        $this->assertStringContainsString('.employee-profile__identity [data-inline-edit-display] { min-height: 44px;', $css);
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__status-rail\s*\{[^}]*flex-wrap:\s*wrap;/s', $css);
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__status-trigger\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s', $css);
        $this->assertStringContainsString('.employee-profile__status-trigger:focus-visible { outline: 2px solid var(--profile-duty-signal);', $css);
        $this->assertStringContainsString("[data-state='active'], [data-state='ready']", $css);
        $this->assertStringContainsString("[data-state='inactive'], [data-state='denied']", $css);
        $this->assertStringNotContainsString(".employee-profile__status-trigger[data-state='unknown'] .employee-profile__status-signal { background: theme('colors.emerald", $css);
        $mobileStart = strpos($css, '@media (max-width: 767.98px)');
        $this->assertNotFalse($mobileStart);
        $tabletStart = strpos($css, '@media (max-width: 1023.98px)');
        $this->assertNotFalse($tabletStart);
        $this->assertStringNotContainsString('employee-profile__identity-layout', substr($css, $tabletStart, $mobileStart - $tabletStart));
        $mobile = substr($css, $mobileStart);
        $this->assertStringContainsString('.employee-profile__identity { padding: 8px 12px !important; }', $mobile);
        $this->assertStringContainsString('.employee-profile__identity-layout { grid-template-columns: minmax(0, 1fr); gap: 8px; }', $mobile);
        $this->assertStringContainsString('.employee-profile__status-group { display: contents; }', $mobile);
        $this->assertStringContainsString('.employee-profile__status-group + .employee-profile__status-group { padding: 0; border: 0; }', $mobile);
        $this->assertStringContainsString('font-size: 20px; white-space: normal; overflow-wrap: anywhere;', $mobile);
        $this->assertMatchesRegularExpression('/\.employee-profile\[data-profile-shell\] \.employee-profile__portrait\s*\{[^}]*width:\s*48px;[^}]*height:\s*48px;/s', $mobile);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $mobile);
        $this->assertStringContainsString('.employee-profile__status-trigger,', substr($mobile, strpos($mobile, '@media (prefers-reduced-motion: reduce)')));
        foreach (['employee-profile__phone-setup', 'employee-profile__account', 'employee-profile__identity-layout--with-phone'] as $removed) {
            $this->assertStringNotContainsString($removed, $css);
        }
    }

    private function profileComponent()
    {
        return Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])->assertSuccessful();
    }

    private function reader(array $abilities): User
    {
        $reader = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false,
            'rbac_permissions' => array_fill_keys($abilities, true)]);
        $reader->teams()->attach($team->id);
        $reader->forceFill(['current_team_id' => $team->id])->save();

        return $reader->fresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function text(DOMElement $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}

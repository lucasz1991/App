<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxSource;
use App\Models\OperationAudit;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\HistoricalContactProvisioner;
use App\Services\Dropbox\WorkbookReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxHistoricalContactProvisionerTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private DropboxConnection $connection;

    private const NAME = 'Emilio Da Silva Muster';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Event::fake([Registered::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-23T12:00:00+02:00'));
        $this->actor = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'refresh_token' => 'synthetic-token', 'settings' => [
            ...config('dropbox.defaults'), 'historical_import_contacts' => true,
        ]]);
    }

    public function test_full_name_contact_and_matching_past_ma_create_one_inactive_unverified_account_without_name_splitting(): void
    {
        $parsed = $this->parsed();
        $source = DropboxSource::create(['connection_id' => $this->connection->id, 'file_id' => 'id:synthetic', 'path' => '/Disposition/Aufträge KW 41.xlsx',
            'name' => 'Aufträge KW 41.xlsx', 'profile' => 'weekly', 'rev' => '1', 'first_seen_at' => now()]);
        $parsed['source_id'] = $source->id;
        $result = $this->reconcile($parsed);
        $user = User::where('name', self::NAME)->firstOrFail();
        $this->assertSame(['created' => 1, 'existing' => 0, 'mapped' => 1, 'review' => 0], $result);
        $this->assertSame(self::NAME, $user->name);
        $this->assertSame('history.person@example.test', $user->email);
        $this->assertSame('staff', $user->role);
        $this->assertFalse($user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertNotEmpty($user->password);
        $this->assertSame(0, UserProfile::count());
        $this->assertSame($user->id, DropboxIdentity::firstOrFail()->user_id);
        $this->assertSame(0, WorkTimeEntry::count());
        $audit = OperationAudit::where('action', 'user.import_history_created')->firstOrFail();
        $this->assertSame($user->id, $audit->data['user_id']);
        $this->assertSame($source->id, $audit->data['source_id']);
        $this->assertSame(['sheet' => 'Mitarbeiterkontakte', 'slot' => '39'], $audit->data['contact']);
        $this->assertSame([['sheet' => 'Disposition', 'slot' => '17']], $audit->data['planning']);
        $this->assertArrayNotHasKey('password', $audit->data);

        $this->assertSame(['created' => 0, 'existing' => 1, 'mapped' => 0, 'review' => 0], $this->reconcile($parsed));
        $this->assertSame(2, User::count());
        $this->assertSame(1, OperationAudit::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(Registered::class);
    }

    public function test_missing_email_uses_the_existing_deterministic_pattern_and_only_explicit_profile_parts(): void
    {
        $parsed = $this->parsed(['contact_email' => '', 'first_name' => 'Emilio', 'last_name' => 'Da Silva Muster']);
        $this->reconcile($parsed);
        $user = User::where('name', self::NAME)->firstOrFail();
        $this->assertSame('import-'.substr(hash('sha256', WorkbookReader::normalize(self::NAME)), 0, 20).'@railtime.invalid', $user->email);
        $this->assertSame('Emilio', $user->profile->first_name);
        $this->assertSame('Da Silva Muster', $user->profile->last_name);
        $this->assertNull($user->profile->phone);
    }

    public function test_existing_inactive_profile_match_is_mapped_without_changing_the_person_or_profile(): void
    {
        $user = User::factory()->create(['name' => 'Historischer Anzeigename', 'role' => 'staff', 'status' => false]);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Emilio', 'last_name' => 'Da Silva Muster', 'phone' => '0123']);
        $before = $user->fresh()->getRawOriginal();
        $profile = $user->profile->getRawOriginal();
        $result = $this->reconcile($this->parsed());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['mapped']);
        $this->assertSame($user->id, DropboxIdentity::first()->user_id);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($profile, $user->profile->fresh()->getRawOriginal());
        $this->assertSame(1, OperationAudit::where('action', 'user.import_history_mapped')->count());
    }

    public function test_equal_active_and_inactive_names_are_reviewed_without_creating_a_third_person(): void
    {
        User::factory()->create(['name' => self::NAME, 'role' => 'staff', 'status' => true]);
        User::factory()->create(['name' => self::NAME, 'role' => 'staff', 'status' => false]);
        $result = $this->reconcile($this->parsed());
        $this->assertSame(1, $result['review']);
        $this->assertSame(0, $result['created']);
        $this->assertNull(DropboxIdentity::first()->user_id);
        $this->assertSame(3, User::count());
    }

    public function test_existing_provider_or_manual_mapping_is_preserved(): void
    {
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => WorkbookReader::normalize(self::NAME), 'kind' => 'provider']);
        $this->assertSame(1, $this->reconcile($this->parsed())['review']);
        $this->assertSame('provider', $identity->fresh()->kind);
        $this->assertSame(1, User::count());

        $person = User::factory()->create(['name' => 'Manuell zugeordnete Person', 'role' => 'staff', 'status' => false]);
        $identity->update(['kind' => 'employee', 'user_id' => $person->id]);
        $this->assertSame(1, $this->reconcile($this->parsed())['existing']);
        $this->assertSame($person->id, $identity->fresh()->user_id);
        $this->assertSame(2, User::count());
        $this->assertSame(0, OperationAudit::count());
    }

    public function test_duplicate_contact_compound_labels_and_provider_contact_collisions_require_review(): void
    {
        $parsed = $this->parsed();
        $parsed['rows'][] = $parsed['rows'][0];
        $this->assertSame(1, $this->reconcile($parsed)['review']);
        foreach (['Erika Muster / Alex Beispiel', 'Erika und Alex', 'Rail Firma', 'Transport GmbH'] as $label) {
            $parsed = $this->parsed();
            $parsed['rows'][0]['locator']['subject'] = $label;
            $parsed['rows'][0]['values']['name'] = $label;
            $parsed['rows'][1]['values']['employee'] = $label;
            $this->assertSame(1, $this->reconcile($parsed)['review']);
        }
        $parsed = $this->parsed();
        $provider = $parsed['rows'][0];
        $provider['locator']['kind'] = 'provider';
        $parsed['rows'][] = $provider;
        $this->assertSame(1, $this->reconcile($parsed)['review']);
        $this->assertSame(1, User::count());
    }

    public function test_existing_email_collision_and_shared_workbook_email_do_not_claim_or_modify_existing_accounts(): void
    {
        $existing = User::factory()->create(['name' => 'Andere Person', 'email' => 'history.person@example.test', 'role' => 'guest']);
        $before = $existing->fresh()->getRawOriginal();
        $this->assertSame(1, $this->reconcile($this->parsed())['review']);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame(2, User::count());

        $parsed = $this->parsed(['contact_email' => 'shared@example.test']);
        $other = $parsed['rows'][0];
        $other['locator']['subject'] = 'Weitere Historische Person';
        $other['values']['name'] = 'Weitere Historische Person';
        $parsed['rows'][] = $other;
        $this->assertSame(1, $this->reconcile($parsed)['review']);
        $this->assertSame(2, User::count());
    }

    public function test_contacts_without_exact_past_ma_in_same_workbook_or_with_running_overnight_plan_do_not_provision(): void
    {
        foreach ([['employee' => 'Andere Person'], ['date' => '2026-10-24'], ['date' => '2026-10-23', 'starts' => '23:00', 'ends' => '04:00'], ['date' => '2026-02-31']] as $changes) {
            $parsed = $this->parsed();
            $parsed['rows'][1]['values'] = [...$parsed['rows'][1]['values'], ...$changes];
            $this->assertSame(0, $this->reconcile($parsed)['created']);
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DropboxIdentity::count());
    }

    public function test_disabled_local_preview_and_inactive_connections_do_not_provision(): void
    {
        foreach ([['historical_import_contacts' => false], ['source' => 'local']] as $settings) {
            $this->connection->update(['settings' => [...config('dropbox.defaults'), 'historical_import_contacts' => true, ...$settings]]);
            $this->assertSame(0, $this->reconcile($this->parsed())['created']);
        }
        $this->connection->update(['settings' => [...config('dropbox.defaults'), 'historical_import_contacts' => true]]);
        foreach ([DropboxMode::Off, DropboxMode::Preview, DropboxMode::AppOnly] as $mode) {
            $this->connection->update(['mode' => $mode]);
            $this->assertSame(0, $this->reconcile($this->parsed())['created']);
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DropboxIdentity::count());
    }

    public function test_generation_change_blocks_provisioning_before_any_person_is_created(): void
    {
        DropboxConnection::whereKey($this->connection->id)->update(['generation' => $this->connection->generation + 1]);
        try {
            $this->reconcile($this->parsed());
            $this->fail('Stale connection must not provision a person.');
        } catch (DropboxApiException $exception) {
            $this->assertSame('configuration_changed', $exception->reason);
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DropboxIdentity::count());
    }

    public function test_disabling_any_required_domain_prevents_account_provisioning(): void
    {
        foreach (['planning', 'assignments', 'contacts'] as $disabled) {
            $this->connection->update(['settings' => [...$this->connection->settings, 'domains' => array_values(array_diff(config('dropbox.defaults.domains'), [$disabled]))]]);
            $this->assertSame(0, $this->reconcile($this->parsed())['created']);
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DropboxIdentity::count());
    }

    private function reconcile(array $parsed): array
    {
        return app(HistoricalContactProvisioner::class)->reconcile($this->connection, $parsed, $this->actor);
    }

    private function parsed(array $values = []): array
    {
        return ['rows' => [
            ['domain' => 'contacts', 'sheet' => 'Mitarbeiterkontakte', 'slot' => '39', 'locator' => ['kind' => 'employee', 'subject' => self::NAME],
                'values' => ['name' => self::NAME, 'contact_email' => 'history.person@example.test', 'reported_qualifications' => 'WGM', 'phone' => '0123', ...$values]],
            ['domain' => 'planning', 'sheet' => 'Disposition', 'slot' => '17', 'locator' => ['master' => true],
                'values' => ['employee' => self::NAME, 'date' => '2026-10-11', 'starts' => '08:00', 'ends' => '10:00']],
        ]];
    }
}

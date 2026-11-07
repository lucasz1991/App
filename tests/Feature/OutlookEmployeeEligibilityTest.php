<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\OutlookAddin\OutlookAddinEmployeeEligibility;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookEmployeeEligibilityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const OBJECT = '22222222-2222-4222-8222-222222222222';

    private User $employee;

    private EmployeeIdentityAccount $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.entra.tenant_id' => self::TENANT, 'outlook_addin.snapshots.auto_refresh' => false,
            'outlook_addin.universal_source.mode' => 'eligible_linked_employees', 'app.url' => 'https://mail.example.test']);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            foreach (['external_id', 'tenant_id', 'principal', 'email', 'lifecycle_status'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamps();
        });
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => false, 'email_verified_at' => null,
            'email' => 'private@example.test']);
        $this->identity = EmployeeIdentityAccount::create(['user_id' => $this->employee->id, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'employee@company.test',
            'email' => 'alias@company.test', 'lifecycle_status' => 'active']);
        $this->app->bind(EmployeeWorkplaceProvisioner::class, static fn () => throw new \LogicException('Outlook must never activate or verify a RailTime account.'));
    }

    public static function registrationStates(): iterable
    {
        foreach (['staff', 'admin'] as $role) {
            foreach ([false, true] as $active) {
                foreach ([false, true] as $verified) {
                    yield "$role status=$active verified=$verified" => [$role, $active, $verified];
                }
            }
        }
    }

    #[DataProvider('registrationStates')]
    public function test_outlook_access_never_completes_registration(string $role, bool $active, bool $verified): void
    {
        $this->employee->update(['role' => $role, 'status' => $active, 'email_verified_at' => $verified ? now() : null]);
        $before = $this->rows();
        $helper = app(OutlookAddinEmployeeEligibility::class);
        self::assertTrue($helper->permits($this->employee));
        self::assertTrue($helper->canPrepareSnapshot($this->employee));
        $resolver = app(OutlookAddinIdentityResolver::class);
        $identity = new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'employee@company.test', 'Synthetic');
        $resolved = $resolver->resolve($identity, 'EMPLOYEE@COMPANY.TEST', 'alias@company.test');
        self::assertSame($this->employee->id, $resolved['user']->id);
        self::assertSame(['employee@company.test', 'alias@company.test'], $resolved['binding']['allowedSenderAddresses']);
        self::assertSame($this->employee->id, $resolver->resolveOwnAccount($identity, 'alias@company.test')['user']->id);
        self::assertTrue(OutlookUniversalPayloadPresentation::isSourceAllowed($this->employee));
        self::assertSame($before, $this->rows());
        Http::assertNothingSent();
    }

    public static function deniedBindings(): array
    {
        return array_map(static fn (string $case): array => [$case], ['guest', 'customer', 'superadmin', 'deleted-user', 'unlinked',
            'disabled', 'foreign-tenant', 'missing-tenant', 'missing-config', 'wrong-provider', 'invalid-oid', 'invalid-principal',
            'invalid-email', 'entra-guest', 'duplicate-oid', 'second-binding', 'principal-collision', 'alias-collision']);
    }

    #[DataProvider('deniedBindings')]
    public function test_ineligible_or_ambiguous_identity_never_falls_back_to_profile_email(string $case): void
    {
        $changes = match ($case) {
            'unlinked' => ['user_id' => null], 'disabled' => ['lifecycle_status' => 'disabled'],
            'foreign-tenant' => ['tenant_id' => '99999999-9999-4999-8999-999999999999'], 'missing-tenant' => ['tenant_id' => null],
            'wrong-provider' => ['provider' => AccountProvider::GoogleWorkspace], 'invalid-oid' => ['external_id' => 'not-an-oid'],
            'invalid-principal' => ['principal' => 'invalid'], 'invalid-email' => ['email' => 'invalid'],
            'entra-guest' => ['principal' => 'employee_company.test#EXT#@tenant.onmicrosoft.com'], default => [],
        };
        if ($changes !== []) {
            $this->identity->update($changes);
        }
        if (in_array($case, ['guest', 'customer', 'superadmin'], true)) {
            $this->employee->update(['role' => $case]);
        } elseif ($case === 'deleted-user') {
            DB::table('users')->where('id', $this->employee->id)->delete();
        } elseif ($case === 'missing-config') {
            config(['outlook_addin.entra.tenant_id' => '']);
        } elseif (in_array($case, ['duplicate-oid', 'second-binding', 'principal-collision', 'alias-collision'], true)) {
            $other = User::factory()->create(['role' => 'staff']);
            EmployeeIdentityAccount::create(['user_id' => $case === 'second-binding' ? $this->employee->id : $other->id,
                'provider' => AccountProvider::Microsoft365, 'tenant_id' => self::TENANT,
                'external_id' => $case === 'duplicate-oid' ? strtoupper(self::OBJECT) : '33333333-3333-4333-8333-333333333333',
                'principal' => $case === 'principal-collision' ? 'EMPLOYEE@COMPANY.TEST' : 'other@company.test',
                'email' => $case === 'alias-collision' ? 'ALIAS@COMPANY.TEST' : null, 'lifecycle_status' => 'active']);
        }
        $before = $this->rows();
        self::assertFalse(app(OutlookAddinEmployeeEligibility::class)->permits($this->employee));
        self::assertFalse(OutlookUniversalPayloadPresentation::isSourceAllowed($this->employee));
        try {
            app(OutlookAddinIdentityResolver::class)->resolve(new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'employee@company.test', ''), 'employee@company.test', 'employee@company.test');
            self::fail('Invalid binding must fail closed.');
        } catch (OutlookAddinException $error) {
            self::assertSame('outlook_addin_identity_not_linked', $error->errorCode);
        }
        self::assertSame($before, $this->rows());
    }

    public function test_optional_account_email_and_unrelated_disabled_history_do_not_block_a_unique_principal(): void
    {
        $this->identity->update(['email' => null]);
        EmployeeIdentityAccount::create(['user_id' => $this->employee->id, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => '33333333-3333-4333-8333-333333333333',
            'principal' => 'old@company.test', 'lifecycle_status' => 'disabled']);
        self::assertTrue(app(OutlookAddinEmployeeEligibility::class)->permits($this->employee));
        $identity = new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'employee@company.test', '');
        self::assertSame(['employee@company.test'], app(OutlookAddinIdentityResolver::class)->resolve($identity, 'employee@company.test', 'employee@company.test')['binding']['allowedSenderAddresses']);
    }

    public function test_current_user_and_persisted_identity_are_required_not_forged_profile_fields_or_client_mailbox(): void
    {
        $helper = app(OutlookAddinEmployeeEligibility::class);
        self::assertFalse($helper->permits((new User)->forceFill(['id' => $this->employee->id, 'role' => 'staff', 'email' => 'employee@company.test'])));
        $identity = new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'employee@company.test', '');
        foreach ([['private@example.test', 'employee@company.test'], ['employee@company.test', 'private@example.test']] as [$mailbox, $sender]) {
            try {
                app(OutlookAddinIdentityResolver::class)->resolve($identity, $mailbox, $sender);
                self::fail('Profile email is not a Microsoft mailbox alias.');
            } catch (OutlookAddinException $error) {
                self::assertContains($error->errorCode, ['outlook_addin_mailbox_mismatch', 'outlook_addin_sender_mismatch']);
            }
        }
        DB::table('users')->where('id', $this->employee->id)->update(['role' => 'customer']);
        self::assertFalse($helper->permits($this->employee), 'Stale in-memory staff role must not authorize.');
    }

    public function test_linked_source_rollout_serves_new_employees_without_expanding_it_fixture_pilot(): void
    {
        $sourceTest = new OutlookUniversalPayloadPresentationTest('test_additive_projection_preserves_every_legacy_field_and_adds_bounded_editor_documents');
        $canonical = (new ReflectionMethod($sourceTest, 'payload'))->invoke($sourceTest);
        $profile = (new ReflectionMethod($sourceTest, 'profile'))->invoke($sourceTest);
        $before = $this->rows();
        $projection = new OutlookUniversalPayloadPresentation(new OutlookUniversalPresentation);
        foreach (['Outlook', 'OutlookWebApp', 'OutlookIOS'] as $host) {
            $result = $projection->append($canonical, $canonical, $profile, $this->employee, ['host' => $host]);
            self::assertArrayHasKey('universalSignature', $result);
            self::assertArrayHasKey('universalCompose', $result['templates'][0]);
        }
        self::assertFalse(OutlookUniversalPayloadPresentation::isPilot($this->employee));
        self::assertFalse(OutlookUniversalDiagnosticFixtureStore::allowed($this->employee, 'it-technik@rail-time.de'));
        self::assertSame($before, $this->rows());
        foreach ([null, '', 'all', 'typo'] as $mode) {
            config(['outlook_addin.universal_source.mode' => $mode]);
            self::assertFalse(OutlookUniversalPayloadPresentation::isSourceAllowed($this->employee));
        }
    }

    private function rows(): array
    {
        return [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('employee_identity_accounts')->orderBy('id')->get()->toJson()];
    }
}

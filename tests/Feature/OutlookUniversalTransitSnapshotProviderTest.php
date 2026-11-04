<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Support\Mail\OutlookUniversalTransitSnapshotProvider;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookUniversalTransitSnapshotProviderTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::create('employee_identity_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->string('external_id');
            $table->string('tenant_id');
            $table->string('principal');
            $table->string('email');
            $table->string('lifecycle_status');
            $table->timestamps();
        });
        config(['app.url' => 'https://app.rail-time.de', 'outlook_addin.entra.tenant_id' => self::TENANT]);
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_bound_pilot_resolves_actual_pinned_media_without_business_writes_or_transport(): void
    {
        [$user, $service] = $this->bound();
        $before = $this->state();
        $result = $service->resolve(self::TENANT, $user->id, $user->email, $service->draft['header']);
        self::assertTrue($result['ok']);
        self::assertSame($service->draft, $result['projection']);
        self::assertCount(13, $result['media']);
        foreach ($result['media'] as $entry) {
            $bytes = base64_decode($entry['dataBase64'], true);
            self::assertSame($entry['sha256'], hash('sha256', $bytes));
            self::assertSame('image/png', getimagesizefromstring($bytes)['mime']);
            self::assertSame(file_get_contents(public_path(parse_url($entry['source'], PHP_URL_PATH))), $bytes);
        }
        self::assertSame($before, $this->state());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    #[DataProvider('invalidBindings')]
    public function test_binding_failure_precedes_any_source_read(string $case): void
    {
        [$user, $service] = $this->bound();
        $tenant = self::TENANT;
        $id = $user->id;
        $sender = $user->email;
        $header = $service->draft['header'];
        match ($case) {
            'tenant' => $tenant = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'user' => $id++, 'sender' => $sender = 'other@example.invalid', 'header' => $header .= "\r\nX-Test: 1",
            'inactive' => $user->update(['status' => false]),
            'unverified' => DB::table('users')->where('id', $id)->update(['email_verified_at' => null]),
            'identity-tenant' => EmployeeIdentityAccount::query()->update(['tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']),
            'identity-inactive' => EmployeeIdentityAccount::query()->update(['lifecycle_status' => 'disabled']),
            'identity-address' => EmployeeIdentityAccount::query()->update(['email' => 'other@example.invalid', 'principal' => 'other@example.invalid']),
            'duplicate' => EmployeeIdentityAccount::query()->first()->replicate()->save(),
        };
        try {
            $service->resolve($tenant, $id, $sender, $header);
            self::fail('Binding accepted');
        } catch (RuntimeException $error) {
            self::assertSame('U2E_TRANSIT_BINDING_INVALID', $error->getMessage());
            self::assertSame(0, $service->reads);
        }
    }

    #[DataProvider('sourceFailures')]
    public function test_stale_custom_invalid_media_and_racing_source_never_produce_a_snapshot(string $case, string $code): void
    {
        [$user, $service] = $this->bound();
        $header = $service->draft['header'];
        match ($case) {
            'old-version' => $header = 'u2e-'.str_repeat('0', 24),
            'custom' => $service->draft = null,
            'media' => $service->draft['media'][0]['sha256'] = str_repeat('0', 64),
            'url' => $service->draft['media'][0]['url'] = 'https://other.invalid/m/b983af0049.png',
            'race' => $service->race = true,
            'identity-race' => $service->identityRace = true,
            'duplicate-race' => $service->duplicateRace = true,
        };
        $this->expectExceptionMessage($code);
        $service->resolve(self::TENANT, $user->id, $user->email, $header);
    }

    public static function invalidBindings(): array
    {
        return array_map(fn ($value) => [$value], ['tenant', 'user', 'sender', 'header', 'inactive', 'unverified', 'identity-tenant', 'identity-inactive', 'identity-address', 'duplicate']);
    }

    public static function sourceFailures(): array
    {
        return [['old-version', 'U2E_TRANSIT_VERSION_NOT_CURRENT'], ['custom', 'U2E_TRANSIT_SOURCE_REJECTED'],
            ['media', 'U2E_TRANSIT_ASSET_INVALID'], ['url', 'U2E_TRANSIT_ASSET_INVALID'],
            ['race', 'U2E_TRANSIT_SOURCE_CHANGED'], ['identity-race', 'U2E_TRANSIT_SOURCE_CHANGED'],
            ['duplicate-race', 'U2E_TRANSIT_SOURCE_CHANGED']];
    }

    private function bound(): array
    {
        $user = User::factory()->create(['email' => 'it-technik@rail-time.de', 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        EmployeeIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'tenant_id' => self::TENANT,
            'principal' => $user->email, 'email' => $user->email, 'lifecycle_status' => 'active']);
        $descriptor = ['sourceHash' => hash('sha256', 'synthetic publication'), 'kind' => 'employee',
            'identity' => ['name' => 'Test Person', 'role' => 'IT'],
            'direct' => [['type' => 'email', 'value' => $user->email, 'label' => $user->email], ['type' => 'phone', 'value' => '+491705550101', 'label' => '+49 170 5550101']],
            'company' => ['name' => 'Muster GmbH', 'website' => 'https://example.invalid', 'websiteLabel' => 'example.invalid', 'street' => 'Musterweg 1', 'postalCity' => '10115 Berlin', 'registration' => 'HRB 123', 'confidentiality' => 'Vertraulich.'],
            'dispatch' => ['email' => 'dispo@example.invalid', 'whatsapp' => 'https://wa.me/491601881848', 'phone' => '+491601881839', 'phoneLabel' => '+49 160 1881839']];
        $draft = (new OutlookUniversalPresentation)->render($descriptor, new HtmlString('<p>Synthetic author.</p>'), ['mode' => 'send']);
        $service = new class(app(OutlookAddinPayloadService::class), app(OutlookUniversalPayloadPresentation::class), $draft) extends OutlookUniversalTransitSnapshotProvider
        {
            public int $reads = 0;

            public bool $race = false;

            public bool $identityRace = false;

            public bool $duplicateRace = false;

            private int $fingerprints = 0;

            public function __construct($payloads, $universal, public ?array $draft)
            {
                parent::__construct($payloads, $universal);
            }

            protected function fingerprint(User $user): string
            {
                return hash('sha256', $this->race ? (string) $this->fingerprints++ : 'same');
            }

            protected function projection(User $user): ?array
            {
                $this->reads++;
                if ($this->identityRace) {
                    EmployeeIdentityAccount::query()->update(['lifecycle_status' => 'disabled']);
                }
                if ($this->duplicateRace) {
                    EmployeeIdentityAccount::query()->first()->replicate()->save();
                }

                return $this->draft;
            }
        };

        return [$user, $service];
    }

    private function state(): string
    {
        return hash('sha256', json_encode(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), ['users', 'user_profiles', 'employee_identity_accounts', 'settings']), JSON_THROW_ON_ERROR));
    }
}

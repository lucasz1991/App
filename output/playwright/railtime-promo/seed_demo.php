<?php

declare(strict_types=1);

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceEnrollmentStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceEnrollment;
use App\Models\File;
use App\Models\FilePool;
use App\Models\MailDocument;
use App\Models\Message;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\MailSignature;
use App\Support\Rbac\RbacCatalog;
use App\Support\Mail\SignatureDocumentContract;
use App\Services\DeviceManagement\DeviceProvisioningProfileCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::transaction(function (): void {
    $admin = User::query()->create([
        'name' => 'RailTime Demo',
        'email' => 'demo.admin@rail-time.test',
        'password' => Hash::make('RailTime-Demo-2026!'),
        'role' => 'admin',
        'status' => true,
        'email_verified_at' => now(),
        'locale' => 'de',
    ]);
    $admin->forceFill(['email_verified_at' => now()])->save();

    $allPermissions = array_fill_keys(RbacCatalog::allPermissions(), true);
    $adminTeam = Team::forceCreate([
        'user_id' => $admin->id,
        'name' => 'Administratoren',
        'personal_team' => false,
        'rbac_permissions' => $allPermissions,
    ]);
    $employeeTeam = Team::forceCreate([
        'user_id' => $admin->id,
        'name' => 'Mitarbeiter',
        'personal_team' => false,
        'rbac_permissions' => array_fill_keys(RbacCatalog::allPermissions(), false),
    ]);
    $managementTeam = Team::forceCreate([
        'user_id' => $admin->id,
        'name' => 'Verwaltung',
        'personal_team' => false,
        'rbac_permissions' => $allPermissions,
    ]);

    $admin->teams()->attach($adminTeam->id, ['role' => 'admin']);
    $admin->forceFill(['current_team_id' => $adminTeam->id])->save();

    $people = collect([
        ['Mara König', 'mara.koenig@rail-time.test', 'Disponentin'],
        ['Jonas Weber', 'jonas.weber@rail-time.test', 'Wagenmeister'],
        ['Leonie Hartmann', 'leonie.hartmann@rail-time.test', 'Verwaltung'],
        ['Tobias Berger', 'tobias.berger@rail-time.test', 'Triebfahrzeugführer'],
    ])->map(function (array $person) use ($admin, $employeeTeam, $managementTeam): User {
        $user = User::query()->create([
            'name' => $person[0],
            'email' => $person[1],
            'password' => Hash::make('RailTime-Demo-2026!'),
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
            'locale' => 'de',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $team = $person[2] === 'Verwaltung' ? $managementTeam : $employeeTeam;
        $user->teams()->attach($team->id, ['role' => 'editor']);
        $user->forceFill(['current_team_id' => $team->id])->save();
        UserProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => strtok($person[0], ' '),
            'last_name' => trim(substr($person[0], strpos($person[0], ' ') + 1)),
            'phone' => '+49 40 555 0100',
            'mobile' => '+49 170 555 0100',
            'position' => $person[2],
            'city' => 'Hamburg',
            'country' => 'Deutschland',
        ]);

        return $user;
    });

    UserProfile::query()->create([
        'user_id' => $admin->id,
        'first_name' => 'RailTime',
        'last_name' => 'Demo',
        'phone' => '+49 40 555 0000',
        'mobile' => '+49 170 555 0000',
        'position' => 'Geschäftsführung',
        'city' => 'Hamburg',
        'country' => 'Deutschland',
    ]);

    foreach ($people as $index => $user) {
        activity()->causedBy($user)->log(match ($index) {
            0 => 'Auftragsplanung aktualisiert',
            1 => 'Wagenliste geprüft',
            2 => 'Mitarbeiterprofil freigegeben',
            default => 'Schicht bestätigt',
        });
    }

    $mara = $people->first();
    $jonas = $people->get(1);
    $leonie = $people->get(2);

    $direct = Chat::directBetween($admin, $mara);
    foreach ([
        [$mara, 'Guten Morgen! Die Wagenliste für den Nordkorridor ist vollständig.'],
        [$admin, 'Perfekt, danke. Bitte die aktualisierten Unterlagen direkt im Chat bereitstellen.'],
        [$mara, 'Erledigt – das Team kann jetzt darauf zugreifen.'],
    ] as $offset => [$sender, $body]) {
        $message = ChatMessage::query()->create([
            'chat_id' => $direct->id,
            'user_id' => $sender->id,
            'body' => $body,
            'message_type' => 'text',
        ]);
        $message->forceFill(['created_at' => now()->subMinutes(18 - ($offset * 6))])->save();
    }

    $group = Chat::query()->create([
        'type' => 'group',
        'name' => 'Disposition Nord',
        'created_by' => $admin->id,
    ]);
    $group->participants()->attach([
        $admin->id => ['last_read_at' => now(), 'last_opened_at' => now(), 'joined_at' => now()],
        $mara->id => ['last_read_at' => now(), 'last_opened_at' => now(), 'joined_at' => now()],
        $jonas->id => ['last_read_at' => now()->subHour(), 'last_opened_at' => now()->subHour(), 'joined_at' => now()],
    ]);
    ChatMessage::query()->create([
        'chat_id' => $group->id,
        'user_id' => $jonas->id,
        'body' => 'Übergabe Gleis 7 ist bestätigt. Alle Beteiligten sind informiert.',
        'message_type' => 'text',
    ]);

    foreach ([
        ['Neue Einsatzunterlagen verfügbar', 'Die aktualisierte Wagenliste und Sicherheitsunterweisung stehen bereit.', '/files', 'Dateien öffnen'],
        ['Meeting heute um 14:30 Uhr', 'Die Einladung zur Abstimmung mit der Disposition wurde hinterlegt.', '/calls', 'Meeting öffnen'],
        ['Geräteeinrichtung vorbereitet', 'Das neue Dienstgerät kann jetzt angemeldet und automatisch eingerichtet werden.', '/administrator/geraete', 'Geräte öffnen'],
    ] as [$subject, $body, $url, $label]) {
        Message::query()->create([
            'subject' => $subject,
            'message' => $body,
            'action_url' => $url,
            'action_label' => $label,
            'from_user' => $leonie->id,
            'to_user' => $admin->id,
            'status' => 1,
        ]);
    }

    $meeting = Room::query()->create([
        'name' => 'Tageslage Verwaltung & Disposition',
        'type' => 'meeting',
        'status' => 'pending',
        'owner_id' => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'settings' => ['video' => true, 'chat' => true],
    ]);
    foreach ([[$admin, 'host'], [$mara, 'speaker'], [$leonie, 'speaker']] as [$user, $role]) {
        RoomParticipant::query()->create([
            'room_id' => $meeting->id,
            'user_id' => $user->id,
            'role' => $role,
            'connection' => 'invited',
            'livekit_identity' => 'demo-user-'.$user->id,
        ]);
    }

    $history = Room::query()->create([
        'name' => 'Wochenplanung Betriebsführung',
        'type' => 'meeting',
        'status' => 'ended',
        'owner_id' => $admin->id,
        'scheduled_at' => now()->subDay(),
        'started_at' => now()->subDay()->subMinutes(45),
        'connected_at' => now()->subDay()->subMinutes(44),
        'ended_at' => now()->subDay(),
        'ended_reason' => 'completed',
        'settings' => ['video' => true, 'chat' => true],
    ]);
    RoomParticipant::query()->create([
        'room_id' => $history->id,
        'user_id' => $admin->id,
        'role' => 'host',
        'connection' => 'left',
        'livekit_identity' => 'demo-history-admin',
        'joined_at' => $history->started_at,
        'left_at' => $history->ended_at,
    ]);

    $pool = FilePool::query()->create([
        'title' => 'Geschäftsführung & Verwaltung',
        'type' => 'company',
        'description' => 'Zentrale, rollenbasierte Dokumentbereitstellung',
        'filepoolable_type' => Team::class,
        'filepoolable_id' => $adminTeam->id,
    ]);
    foreach ([
        ['Einsatzplanung September.pdf', 'application/pdf', 'PDF', 284000],
        ['Sicherheitsunterweisung 2026.pdf', 'application/pdf', 'PDF', 412000],
        ['Übergabeprotokoll Nord.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'EXCEL', 96000],
        ['Leitfaden Kommunikation.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'WORD', 138000],
    ] as $index => [$name, $mime, $type, $size]) {
        $path = 'promo-video/'.($index + 1).'-'.strtolower(str_replace(' ', '-', $name)).'.txt';
        Storage::disk('private')->put($path, 'RailTime Demo-Dokument: '.$name);
        $pool->files()->create([
            'filepool_id' => $pool->id,
            'user_id' => $admin->id,
            'name' => $name,
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $mime,
            'type' => $type,
            'size' => $size,
            'shared_roles' => ['admin', 'staff'],
            'visible_teams' => [$adminTeam->id, $managementTeam->id],
        ]);
    }

    $devices = collect([
        ['RT-NB-1042', 'Dienstnotebook Verwaltung', DevicePlatform::Windows, DeviceLifecycleStatus::InService, DeviceManagementStatus::Managed, DeviceComplianceStatus::Compliant, 'Dell', 'Latitude 7450', 'Hamburg · Verwaltung'],
        ['RT-IP-0218', 'iPhone Einsatzleitung', DevicePlatform::IOS, DeviceLifecycleStatus::Preparing, DeviceManagementStatus::Pending, DeviceComplianceStatus::Warning, 'Apple', 'iPhone 16', 'Hamburg · Übergabe'],
        ['RT-MB-0088', 'MacBook Geschäftsführung', DevicePlatform::MacOS, DeviceLifecycleStatus::Assigned, DeviceManagementStatus::Managed, DeviceComplianceStatus::Compliant, 'Apple', 'MacBook Air', 'Hamburg · Geschäftsführung'],
        ['RT-TB-0311', 'Tablet Wagenmeister', DevicePlatform::Android, DeviceLifecycleStatus::Inventory, DeviceManagementStatus::Unmanaged, DeviceComplianceStatus::Unknown, 'Samsung', 'Galaxy Tab Active', 'Lager Hamburg'],
    ])->map(function (array $entry) use ($admin): Device {
        return Device::query()->create([
            'asset_tag' => $entry[0],
            'serial_number' => 'DEMO-'.str_replace('RT-', '', $entry[0]),
            'hostname' => strtolower(str_replace('-', '', $entry[0])),
            'display_name' => $entry[1],
            'form_factor' => str_contains($entry[0], 'IP') ? 'phone' : (str_contains($entry[0], 'TB') ? 'tablet' : 'notebook'),
            'platform' => $entry[2],
            'ownership' => 'corporate',
            'lifecycle_status' => $entry[3],
            'management_status' => $entry[4],
            'compliance_status' => $entry[5],
            'primary_provider' => 'simulation',
            'manufacturer' => $entry[6],
            'model' => $entry[7],
            'os_version' => 'Aktuell',
            'declared_location' => $entry[8],
            'last_seen_at' => now()->subMinutes(8),
            'last_synced_at' => now()->subMinutes(5),
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    });

    foreach ([[$devices->get(0), $leonie], [$devices->get(1), $mara], [$devices->get(2), $admin]] as [$device, $user]) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => DeviceAssignment::STATUS_ACTIVE,
            'assigned_by' => $admin->id,
            'assigned_at' => now()->subDays(4),
            'handover_at' => now()->subDays(3),
            'handover_notes' => 'Automatisierte RailTime-Bereitstellung',
        ]);
    }

    $pendingAssignment = DeviceAssignment::query()->where('device_id', $devices->get(1)->id)->firstOrFail();
    $enrollment = new DeviceEnrollment([
        'device_id' => $devices->get(1)->id,
        'user_id' => $mara->id,
        'device_assignment_id' => $pendingAssignment->id,
        'provider' => 'simulation',
        'mode' => 'ade',
        'status' => DeviceEnrollmentStatus::Invited,
        'invited_at' => now()->subHour(),
        'expires_at' => now()->addDays(5),
        'created_by' => $admin->id,
        'metadata' => ['purpose' => 'promo-demo'],
    ]);
    $enrollment->setPlainToken('railtime-demo-enrollment-token-2026-0000000001')->save();

    foreach ([
        ['identity', 'Benutzerkonto zugeordnet', 'passed'],
        ['applications', 'Pflichtanwendungen vorbereitet', 'passed'],
        ['certificate', 'Gerätezertifikat ausstehend', 'pending'],
        ['compliance', 'Sicherheitsrichtlinien werden geprüft', 'pending'],
    ] as [$key, $label, $status]) {
        DB::table('device_readiness_checks')->insert([
            'device_id' => $devices->get(1)->id,
            'check_key' => $key,
            'label' => $label,
            'status' => $status,
            'source' => 'simulation',
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(DeviceProvisioningProfileCatalog::class)->ensurePersisted($admin);

    foreach (MailDocumentKind::cases() as $kind) {
        if ($kind === MailDocumentKind::Template) {
            $html = (string) file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        } else {
            $tokens = [];
            foreach (array_keys(MailSignature::forCompany()->values([], CompanyData::defaults())) as $key) {
                $tokens[$key] = '{{'.$key.'}}';
            }
            $html = view('emails.parts.signature', ['values' => $tokens])->render();
        }

        $html = trim(app(EmailHtmlSanitizer::class)->assertClean(trim($html))->html);
        $builderData = [
            'pages' => [['name' => $kind->label(), 'component' => $html]],
            'styles' => [],
            'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA],
        ];
        MailDocument::query()->create([
            'kind' => $kind,
            'name' => $kind === MailDocumentKind::Template ? 'RailTime Standardvorlage' : 'RailTime Standardsignatur',
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
            'builder_data' => $builderData,
            'html' => $html,
            'css' => '',
            'published_html' => $html,
            'published_css' => '',
            'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor($builderData, $html, ''),
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }
});

fwrite(STDOUT, "RailTime promo demo data created.\n");

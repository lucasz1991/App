<?php

namespace App\Support\OutlookAddin;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use JsonException;
use RuntimeException;
use ZipArchive;

/**
 * Creates an offline deployment package without contacting Microsoft 365.
 *
 * The contained Exchange script is deliberately inert by default. It does
 * not even establish a tenant connection until an administrator explicitly
 * sets $ApplyChanges to $true after reviewing the generated signature.
 */
final class OutlookDeploymentPackage
{
    private const ARCHIVE_NAME = 'RailTime-Outlook-Bereitstellung.zip';

    private const MAX_SIGNATURE_BYTES = 2_097_152;

    public function __construct(private readonly OutlookAddinManifest $manifest) {}

    /**
     * @param  callable(?User): (string|array{content: string})|null  $signatureSource
     * @return array{filename: string, mime: string, content: string, meta: array<string, mixed>}
     */
    public function build(?User $user = null, ?callable $signatureSource = null): array
    {
        $this->manifest->assertReady();

        $manifestXml = $this->manifest->render();
        [$signatureHtml, $personalReplacements] = $this->signatureSource($user, $signatureSource);
        $signatureHtml = $this->prepareSignature($signatureHtml, $personalReplacements);
        $generatedAt = now()->utc()->toIso8601String();
        $manifestHash = hash('sha256', $manifestXml);
        $signatureHash = hash('sha256', $signatureHtml);
        $script = $this->exchangeFallbackScript($manifestHash, $signatureHash);
        $readme = $this->readme($generatedAt, $manifestHash, $signatureHash, $user);
        $files = [
            'manifest.xml' => $manifestXml,
            'README.txt' => $readme,
            'ExchangeFallback.ps1' => $script,
            'signature.html' => $signatureHtml,
        ];
        $meta = $this->meta($generatedAt, $user, $files);
        $files['meta.json'] = $this->json($meta);

        return [
            'filename' => self::ARCHIVE_NAME,
            'mime' => 'application/zip',
            'content' => $this->zip($files),
            'meta' => $meta,
        ];
    }

    /**
     * @param  callable(?User): (string|array{content: string})|null  $signatureSource
     * @return array{0: string, 1: array<string, string>}
     */
    private function signatureSource(?User $user, ?callable $signatureSource): array
    {
        if ($signatureSource !== null) {
            $result = $signatureSource($user);
            $content = is_array($result) ? ($result['content'] ?? null) : $result;

            if (! is_string($content)) {
                throw new RuntimeException('Die injizierte Signaturquelle hat keinen HTML-Inhalt geliefert.');
            }

            return [$content, []];
        }

        if (! $user instanceof User) {
            throw new RuntimeException('Fuer das Bereitstellungspaket fehlt der Signaturbenutzer.');
        }

        [$placeholderUser, $personalReplacements] = $this->exchangePlaceholderUser($user);
        $builder = new EmailTemplateBuilder($placeholderUser);

        return [
            $builder->buildOutlookAddinSignatureHtml('light'),
            $personalReplacements,
        ];
    }

    /** @param array<string, string> $personalReplacements */
    private function prepareSignature(string $html, array $personalReplacements): string
    {
        $html = trim($this->bodyFragment($html));

        if ($html === '') {
            throw new RuntimeException('Die Signaturquelle ist leer.');
        }

        if (strlen($html) > self::MAX_SIGNATURE_BYTES) {
            throw new RuntimeException('Die Signaturquelle ist fuer das Exchange-Fallbackpaket zu gross.');
        }

        if (preg_match('/<\s*(script|iframe|object|embed|form)\b/i', $html)
            || preg_match('/<[^>]+\son[a-z0-9_-]+\s*=/i', $html)
            || preg_match('/javascript\s*:/i', $html)) {
            throw new RuntimeException('Die Signaturquelle enthaelt aktive oder nicht erlaubte HTML-Inhalte.');
        }

        $html = str_replace(array_keys($this->exchangeTokenMap()), array_values($this->exchangeTokenMap()), $html);
        $html = str_replace(
            array_keys($personalReplacements),
            array_values($personalReplacements),
            $html,
        );

        $marker = htmlspecialchars($this->manifest->marker(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $markerHtml = '<!-- '.$marker." -->\n"
            .'<span aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'
            .$marker
            .'</span>';

        return $markerHtml.$html."\n";
    }

    private function bodyFragment(string $html): string
    {
        if (preg_match('~<body\b[^>]*>(.*)</body\s*>~is', $html, $matches) === 1) {
            return (string) $matches[1];
        }

        return $html;
    }

    /**
     * Render every optional personal row before converting it to Exchange
     * placeholders. Rendering the downloading administrator's real profile
     * would otherwise permanently omit rows which happen to be empty there.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    private function exchangePlaceholderUser(User $source): array
    {
        $user = $source->replicate();
        $user->forceFill([
            'name' => 'RTEXCHANGEDISPLAYNAME',
            'email' => 'rtexchangeemail@example.invalid',
        ]);
        $user->setRelation('profile', new UserProfile([
            'position' => 'RTEXCHANGETITLE',
            'phone' => '+49 000 000001',
            'mobile' => '+49 000 000002',
        ]));

        return [$user, [
            'RTEXCHANGEDISPLAYNAME' => '%%DisplayName%%',
            'RTEXCHANGETITLE' => '%%Title%%',
            '+49 000 000001' => '%%Phone%%',
            '+49000000001' => '%%Phone%%',
            '+49 000 000002' => '%%MobilePhone%%',
            '+49000000002' => '%%MobilePhone%%',
            'rtexchangeemail@example.invalid' => '%%WindowsEmailAddress%%',
        ]];
    }

    /** @return array<string, string> */
    private function exchangeTokenMap(): array
    {
        return [
            '{{VORNAME_NACHNAME}}' => '%%DisplayName%%',
            '{{POSITION}}' => '%%Title%%',
            '{{DURCHWAHL}}' => '%%Phone%%',
            '{{DURCHWAHL_TEL}}' => '%%Phone%%',
            '{{MOBIL}}' => '%%MobilePhone%%',
            '{{MOBIL_TEL}}' => '%%MobilePhone%%',
            '{{E_MAIL}}' => '%%WindowsEmailAddress%%',
        ];
    }

    private function exchangeFallbackScript(string $manifestHash, string $signatureHash): string
    {
        $metadata = $this->manifest->metadata();
        $tenantId = $this->powershellLiteral((string) $metadata['tenant_id']);
        $marker = $this->powershellLiteral((string) $metadata['marker']);
        $manifestHash = $this->powershellLiteral($manifestHash);
        $signatureHash = $this->powershellLiteral($signatureHash);

        $script = <<<'POWERSHELL'
#Requires -Version 5.1

$ErrorActionPreference = 'Stop'
$ApplyChanges = $false
$EnableRule = $false

$ExpectedTenantId = __TENANT_ID__
$Marker = __MARKER__
$ManifestSha256 = __MANIFEST_HASH__
$SignatureSha256 = __SIGNATURE_HASH__
$RuleName = 'RailTime - Signatur-Fallback'
$SignaturePath = Join-Path $PSScriptRoot 'signature.html'

if (-not (Test-Path -LiteralPath $SignaturePath -PathType Leaf)) {
    throw "signature.html fehlt im entpackten Bereitstellungspaket."
}

$SignatureHtml = [System.IO.File]::ReadAllText(
    $SignaturePath,
    [System.Text.UTF8Encoding]::new($false)
)
$ActualSignatureSha256 = (
    Get-FileHash -LiteralPath $SignaturePath -Algorithm SHA256
).Hash.ToLowerInvariant()

if ($ActualSignatureSha256 -ne $SignatureSha256) {
    throw "Die SHA-256-Pruefung von signature.html ist fehlgeschlagen."
}

Write-Host "RailTime Exchange-Online-Fallback" -ForegroundColor Cyan
Write-Host "Tenant-ID: $ExpectedTenantId"
Write-Host "Manifest SHA-256: $ManifestSha256"
Write-Host "Signatur SHA-256: $SignatureSha256"
Write-Host "Regel: $RuleName"
Write-Host "Die Regel gilt nur fuer interne Absender an externe Empfaenger."
Write-Host "Fallback bei nicht veraenderbaren Nachrichten: Ignore (Nachricht wird unveraendert zugestellt)."

if (-not $ApplyChanges) {
    Write-Warning 'DRY-RUN: Keine Tenant-Verbindung und keine Aenderung. README.txt pruefen und $ApplyChanges bewusst auf $true setzen.'
    return
}

if (-not (Get-Module -ListAvailable -Name ExchangeOnlineManagement)) {
    throw 'Das PowerShell-Modul ExchangeOnlineManagement ist nicht installiert.'
}

Import-Module ExchangeOnlineManagement
Connect-ExchangeOnline -ShowBanner:$false

$RuleParameters = @{
    FromScope = 'InOrganization'
    SentToScope = 'NotInOrganization'
    ApplyHtmlDisclaimerText = $SignatureHtml
    ApplyHtmlDisclaimerLocation = 'Append'
    ApplyHtmlDisclaimerFallbackAction = 'Ignore'
    ExceptIfSubjectOrBodyMatchesWords = @($Marker)
    Enabled = $EnableRule
    Comments = "RailTime vorbereitet; Manifest $ManifestSha256; Signatur $SignatureSha256"
}

$ExistingRule = Get-TransportRule -Identity $RuleName -ErrorAction SilentlyContinue
if ($null -eq $ExistingRule) {
    New-TransportRule -Name $RuleName @RuleParameters
    Write-Host "Regel wurde erstellt. Aktiv: $EnableRule" -ForegroundColor Green
} else {
    Set-TransportRule -Identity $RuleName @RuleParameters
    Write-Host "Regel wurde aktualisiert. Aktiv: $EnableRule" -ForegroundColor Green
}

Write-Warning 'Vor Aktivierung zuerst mit internen und externen Testkonten sowie verschluesselten Nachrichten pruefen.'
Disconnect-ExchangeOnline -Confirm:$false
POWERSHELL;

        return $this->windowsLines(str_replace(
            ['__TENANT_ID__', '__MARKER__', '__MANIFEST_HASH__', '__SIGNATURE_HASH__'],
            [$tenantId, $marker, $manifestHash, $signatureHash],
            $script,
        ));
    }

    private function readme(
        string $generatedAt,
        string $manifestHash,
        string $signatureHash,
        ?User $user,
    ): string {
        $metadata = $this->manifest->metadata();
        $owner = $user instanceof User
            ? 'Signaturquelle wurde aus der veroeffentlichten Vorlage mit vollstaendigen Exchange-Absenderplatzhaltern erzeugt; Paket angefordert durch Benutzer-ID '.(int) $user->getKey().'.'
            : 'Signaturquelle wurde durch einen explizit injizierten Renderer erzeugt.';

        $readme = <<<TEXT
RailTime Outlook-Bereitstellung
===============================

Erzeugt: {$generatedAt}
Add-in-ID: {$metadata['addin_id']}
Tenant-ID: {$metadata['tenant_id']}
Client-ID: {$metadata['client_id']}
Basisadresse: {$metadata['base_url']}
Manifest SHA-256: {$manifestHash}
Signatur SHA-256: {$signatureHash}

Dateien
-------
manifest.xml
    Add-in-only Manifest fuer die zentrale Bereitstellung im Microsoft 365 Admin Center.

signature.html
    Gepruefte RailTime-Signatur mit Exchange-Absenderplatzhaltern und Deduplizierungsmarker.

ExchangeFallback.ps1
    Optionaler Exchange-Online-Transportregel-Fallback. Das Skript verbindet sich in der
    ausgelieferten Einstellung nicht mit Microsoft 365 und veraendert keinen Tenant.

meta.json
    Maschinenlesbare Versionen, Konfiguration und SHA-256-Pruefsummen.

Sicherer Ablauf
---------------
1. ZIP vollstaendig entpacken und die SHA-256-Werte pruefen.
2. manifest.xml im Microsoft 365 Admin Center als integrierte App pruefen und bereitstellen.
3. Zuerst eine kleine Pilotgruppe verwenden. Ein Download beweist keine erfolgreiche Tenant-Bereitstellung.
4. signature.html in Browser, Outlook Windows, Outlook Web, Outlook Mac und Outlook Mobile pruefen.
5. Den Exchange-Fallback nur verwenden, wenn er wirklich benoetigt wird.
6. Fuer den Fallback ExchangeFallback.ps1 lesen. Erst danach \$ApplyChanges bewusst auf \$true setzen.
7. Die Transportregel wird selbst dann standardmaessig deaktiviert erstellt/aktualisiert
   (\$EnableRule = \$false). Erst nach Tests manuell aktivieren.

Exchange-Fallback
-----------------
Der Fallback gilt ausschliesslich fuer interne Absender an externe Empfaenger. Nachrichten,
in die Exchange keinen Disclaimer einsetzen kann, werden unveraendert zugestellt (Ignore),
nicht blockiert oder als neue Nachricht verpackt. Der Marker {$metadata['marker']} verhindert
eine zweite Signatur, wenn das Outlook-Add-in bereits eine eingefuegt hat.

Wichtige Einschraenkung: Eine Exchange-Transportregel ist beim Verfassen nicht sichtbar und
kann die Signatur am Ende einer gesamten Antwortkette anhaengen. Sie ist nur ein Ausfallschutz,
nicht der primaere Benutzerweg.

{$owner}

Dieses Paket enthaelt weder Client-Secret noch Kennwort noch Zugriffstoken. Seine Erzeugung hat
keine Microsoft-365-, Exchange- oder Entra-Konfiguration veraendert.
TEXT;

        return $this->windowsLines($readme);
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    private function meta(string $generatedAt, ?User $user, array $files): array
    {
        $fileMeta = [];
        foreach ($files as $name => $content) {
            $fileMeta[$name] = [
                'bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
            ];
        }

        return [
            'schema' => 1,
            'generated_at' => $generatedAt,
            'generated_for_user_id' => $user?->getKey(),
            'tenant_mutated' => false,
            'apply_changes_default' => false,
            'enable_rule_default' => false,
            'manifest' => $this->manifest->metadata(),
            'files' => $fileMeta,
        ];
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'railtime-outlook-');
        if (! is_string($path)) {
            throw new RuntimeException('Das temporaere Outlook-Paket konnte nicht angelegt werden.');
        }

        $zip = new ZipArchive;
        $opened = false;
        $closed = false;

        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Das Outlook-Bereitstellungsarchiv konnte nicht geoeffnet werden.');
            }
            $opened = true;

            foreach ($files as $name => $content) {
                if (! $zip->addFromString($name, $content)) {
                    throw new RuntimeException("Die Paketdatei {$name} konnte nicht geschrieben werden.");
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Das Outlook-Bereitstellungsarchiv konnte nicht abgeschlossen werden.');
            }
            $closed = true;

            $content = file_get_contents($path);
            if (! is_string($content)) {
                throw new RuntimeException('Das Outlook-Bereitstellungsarchiv konnte nicht gelesen werden.');
            }

            return $content;
        } finally {
            if ($opened && ! $closed) {
                $zip->close();
            }

            @unlink($path);
        }
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Die Metadaten des Outlook-Pakets konnten nicht serialisiert werden.', 0, $exception);
        }
    }

    private function powershellLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function windowsLines(string $value): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", trim($value))."\r\n";
    }
}

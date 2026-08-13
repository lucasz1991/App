[CmdletBinding()]
param(
    [string] $SignatureName = '__RAILTIME_SIGNATURE_NAME__',
    [string] $SourceDirectory = '',
    [string] $TargetDirectory = '',
    [switch] $TestMode,
    [switch] $NoGui,
    [string] $AccountFixturePath = '',
    [string] $ResultPath = ''
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$script:InstallerTitle = 'RailTime Outlook-Einrichtung'
$script:InstallerVersion = '3.0'
$script:AccountContainerName = '9375CFF0413111d3B88A00104B2A6676'
$script:LogPath = ''
$script:Mutex = $null
$script:MutexAcquired = $false
$script:LastExitCode = 0

function New-InstallerException {
    param(
        [int] $ExitCode,
        [string] $Message
    )

    $exception = New-Object System.InvalidOperationException($Message)
    $exception.Data['RailTimeExitCode'] = $ExitCode

    return $exception
}

function Throw-InstallerError {
    param(
        [int] $ExitCode,
        [string] $Message
    )

    throw (New-InstallerException -ExitCode $ExitCode -Message $Message)
}

function Get-InstallerExitCode {
    param([System.Exception] $Exception)

    if ($null -ne $Exception -and $Exception.Data.Contains('RailTimeExitCode')) {
        return [int] $Exception.Data['RailTimeExitCode']
    }

    return 1
}

function Initialize-InstallerLog {
    $tempRoot = $env:TEMP
    if ([string]::IsNullOrWhiteSpace($tempRoot)) {
        $tempRoot = [System.IO.Path]::GetTempPath()
    }

    if (-not (Test-Path -LiteralPath $tempRoot -PathType Container)) {
        [void] (New-Item -ItemType Directory -Path $tempRoot -Force)
    }

    $script:LogPath = Join-Path $tempRoot 'RailTime-Outlook-Signatur-Installation.log'
    $header = '[START] {0} RailTime Outlook-Einrichtung' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    [System.IO.File]::WriteAllText($script:LogPath, $header + [Environment]::NewLine, (New-Object System.Text.UTF8Encoding($true)))

    Write-InstallerLog -Level INFO -Message ('Installer-Version: {0}' -f $script:InstallerVersion)
    Write-InstallerLog -Level INFO -Message ('Windows PowerShell: {0}' -f $PSVersionTable.PSVersion)
    Write-InstallerLog -Level INFO -Message ('Betriebssystem: {0}' -f [Environment]::OSVersion.VersionString)
    Write-InstallerLog -Level INFO -Message ('Prozessarchitektur: {0}-Bit' -f ([IntPtr]::Size * 8))
    if ($TestMode) {
        Write-InstallerLog -Level INFO -Message 'Sicherer Testmodus aktiv: Prozesse und Registry bleiben unberührt.'
    }
}

function Write-InstallerLog {
    param(
        [ValidateSet('INFO', 'ERFOLG', 'FEHLER', 'WARNUNG')]
        [string] $Level,
        [string] $Message
    )

    if ([string]::IsNullOrWhiteSpace($script:LogPath)) {
        return
    }

    $line = '[{0}] {1} {2}' -f $Level, (Get-Date -Format 'HH:mm:ss'), $Message
    [System.IO.File]::AppendAllText($script:LogPath, $line + [Environment]::NewLine, (New-Object System.Text.UTF8Encoding($true)))
}

function Write-InstallerStatus {
    param(
        [ValidateSet('INFO', 'ERFOLG', 'FEHLER', 'WARNUNG')]
        [string] $Level,
        [string] $Message
    )

    Write-InstallerLog -Level $Level -Message $Message
    Write-Host ('[{0}] {1}' -f $Level, $Message)
}

function Get-FullPath {
    param([string] $Path)

    return [System.IO.Path]::GetFullPath($Path)
}

function Get-PayloadRelativePaths {
    param([string] $Name)

    $assetFolder = $Name + '_files'
    $paths = @()
    $paths += ($Name + '.htm')
    $paths += ($Name + '.rtf')
    $paths += ($Name + '.txt')
    $paths += (Join-Path $assetFolder 'zug-dampf.gif')
    $paths += (Join-Path $assetFolder 'logo.gif')
    $paths += (Join-Path $assetFolder 'contact-location.png')
    $paths += (Join-Path $assetFolder 'contact-phone.png')
    $paths += (Join-Path $assetFolder 'contact-mobile.png')
    $paths += (Join-Path $assetFolder 'contact-email.png')
    $paths += (Join-Path $assetFolder 'contact-web.png')

    return $paths
}

function Convert-ToPortablePath {
    param([string] $Path)

    return ($Path -replace '\\', '/')
}

function Get-PackageRelativePaths {
    param([string] $Name)

    $paths = @()
    foreach ($payloadPath in (Get-PayloadRelativePaths -Name $Name)) {
        $paths += Convert-ToPortablePath -Path $payloadPath
    }
    $paths += 'README-Outlook.html'
    $paths += 'Outlook-klassisch-installieren.cmd'
    $paths += 'RailTime-Outlook-Installer.ps1'

    return $paths
}

function Get-ShortHash {
    param([string] $Hash)

    if ([string]::IsNullOrWhiteSpace($Hash) -or $Hash.Length -le 16) {
        return $Hash
    }

    return $Hash.Substring(0, 16)
}

function Get-TextSha256 {
    param([string] $Text)

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($Text)
        return ([BitConverter]::ToString($sha256.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha256.Dispose()
    }
}

function Test-Package {
    param(
        [string] $Name,
        [string] $Root
    )

    if ($Name -notmatch '^RailTime-Signatur-(hell|dunkel)-[a-z0-9]+(?:-[a-z0-9]+)*$') {
        Throw-InstallerError -ExitCode 11 -Message 'Der Signaturname im Installationspaket ist ungültig.'
    }

    if (-not (Test-Path -LiteralPath $Root -PathType Container)) {
        Throw-InstallerError -ExitCode 11 -Message 'Der entpackte Paketordner wurde nicht gefunden.'
    }

    $manifestPath = Join-Path $Root 'RailTime-Paketmanifest.json'
    if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        Throw-InstallerError -ExitCode 11 -Message 'Das ZIP wurde nicht vollständig entpackt. Es fehlt: RailTime-Paketmanifest.json'
    }

    try {
        $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Throw-InstallerError -ExitCode 11 -Message 'Das Paketmanifest ist nicht lesbar. Bitte das ZIP erneut herunterladen und vollständig entpacken.'
    }

    if ([int] $manifest.schema -ne 1 -or [string] $manifest.signatureName -cne $Name) {
        Throw-InstallerError -ExitCode 11 -Message 'Das Paketmanifest gehört nicht zu dieser RailTime-Signatur.'
    }

    $entries = @($manifest.files)
    $expectedPaths = @(Get-PackageRelativePaths -Name $Name)
    if ($entries.Count -ne $expectedPaths.Count) {
        Throw-InstallerError -ExitCode 11 -Message 'Das Paketmanifest enthält nicht die erwartete Anzahl an Dateien.'
    }

    $verifiedFiles = @()
    foreach ($relativePath in $expectedPaths) {
        $entry = $entries | Where-Object { [string] $_.path -ceq $relativePath } | Select-Object -First 1
        if ($null -eq $entry) {
            Throw-InstallerError -ExitCode 11 -Message ('Im Paketmanifest fehlt: {0}' -f $relativePath)
        }

        $nativeRelativePath = $relativePath -replace '/', [System.IO.Path]::DirectorySeparatorChar
        $sourcePath = Join-Path $Root $nativeRelativePath
        if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
            Throw-InstallerError -ExitCode 11 -Message ('Das ZIP wurde nicht vollständig entpackt. Es fehlt: {0}' -f $relativePath)
        }

        $sourceItem = Get-Item -LiteralPath $sourcePath
        if ($sourceItem.Length -le 0) {
            Throw-InstallerError -ExitCode 11 -Message ('Eine Installationsdatei ist leer: {0}' -f $relativePath)
        }

        $actualHash = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
        $expectedHash = ([string] $entry.sha256).ToLowerInvariant()
        if ($sourceItem.Length -ne [long] $entry.bytes -or $actualHash -cne $expectedHash) {
            Throw-InstallerError -ExitCode 11 -Message ('Die Paketprüfung ist fehlgeschlagen: {0}. Bitte das ZIP erneut herunterladen.' -f $relativePath)
        }

        $verifiedFiles += [pscustomobject] @{
            Path = $relativePath
            Bytes = [long] $sourceItem.Length
            Sha256 = $actualHash
        }
    }

    $fingerprintMaterial = @()
    foreach ($manifestEntry in $entries) {
        $verifiedEntry = $verifiedFiles | Where-Object { [string] $_.Path -ceq [string] $manifestEntry.path } | Select-Object -First 1
        $fingerprintMaterial += '{0}:{1}' -f $verifiedEntry.Path, $verifiedEntry.Sha256
    }
    $computedFingerprint = Get-TextSha256 -Text ($fingerprintMaterial -join "`n")
    $fingerprint = ([string] $manifest.packageFingerprint).ToLowerInvariant()
    if ($fingerprint -notmatch '^[a-f0-9]{64}$' -or $fingerprint -cne $computedFingerprint) {
        Throw-InstallerError -ExitCode 11 -Message 'Der Paketfingerabdruck im Manifest ist ungültig.'
    }

    Write-InstallerStatus -Level INFO -Message ('Paketmanifest geprüft: {0} Dateien, SHA-256 {1}…' -f $verifiedFiles.Count, (Get-ShortHash -Hash $fingerprint))

    return [pscustomobject] @{
        Fingerprint = $fingerprint
        Files = $verifiedFiles
        ManifestPath = $manifestPath
    }
}

function Convert-RegistryAccountName {
    param([object] $Value)

    if ($null -eq $Value) {
        return ''
    }

    if ($Value -is [byte[]]) {
        $candidates = @(
            [System.Text.Encoding]::Unicode.GetString($Value),
            [System.Text.Encoding]::UTF8.GetString($Value),
            [System.Text.Encoding]::ASCII.GetString($Value)
        )

        foreach ($candidate in $candidates) {
            $clean = ([string] $candidate).Trim([char] 0).Trim()
            $match = [regex]::Match($clean, '(?i)[A-Z0-9._%+\-]+@[A-Z0-9.\-]+')
            if ($match.Success) {
                return $match.Value.Trim([char] 0)
            }
        }

        return ''
    }

    $text = ([string] $Value).Trim([char] 0).Trim()
    $textMatch = [regex]::Match($text, '(?i)[A-Z0-9._%+\-]+@[A-Z0-9.\-]+')
    if ($textMatch.Success) {
        return $textMatch.Value.Trim([char] 0)
    }

    return $text
}

function Get-DefaultOutlookProfileName {
    $outlookPath = 'HKCU:\Software\Microsoft\Office\16.0\Outlook'
    $profileName = ''

    if (Test-Path -LiteralPath $outlookPath) {
        $outlookProperties = Get-ItemProperty -LiteralPath $outlookPath -ErrorAction SilentlyContinue
        if ($null -ne $outlookProperties) {
            $defaultProfileProperty = $outlookProperties.PSObject.Properties['DefaultProfile']
            if ($null -ne $defaultProfileProperty) {
                $profileName = [string] $defaultProfileProperty.Value
            }
        }
    }

    if ([string]::IsNullOrWhiteSpace($profileName)) {
        $messagingPath = 'HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Windows Messaging Subsystem\Profiles'
        if (Test-Path -LiteralPath $messagingPath) {
            $messagingProperties = Get-ItemProperty -LiteralPath $messagingPath -ErrorAction SilentlyContinue
            if ($null -ne $messagingProperties) {
                $messagingProfileProperty = $messagingProperties.PSObject.Properties['DefaultProfile']
                if ($null -ne $messagingProfileProperty) {
                    $profileName = [string] $messagingProfileProperty.Value
                }
            }
        }
    }

    if ([string]::IsNullOrWhiteSpace($profileName)) {
        $profilesRoot = 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Profiles'
        if (Test-Path -LiteralPath $profilesRoot) {
            $firstProfile = Get-ChildItem -LiteralPath $profilesRoot -ErrorAction SilentlyContinue | Sort-Object PSChildName | Select-Object -First 1
            if ($null -ne $firstProfile) {
                $profileName = [string] $firstProfile.PSChildName
            }
        }
    }

    return $profileName.Trim()
}

function Get-FixtureAccounts {
    param([string] $FixturePath)

    if ([string]::IsNullOrWhiteSpace($FixturePath) -or -not (Test-Path -LiteralPath $FixturePath -PathType Leaf)) {
        Throw-InstallerError -ExitCode 10 -Message 'Für den sicheren Testmodus fehlt die Account-Fixture.'
    }

    $fixture = Get-Content -LiteralPath $FixturePath -Raw -Encoding UTF8 | ConvertFrom-Json
    $defaultProfile = [string] $fixture.DefaultProfile
    $profiles = @($fixture.Profiles)
    $profile = $profiles | Where-Object { [string] $_.Name -ieq $defaultProfile } | Select-Object -First 1

    if ($null -eq $profile) {
        Throw-InstallerError -ExitCode 12 -Message 'In der Test-Fixture wurde das Standardprofil nicht gefunden.'
    }

    $accounts = @($profile.Accounts) | Sort-Object @{ Expression = {
        $key = [string] $_.Key
        $numeric = 0
        if ([int]::TryParse($key, [ref] $numeric)) { return $numeric }
        return [int]::MaxValue
    } }, @{ Expression = { [string] $_.Key } }

    $foundAccounts = @()
    foreach ($account in $accounts) {
        $email = ([string] $account.Email).Trim()
        if ($email -match '^[^@\s]+@rail-time\.de$') {
            $foundAccounts += [pscustomobject] @{
                ProfileName = [string] $profile.Name
                AccountKeyName = [string] $account.Key
                AccountKeyPath = 'fixture://{0}/{1}' -f $profile.Name, $account.Key
                Email = $email
                IsFixture = $true
            }
        }
    }

    return @($foundAccounts)
}

function Get-ClassicOutlookAccounts {
    param([string] $FixturePath)

    if ($TestMode) {
        return @(Get-FixtureAccounts -FixturePath $FixturePath)
    }

    $profileName = Get-DefaultOutlookProfileName
    if ([string]::IsNullOrWhiteSpace($profileName)) {
        return $null
    }

    $profilesRoot = 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Profiles'
    $accountRoot = Join-Path (Join-Path $profilesRoot $profileName) $script:AccountContainerName
    if (-not (Test-Path -LiteralPath $accountRoot -PathType Container)) {
        return $null
    }

    $accountKeys = Get-ChildItem -LiteralPath $accountRoot -ErrorAction SilentlyContinue | Sort-Object @{ Expression = {
        $numeric = 0
        if ([int]::TryParse($_.PSChildName, [ref] $numeric)) { return $numeric }
        return [int]::MaxValue
    } }, PSChildName

    $foundAccounts = @()
    foreach ($accountKey in $accountKeys) {
        $rawName = $accountKey.GetValue('Account Name', $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
        $email = Convert-RegistryAccountName -Value $rawName

        if ($email -match '^[^@\s]+@rail-time\.de$') {
            $foundAccounts += [pscustomobject] @{
                ProfileName = $profileName
                AccountKeyName = [string] $accountKey.PSChildName
                AccountKeyPath = [string] $accountKey.PSPath
                Email = $email
                IsFixture = $false
            }
        }
    }

    return @($foundAccounts)
}

function Test-ClassicOutlookSignaturePolicy {
    if ($TestMode) {
        return
    }

    $policyPath = 'HKCU:\Software\Policies\Microsoft\Office\16.0\Outlook\Setup'
    if (-not (Test-Path -LiteralPath $policyPath -PathType Container)) {
        return
    }

    $policyProperties = Get-ItemProperty -LiteralPath $policyPath -ErrorAction SilentlyContinue
    if ($null -eq $policyProperties) {
        return
    }

    $policyProperty = $policyProperties.PSObject.Properties['DisableRoamingSignatures']
    if ($null -ne $policyProperty -and [int] $policyProperty.Value -ne 1) {
        Throw-InstallerError -ExitCode 15 -Message 'Eine verwaltete Outlook-Richtlinie verhindert den lokalen Classic-Signaturmodus. Bitte die IT-Administration kontaktieren.'
    }
}

function Get-InstallationContext {
    $package = Test-Package -Name $SignatureName -Root $SourceDirectory

    $accounts = @(Get-ClassicOutlookAccounts -FixturePath $AccountFixturePath)
    if ($accounts.Count -eq 0) {
        Throw-InstallerError -ExitCode 12 -Message 'Im aktiven Classic-Outlook-Profil wurde kein Konto mit der Domain @rail-time.de gefunden. Outlook-Einstellungen und Signaturdateien wurden nicht geändert.'
    }
    $account = $accounts[0]

    Test-ClassicOutlookSignaturePolicy

    $resolvedTarget = $TargetDirectory
    if ([string]::IsNullOrWhiteSpace($resolvedTarget)) {
        if ([string]::IsNullOrWhiteSpace($env:APPDATA)) {
            Throw-InstallerError -ExitCode 10 -Message 'APPDATA ist nicht verfügbar. Die Installation wurde abgebrochen.'
        }
        $resolvedTarget = Join-Path $env:APPDATA 'Microsoft\Signatures'
    }

    $resolvedTarget = Get-FullPath -Path $resolvedTarget
    Write-InstallerStatus -Level INFO -Message ('Classic-Profil: {0}' -f $account.ProfileName)
    Write-InstallerStatus -Level INFO -Message ('RailTime-Konten erkannt: {0}' -f $accounts.Count)
    foreach ($detectedAccount in $accounts) {
        Write-InstallerStatus -Level INFO -Message ('Konto: {0} (Schlüssel {1})' -f $detectedAccount.Email, $detectedAccount.AccountKeyName)
    }
    Write-InstallerStatus -Level INFO -Message ('Ausgewähltes Konto: {0}' -f $account.Email)
    Write-InstallerStatus -Level INFO -Message ('Installationsziel: {0}' -f $resolvedTarget)

    return [pscustomobject] @{
        Account = $account
        Accounts = $accounts
        Package = $package
        SourceDirectory = $SourceDirectory
        TargetDirectory = $resolvedTarget
        RelativePaths = @(Get-PayloadRelativePaths -Name $SignatureName)
    }
}

function Get-OutlookProcesses {
    return @(Get-Process -Name 'OUTLOOK' -ErrorAction SilentlyContinue | Sort-Object Id -Unique)
}

function Get-NewOutlookProcesses {
    return @(Get-Process -Name 'olk' -ErrorAction SilentlyContinue | Sort-Object Id -Unique)
}

function Invoke-UiPump {
    if (-not $NoGui -and -not $TestMode) {
        [System.Windows.Forms.Application]::DoEvents()
    }
}

function Close-OutlookApplications {
    param([scriptblock] $ConfirmForceClose)

    if ($TestMode) {
        Write-InstallerLog -Level INFO -Message 'Testmodus: Outlook-Prozesse werden nicht berührt.'
        return
    }

    $newOutlookProcesses = @(Get-NewOutlookProcesses)
    if ($newOutlookProcesses.Count -gt 0) {
        Write-InstallerStatus -Level INFO -Message ('Neues Outlook erkannt ({0} Prozess(e)); es bleibt geöffnet und wird nicht verändert.' -f $newOutlookProcesses.Count)
    } else {
        Write-InstallerLog -Level INFO -Message 'Neues Outlook läuft nicht. Sein kontogebundener Signaturspeicher wird nicht verändert.'
    }

    $running = @(Get-OutlookProcesses)
    if ($running.Count -eq 0) {
        Write-InstallerLog -Level INFO -Message 'Outlook war bereits geschlossen.'
        return
    }

    Write-InstallerStatus -Level INFO -Message ('Classic Outlook wird regulär geschlossen ({0} Prozess(e)).' -f $running.Count)

    if ($running.Count -gt 0) {
        $outlookApplication = $null
        try {
            $outlookApplication = [System.Runtime.InteropServices.Marshal]::GetActiveObject('Outlook.Application')
            if ($null -ne $outlookApplication) {
                $outlookApplication.Quit()
            }
        } catch {
            Write-InstallerLog -Level WARNUNG -Message 'Classic Outlook konnte nicht über COM beendet werden; das Hauptfenster wird geschlossen.'
        } finally {
            if ($null -ne $outlookApplication) {
                try {
                    [void] [System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($outlookApplication)
                } catch {
                    Write-InstallerLog -Level WARNUNG -Message 'Die Outlook-COM-Verbindung konnte nicht sofort freigegeben werden.'
                }
            }
        }
    }

    foreach ($process in $running) {
        try {
            [void] $process.CloseMainWindow()
        } catch {
            Write-InstallerLog -Level WARNUNG -Message ('Prozess {0} konnte nicht regulär angesprochen werden.' -f $process.ProcessName)
        }
    }

    $deadline = (Get-Date).AddSeconds(12)
    do {
        Start-Sleep -Milliseconds 250
        Invoke-UiPump
        $remaining = @(Get-OutlookProcesses)
    } while ($remaining.Count -gt 0 -and (Get-Date) -lt $deadline)

    if ($remaining.Count -eq 0) {
        Write-InstallerLog -Level INFO -Message 'Outlook wurde regulär geschlossen.'
        return
    }

    $allowForce = $false
    if ($null -ne $ConfirmForceClose) {
        $allowForce = [bool] (& $ConfirmForceClose $remaining)
    }

    if (-not $allowForce) {
        Throw-InstallerError -ExitCode 13 -Message 'Outlook ist noch geöffnet. Das erzwungene Schließen wurde nicht bestätigt; es wurde nichts installiert.'
    }

    foreach ($process in $remaining) {
        Stop-Process -Id $process.Id -Force -ErrorAction Stop
    }

    $forceDeadline = (Get-Date).AddSeconds(5)
    do {
        Start-Sleep -Milliseconds 200
        Invoke-UiPump
        $remaining = @(Get-OutlookProcesses)
    } while ($remaining.Count -gt 0 -and (Get-Date) -lt $forceDeadline)

    if ($remaining.Count -gt 0) {
        Throw-InstallerError -ExitCode 14 -Message 'Outlook konnte auch nach Bestätigung nicht vollständig geschlossen werden.'
    }

    Write-InstallerLog -Level WARNUNG -Message 'Outlook wurde nach ausdrücklicher Bestätigung erzwungen beendet.'
}

function Confirm-SelectedAccount {
    param([pscustomobject] $Account)

    if ($TestMode) {
        return
    }

    if (-not (Test-Path -LiteralPath $Account.AccountKeyPath)) {
        Throw-InstallerError -ExitCode 16 -Message 'Das zuvor ausgewählte RailTime-Konto ist nach dem Schließen von Outlook nicht mehr verfügbar. Es wurde nichts installiert.'
    }

    $accountKey = Get-Item -LiteralPath $Account.AccountKeyPath -ErrorAction Stop
    $currentEmail = Convert-RegistryAccountName -Value $accountKey.GetValue('Account Name', $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
    if ($currentEmail -notmatch '^[^@\s]+@rail-time\.de$' -or $currentEmail -ine $Account.Email) {
        Throw-InstallerError -ExitCode 16 -Message 'Das ausgewählte Outlook-Konto hat sich während der Einrichtung geändert. Es wurde nichts installiert.'
    }
}

function Copy-AndVerifyPayload {
    param([pscustomobject] $Context)

    if (-not (Test-Path -LiteralPath $Context.TargetDirectory -PathType Container)) {
        [void] (New-Item -ItemType Directory -Path $Context.TargetDirectory -Force)
    }

    $verifiedCopies = @()
    $fileIndex = 0
    foreach ($relativePath in $Context.RelativePaths) {
        $fileIndex++
        $sourcePath = Join-Path $Context.SourceDirectory $relativePath
        $destinationPath = Join-Path $Context.TargetDirectory $relativePath
        $destinationParent = Split-Path -Parent $destinationPath

        if (-not (Test-Path -LiteralPath $destinationParent -PathType Container)) {
            [void] (New-Item -ItemType Directory -Path $destinationParent -Force)
        }

        Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force

        $sourceHash = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
        $destinationHash = (Get-FileHash -LiteralPath $destinationPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $portablePath = Convert-ToPortablePath -Path $relativePath
        $manifestEntry = $Context.Package.Files | Where-Object { [string] $_.Path -ceq $portablePath } | Select-Object -First 1
        if ($null -eq $manifestEntry -or $sourceHash -cne $destinationHash -or $destinationHash -cne [string] $manifestEntry.Sha256) {
            Throw-InstallerError -ExitCode 21 -Message ('Die kopierte Datei konnte nicht verifiziert werden: {0}' -f $relativePath)
        }

        $bytes = (Get-Item -LiteralPath $destinationPath).Length
        $verifiedCopies += [pscustomobject] @{
            Path = $portablePath
            Bytes = [long] $bytes
            Sha256 = $destinationHash
        }
        Write-InstallerLog -Level INFO -Message ('Datei {0}/{1}: {2} | {3} Byte | SHA-256 {4}' -f $fileIndex, $Context.RelativePaths.Count, $portablePath, $bytes, $destinationHash)
        Write-Host ('[DATEI {0}/{1}] {2} | {3} Byte | SHA-256 {4}…' -f $fileIndex, $Context.RelativePaths.Count, $portablePath, $bytes, (Get-ShortHash -Hash $destinationHash))
    }

    Write-InstallerStatus -Level INFO -Message ('{0} Signaturdateien wurden kopiert und per SHA-256 verifiziert.' -f $Context.RelativePaths.Count)

    return @($verifiedCopies)
}

function Get-RegistryValueState {
    param(
        [Microsoft.Win32.RegistryKey] $Key,
        [string] $Name
    )

    $valueNames = @($Key.GetValueNames())
    $exists = @($valueNames | Where-Object { $_ -ceq $Name }).Count -gt 0
    if (-not $exists) {
        return [pscustomobject] @{ Exists = $false; Kind = $null; Value = $null }
    }

    return [pscustomobject] @{
        Exists = $true
        Kind = $Key.GetValueKind($Name)
        Value = $Key.GetValue($Name, $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
    }
}

function Restore-RegistryValueState {
    param(
        [Microsoft.Win32.RegistryKey] $Key,
        [string] $Name,
        [pscustomobject] $State
    )

    if ($State.Exists) {
        $Key.SetValue($Name, $State.Value, $State.Kind)
    } else {
        $Key.DeleteValue($Name, $false)
    }
}

function Convert-RegistryStateForBackup {
    param([pscustomobject] $State)

    if (-not $State.Exists) {
        return [pscustomobject] @{ Exists = $false; Kind = ''; Value = '' }
    }

    $value = $State.Value
    if ($value -is [byte[]]) {
        $value = [Convert]::ToBase64String($value)
    }

    return [pscustomobject] @{
        Exists = $true
        Kind = [string] $State.Kind
        Value = [string] $value
    }
}

function Save-RegistryBackup {
    param(
        [pscustomobject] $Context,
        [pscustomobject] $NewSignatureState,
        [pscustomobject] $ReplySignatureState,
        [pscustomobject] $RoamingState
    )

    if ($TestMode) {
        return ''
    }

    $localRoot = $env:LOCALAPPDATA
    if ([string]::IsNullOrWhiteSpace($localRoot)) {
        $localRoot = $env:APPDATA
    }

    $backupRoot = Join-Path $localRoot 'RailTime\Outlook-Installer\Backups'
    [void] (New-Item -ItemType Directory -Path $backupRoot -Force)
    $backupPath = Join-Path $backupRoot ('Signaturwerte-{0}-{1}.json' -f (Get-Date -Format 'yyyyMMdd-HHmmss-fff'), $PID)
    $backup = [pscustomobject] @{
        CreatedAt = (Get-Date).ToString('o')
        Profile = $Context.Account.ProfileName
        AccountKey = $Context.Account.AccountKeyName
        Email = $Context.Account.Email
        NewSignature = Convert-RegistryStateForBackup -State $NewSignatureState
        ReplyForwardSignature = Convert-RegistryStateForBackup -State $ReplySignatureState
        DisableRoamingSignatures = Convert-RegistryStateForBackup -State $RoamingState
    }

    $backup | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $backupPath -Encoding UTF8
    Write-InstallerLog -Level INFO -Message ('Registry-Sicherung angelegt: {0}' -f $backupPath)

    return $backupPath
}

function Set-AndVerifyAccountDefaults {
    param([pscustomobject] $Context)

    if ($TestMode) {
        return [pscustomobject] @{
            BackupPath = ''
            NewSignature = $SignatureName
            ReplyForwardSignature = $SignatureName
            LocalSignatureMode = $true
        }
    }

    $accountKey = Get-Item -LiteralPath $Context.Account.AccountKeyPath -ErrorAction Stop
    $setupPath = 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Setup'
    if (-not (Test-Path -LiteralPath $setupPath -PathType Container)) {
        [void] (New-Item -Path $setupPath -Force)
    }
    $setupKey = Get-Item -LiteralPath $setupPath -ErrorAction Stop

    $newState = Get-RegistryValueState -Key $accountKey -Name 'New Signature'
    $replyState = Get-RegistryValueState -Key $accountKey -Name 'Reply-Forward Signature'
    $roamingState = Get-RegistryValueState -Key $setupKey -Name 'DisableRoamingSignatures'
    $backupPath = Save-RegistryBackup -Context $Context -NewSignatureState $newState -ReplySignatureState $replyState -RoamingState $roamingState

    try {
        $setupKey.SetValue('DisableRoamingSignatures', 1, [Microsoft.Win32.RegistryValueKind]::DWord)
        $accountKey.SetValue('New Signature', $SignatureName, [Microsoft.Win32.RegistryValueKind]::String)
        $accountKey.SetValue('Reply-Forward Signature', $SignatureName, [Microsoft.Win32.RegistryValueKind]::String)

        $actualNew = [string] $accountKey.GetValue('New Signature', '')
        $actualReply = [string] $accountKey.GetValue('Reply-Forward Signature', '')
        $actualRoaming = [int] $setupKey.GetValue('DisableRoamingSignatures', 0)

        if ($actualNew -cne $SignatureName -or $actualReply -cne $SignatureName -or $actualRoaming -ne 1) {
            Throw-InstallerError -ExitCode 31 -Message 'Die Outlook-Kontozuordnung konnte nicht verifiziert werden.'
        }
    } catch {
        Restore-RegistryValueState -Key $accountKey -Name 'New Signature' -State $newState
        Restore-RegistryValueState -Key $accountKey -Name 'Reply-Forward Signature' -State $replyState
        Restore-RegistryValueState -Key $setupKey -Name 'DisableRoamingSignatures' -State $roamingState
        throw
    }

    Write-InstallerLog -Level INFO -Message 'Neue Nachrichten sowie Antworten/Weiterleitungen wurden dem ersten RailTime-Konto zugeordnet.'
    Write-InstallerLog -Level INFO -Message 'Der von Microsoft dokumentierte lokale Classic-Outlook-Signaturmodus wurde aktiviert.'

    return [pscustomobject] @{
        BackupPath = $backupPath
        NewSignature = $SignatureName
        ReplyForwardSignature = $SignatureName
        LocalSignatureMode = $true
    }
}

function Invoke-RailTimeInstallation {
    param(
        [pscustomobject] $Context,
        [scriptblock] $ConfirmForceClose,
        [scriptblock] $StatusCallback
    )

    if ($null -ne $StatusCallback) {
        & $StatusCallback 'Classic Outlook wird vorbereitet …' 20 'Das neue Outlook bleibt geöffnet und unverändert.'
    }
    Write-InstallerStatus -Level INFO -Message 'Schritt 1/3: Classic Outlook sicher schließen.'
    Close-OutlookApplications -ConfirmForceClose $ConfirmForceClose
    Confirm-SelectedAccount -Account $Context.Account

    if ($null -ne $StatusCallback) {
        & $StatusCallback 'Signaturdateien werden installiert …' 55 ('Ziel: {0}' -f $Context.TargetDirectory)
    }
    Write-InstallerStatus -Level INFO -Message ('Schritt 2/3: Dateien nach {0} kopieren und prüfen.' -f $Context.TargetDirectory)
    $verifiedFiles = @(Copy-AndVerifyPayload -Context $Context)

    if ($null -ne $StatusCallback) {
        & $StatusCallback 'Classic-Konto wird zugeordnet …' 80 ('Konto: {0}' -f $Context.Account.Email)
    }
    Write-InstallerStatus -Level INFO -Message ('Schritt 3/3: Signatur dem Konto {0} zuordnen.' -f $Context.Account.Email)
    $assignment = Set-AndVerifyAccountDefaults -Context $Context

    if ($null -ne $StatusCallback) {
        & $StatusCallback 'Installation erfolgreich verifiziert.' 100 ('{0} Dateien · SHA-256 {1}…' -f $verifiedFiles.Count, (Get-ShortHash -Hash $Context.Package.Fingerprint))
    }

    $result = [pscustomobject] @{
        Success = $true
        SignatureName = $SignatureName
        AccountEmail = $Context.Account.Email
        ProfileName = $Context.Account.ProfileName
        AccountKey = $Context.Account.AccountKeyName
        DetectedAccounts = @($Context.Accounts | ForEach-Object { $_.Email })
        TargetDirectory = $Context.TargetDirectory
        InstalledFiles = $verifiedFiles.Count
        FileHashes = $verifiedFiles
        PackageFingerprint = $Context.Package.Fingerprint
        NewSignature = $assignment.NewSignature
        ReplyForwardSignature = $assignment.ReplyForwardSignature
        LocalSignatureMode = $assignment.LocalSignatureMode
        BackupPath = $assignment.BackupPath
        LogPath = $script:LogPath
    }

    Write-InstallerStatus -Level ERFOLG -Message ('Signatur {0} wurde für {1} installiert und vollständig verifiziert.' -f $SignatureName, $Context.Account.Email)

    return $result
}

function Write-TestResult {
    param([object] $Value)

    if ([string]::IsNullOrWhiteSpace($ResultPath)) {
        return
    }

    $parent = Split-Path -Parent $ResultPath
    if (-not [string]::IsNullOrWhiteSpace($parent) -and -not (Test-Path -LiteralPath $parent -PathType Container)) {
        [void] (New-Item -ItemType Directory -Path $parent -Force)
    }

    $json = $Value | ConvertTo-Json -Depth 6
    [System.IO.File]::WriteAllText($ResultPath, $json, (New-Object System.Text.UTF8Encoding($false)))
}

function Invoke-TestInstallation {
    try {
        $context = Get-InstallationContext
        $result = Invoke-RailTimeInstallation -Context $context -ConfirmForceClose { return $false } -StatusCallback $null
        Write-TestResult -Value $result
        Write-Host ('[INFO] Profil: {0}' -f $result.ProfileName)
        Write-Host ('[INFO] Erkannte RailTime-Konten: {0}' -f $result.DetectedAccounts.Count)
        Write-Host ('[INFO] Zielordner: {0}' -f $result.TargetDirectory)
        Write-Host ('[INFO] Verifizierte Dateien: {0}' -f $result.InstalledFiles)
        Write-Host ('[INFO] Paket-SHA-256: {0}' -f $result.PackageFingerprint)
        Write-Host '[INFO] Neues Outlook: keine lokale Änderung; Signatur manuell unter Einstellungen > Konten > Signaturen einfügen.'
        Write-Host ('[ERFOLG] Signatur für {0} installiert.' -f $result.AccountEmail)
        return 0
    } catch {
        $exitCode = Get-InstallerExitCode -Exception $_.Exception
        $failure = [pscustomobject] @{
            Success = $false
            ExitCode = $exitCode
            Message = $_.Exception.Message
            LogPath = $script:LogPath
        }
        Write-InstallerLog -Level FEHLER -Message $_.Exception.Message
        Write-TestResult -Value $failure
        Write-Host ('[FEHLER] {0}' -f $_.Exception.Message)
        return $exitCode
    }
}

function Open-InstallerLog {
    if (Test-Path -LiteralPath $script:LogPath -PathType Leaf) {
        Start-Process -FilePath 'notepad.exe' -ArgumentList @('"' + $script:LogPath + '"')
    }
}

function Open-NewOutlookTemplate {
    [void] (Test-Package -Name $SignatureName -Root $SourceDirectory)
    $templatePath = Join-Path $SourceDirectory ($SignatureName + '.htm')
    if (-not (Test-Path -LiteralPath $templatePath -PathType Leaf)) {
        Throw-InstallerError -ExitCode 11 -Message 'Die HTML-Kopiervorlage für das neue Outlook wurde nicht gefunden.'
    }

    Start-Process -FilePath $templatePath
    Write-InstallerStatus -Level INFO -Message 'HTML-Kopiervorlage für das neue Outlook wurde geöffnet.'
}

function Show-InstallerGui {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()
    [System.Windows.Forms.Application]::SetCompatibleTextRenderingDefault($false)

    $form = New-Object System.Windows.Forms.Form
    $form.Text = $script:InstallerTitle
    $form.StartPosition = 'CenterScreen'
    $form.AutoScaleMode = [System.Windows.Forms.AutoScaleMode]::Dpi
    $workingHeight = [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Height
    $clientHeight = [Math]::Max(560, [Math]::Min(750, $workingHeight - 80))
    $form.ClientSize = New-Object System.Drawing.Size(680, $clientHeight)
    $form.MinimumSize = New-Object System.Drawing.Size(696, ([Math]::Min(609, $workingHeight - 20)))
    $form.AutoScroll = $true
    $form.AutoScrollMinSize = New-Object System.Drawing.Size(680, 750)
    $form.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::FixedSingle
    $form.MaximizeBox = $false
    $form.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#F5F7FA')
    $form.Font = New-Object System.Drawing.Font('Segoe UI', 10)

    $header = New-Object System.Windows.Forms.Panel
    $header.Location = New-Object System.Drawing.Point(0, 0)
    $header.Size = New-Object System.Drawing.Size(680, 112)
    $header.Anchor = 'Top,Left,Right'
    $header.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#071C33')
    $form.Controls.Add($header)

    $brand = New-Object System.Windows.Forms.Label
    $brand.Text = 'RAILTIME'
    $brand.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#E4002B')
    $brand.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 10)
    $brand.AutoSize = $true
    $brand.Location = New-Object System.Drawing.Point(28, 20)
    $header.Controls.Add($brand)

    $title = New-Object System.Windows.Forms.Label
    $title.Text = 'Outlook-Signatur einrichten'
    $title.ForeColor = [System.Drawing.Color]::White
    $title.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 20)
    $title.AutoSize = $true
    $title.Location = New-Object System.Drawing.Point(24, 45)
    $header.Controls.Add($title)

    $subtitle = New-Object System.Windows.Forms.Label
    $subtitle.Text = 'Classic Outlook · persönliche RailTime-Konfiguration'
    $subtitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#C8D3DF')
    $subtitle.AutoSize = $true
    $subtitle.Location = New-Object System.Drawing.Point(28, 82)
    $header.Controls.Add($subtitle)

    $intro = New-Object System.Windows.Forms.Label
    $intro.Text = 'Die Einrichtung schließt Outlook automatisch, installiert die Dateien und ordnet die Signatur dem ersten Konto mit @rail-time.de zu.'
    $intro.Location = New-Object System.Drawing.Point(28, 132)
    $intro.Size = New-Object System.Drawing.Size(620, 48)
    $intro.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#24364A')
    $form.Controls.Add($intro)

    $accountCaption = New-Object System.Windows.Forms.Label
    $accountCaption.Text = 'Erkanntes Classic-Outlook-Konto'
    $accountCaption.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 9)
    $accountCaption.Location = New-Object System.Drawing.Point(28, 191)
    $accountCaption.AutoSize = $true
    $accountCaption.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#5D6B7A')
    $form.Controls.Add($accountCaption)

    $accountValue = New-Object System.Windows.Forms.Label
    $accountValue.Text = 'Wird geprüft …'
    $accountValue.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 12)
    $accountValue.Location = New-Object System.Drawing.Point(28, 214)
    $accountValue.Size = New-Object System.Drawing.Size(620, 30)
    $accountValue.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#071C33')
    $form.Controls.Add($accountValue)

    $detailsBox = New-Object System.Windows.Forms.TextBox
    $detailsBox.Location = New-Object System.Drawing.Point(28, 252)
    $detailsBox.Size = New-Object System.Drawing.Size(620, 130)
    $detailsBox.Multiline = $true
    $detailsBox.ReadOnly = $true
    $detailsBox.ScrollBars = 'Vertical'
    $detailsBox.BackColor = [System.Drawing.Color]::White
    $detailsBox.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#24364A')
    $detailsBox.Text = "Installer $($script:InstallerVersion)`r`nPaket, Konten und Zielpfad werden geprüft …"
    $form.Controls.Add($detailsBox)

    $statusPanel = New-Object System.Windows.Forms.Panel
    $statusPanel.Location = New-Object System.Drawing.Point(28, 394)
    $statusPanel.Size = New-Object System.Drawing.Size(620, 96)
    $statusPanel.Anchor = 'Top,Left,Right'
    $statusPanel.BackColor = [System.Drawing.Color]::White
    $statusPanel.BorderStyle = 'FixedSingle'
    $form.Controls.Add($statusPanel)

    $statusTitle = New-Object System.Windows.Forms.Label
    $statusTitle.Text = 'Bereit zur Prüfung'
    $statusTitle.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 11)
    $statusTitle.Location = New-Object System.Drawing.Point(16, 14)
    $statusTitle.Size = New-Object System.Drawing.Size(584, 25)
    $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#071C33')
    $statusPanel.Controls.Add($statusTitle)

    $statusText = New-Object System.Windows.Forms.Label
    $statusText.Text = 'Das Installationspaket und das Outlook-Profil werden geprüft.'
    $statusText.Location = New-Object System.Drawing.Point(16, 42)
    $statusText.Size = New-Object System.Drawing.Size(584, 42)
    $statusText.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#5D6B7A')
    $statusPanel.Controls.Add($statusText)

    $progress = New-Object System.Windows.Forms.ProgressBar
    $progress.Location = New-Object System.Drawing.Point(28, 503)
    $progress.Size = New-Object System.Drawing.Size(620, 8)
    $progress.Minimum = 0
    $progress.Maximum = 100
    $progress.Value = 0
    $progress.Anchor = 'Top,Left,Right'
    $form.Controls.Add($progress)

    $notice = New-Object System.Windows.Forms.Label
    $notice.Text = 'Classic Outlook: Offene Entwürfe speichern. Nur wenn es regulär nicht beendet werden kann, fragt die Einrichtung vor einem erzwungenen Schließen nach. Neues Outlook: bleibt geöffnet und unverändert; die Signatur wird dort über den manuellen Kontoweg eingerichtet.'
    $notice.Location = New-Object System.Drawing.Point(28, 524)
    $notice.Size = New-Object System.Drawing.Size(620, 76)
    $notice.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#5D6B7A')
    $form.Controls.Add($notice)

    $newOutlookButton = New-Object System.Windows.Forms.Button
    $newOutlookButton.Text = 'Kopiervorlage für neues Outlook öffnen'
    $newOutlookButton.Location = New-Object System.Drawing.Point(28, 615)
    $newOutlookButton.Size = New-Object System.Drawing.Size(620, 44)
    $newOutlookButton.FlatStyle = 'Flat'
    $newOutlookButton.FlatAppearance.BorderColor = [System.Drawing.ColorTranslator]::FromHtml('#AAB5C0')
    $newOutlookButton.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#24364A')
    $newOutlookButton.AccessibleDescription = 'Öffnet die HTML-Kopiervorlage für die manuelle Einrichtung im neuen Outlook.'
    $form.Controls.Add($newOutlookButton)

    $installButton = New-Object System.Windows.Forms.Button
    $installButton.Text = 'Outlook schließen und installieren'
    $installButton.Location = New-Object System.Drawing.Point(28, 677)
    $installButton.Size = New-Object System.Drawing.Size(278, 46)
    $installButton.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#D0D5DD')
    $installButton.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#667085')
    $installButton.FlatStyle = 'Flat'
    $installButton.FlatAppearance.BorderSize = 0
    $installButton.UseVisualStyleBackColor = $false
    $installButton.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 10)
    $installButton.AccessibleDescription = 'Schließt Outlook und richtet die persönliche RailTime-Signatur ein.'
    $installButton.Enabled = $false
    $form.Controls.Add($installButton)

    $logButton = New-Object System.Windows.Forms.Button
    $logButton.Text = 'Protokoll öffnen'
    $logButton.Location = New-Object System.Drawing.Point(316, 677)
    $logButton.Size = New-Object System.Drawing.Size(148, 46)
    $logButton.FlatStyle = 'Flat'
    $logButton.FlatAppearance.BorderColor = [System.Drawing.ColorTranslator]::FromHtml('#AAB5C0')
    $logButton.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#24364A')
    $form.Controls.Add($logButton)

    $closeButton = New-Object System.Windows.Forms.Button
    $closeButton.Text = 'Schließen'
    $closeButton.Location = New-Object System.Drawing.Point(474, 677)
    $closeButton.Size = New-Object System.Drawing.Size(174, 46)
    $closeButton.FlatStyle = 'Flat'
    $closeButton.FlatAppearance.BorderColor = [System.Drawing.ColorTranslator]::FromHtml('#AAB5C0')
    $closeButton.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#24364A')
    $closeButton.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    $form.Controls.Add($closeButton)
    $form.CancelButton = $closeButton

    $guiState = @{
        Context = $null
        Installing = $false
        Installed = $false
    }

    $setStatus = {
        param([string] $Message, [int] $Percent, [string] $Detail)
        $statusTitle.Text = $Message
        $statusText.Text = $Detail
        $progress.Value = [Math]::Max(0, [Math]::Min(100, $Percent))
        [System.Windows.Forms.Application]::DoEvents()
    }

    $confirmForceClose = {
        param([array] $RemainingProcesses)
        $message = "Outlook reagiert nicht auf das reguläre Schließen.`r`n`r`nBeim erzwungenen Beenden können nicht gespeicherte Entwürfe verloren gehen. Outlook jetzt trotzdem beenden?"
        $choice = [System.Windows.Forms.MessageBox]::Show(
            $form,
            $message,
            $script:InstallerTitle,
            [System.Windows.Forms.MessageBoxButtons]::YesNo,
            [System.Windows.Forms.MessageBoxIcon]::Warning,
            [System.Windows.Forms.MessageBoxDefaultButton]::Button2
        )
        return $choice -eq [System.Windows.Forms.DialogResult]::Yes
    }

    $form.Add_Shown({
        try {
            $statusTitle.Text = 'Paket und Konto werden geprüft …'
            [System.Windows.Forms.Application]::DoEvents()
            $guiState.Context = Get-InstallationContext
            $accountValue.Text = $guiState.Context.Account.Email
            $statusTitle.Text = 'Bereit zur Installation'
            $statusText.Text = ('{0} RailTime-Konto/Konten erkannt. Automatische Zuordnung nur zum ersten Konto.' -f $guiState.Context.Accounts.Count)
            $accountLines = @($guiState.Context.Accounts | ForEach-Object { '  • ' + $_.Email })
            $detailLines = @(
                ('Installer: {0}' -f $script:InstallerVersion),
                ('Classic-Profil: {0}' -f $guiState.Context.Account.ProfileName),
                'Erkannte RailTime-Konten:'
            )
            $detailLines += $accountLines
            $detailLines += @(
                ('Paketordner: {0}' -f $guiState.Context.SourceDirectory),
                ('Zielordner: {0}' -f $guiState.Context.TargetDirectory),
                ('Signaturdateien: {0}' -f $guiState.Context.RelativePaths.Count),
                ('Paket-SHA-256: {0}…' -f (Get-ShortHash -Hash $guiState.Context.Package.Fingerprint)),
                'Neues Outlook: manueller Kontoweg, keine lokale Änderung',
                'SHA-256-Dateiprüfung:'
            )
            foreach ($verifiedFile in $guiState.Context.Package.Files) {
                $detailLines += '  • {0} · {1} Byte · {2}…' -f $verifiedFile.Path, $verifiedFile.Bytes, (Get-ShortHash -Hash $verifiedFile.Sha256)
            }
            $detailsBox.Lines = $detailLines
            $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#0F6B44')
            $installButton.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#E4002B')
            $installButton.ForeColor = [System.Drawing.Color]::White
            $installButton.Enabled = $true
            [void] $installButton.Select()
        } catch {
            $script:LastExitCode = Get-InstallerExitCode -Exception $_.Exception
            Write-InstallerLog -Level FEHLER -Message $_.Exception.Message
            $accountValue.Text = 'Kein passendes @rail-time.de-Konto'
            $statusTitle.Text = 'Installation nicht möglich'
            $statusText.Text = $_.Exception.Message
            $detailsBox.Text = "Classic Outlook kann nicht automatisch eingerichtet werden.`r`n`r`nDas neue Outlook kann weiterhin über die HTML-Kopiervorlage manuell eingerichtet werden."
            $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#B42318')
            $statusPanel.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#FFF2F0')
            $installButton.Text = 'Kein RailTime-Konto gefunden'
            [void] [System.Windows.Forms.MessageBox]::Show($form, $_.Exception.Message, $script:InstallerTitle, [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Error)
            [void] $closeButton.Select()
        }
    })

    $installButton.Add_Click({
        if ($guiState.Installing -or $null -eq $guiState.Context) {
            return
        }

        $guiState.Installing = $true
        $installButton.Enabled = $false
        $newOutlookButton.Enabled = $false
        $closeButton.Enabled = $false
        $statusPanel.BackColor = [System.Drawing.Color]::White
        $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#071C33')

        try {
            $result = Invoke-RailTimeInstallation -Context $guiState.Context -ConfirmForceClose $confirmForceClose -StatusCallback $setStatus
            $script:LastExitCode = 0
            $guiState.Installed = $true
            $statusTitle.Text = 'Installation erfolgreich'
            $statusText.Text = ('Die Signatur wurde installiert und {0} für neue Nachrichten sowie Antworten zugeordnet.' -f $result.AccountEmail)
            $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#0F6B44')
            $statusPanel.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#ECFDF3')
            $installButton.Text = 'Erneut installieren'
            [void] [System.Windows.Forms.MessageBox]::Show(
                $form,
                ("Die RailTime-Signatur wurde erfolgreich installiert.`r`n`r`nKonto: {0}`r`nSignatur: {1}`r`nZiel: {2}`r`nDateien: {3}`r`nPaket-SHA-256: {4}…`r`n`r`nClassic Outlook kann jetzt wieder gestartet und mit einer Testmail geprüft werden. Das neue Outlook bleibt separat manuell einzurichten." -f $result.AccountEmail, $result.SignatureName, $result.TargetDirectory, $result.InstalledFiles, (Get-ShortHash -Hash $result.PackageFingerprint)),
                'Installation erfolgreich',
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Information
            )
        } catch {
            $script:LastExitCode = Get-InstallerExitCode -Exception $_.Exception
            Write-InstallerLog -Level FEHLER -Message $_.Exception.Message
            $statusTitle.Text = 'Installation fehlgeschlagen'
            $statusText.Text = $_.Exception.Message
            $statusTitle.ForeColor = [System.Drawing.ColorTranslator]::FromHtml('#B42318')
            $statusPanel.BackColor = [System.Drawing.ColorTranslator]::FromHtml('#FFF2F0')
            [void] [System.Windows.Forms.MessageBox]::Show(
                $form,
                ($_.Exception.Message + "`r`n`r`nProtokoll: " + $script:LogPath),
                'Installation fehlgeschlagen',
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Error
            )
        } finally {
            $guiState.Installing = $false
            $installButton.Enabled = $true
            $newOutlookButton.Enabled = $true
            $closeButton.Enabled = $true
        }
    })

    $logButton.Add_Click({ Open-InstallerLog })
    $newOutlookButton.Add_Click({
        try {
            Open-NewOutlookTemplate
            [void] [System.Windows.Forms.MessageBox]::Show(
                $form,
                "Die HTML-Kopiervorlage wurde geöffnet.`r`n`r`n1. Im Browser Strg+A und Strg+C.`r`n2. Neues Outlook: Einstellungen > Konten > Signaturen.`r`n3. Richtiges RailTime-Konto wählen, Signatur einfügen und speichern.`r`n4. Für neue Nachrichten und Antworten auswählen und eine Testmail erstellen.`r`n`r`nDies ist bewusst ein manueller, von Outlook unterstützter Kontoweg; der lokale Classic-Ordner wird vom neuen Outlook nicht eingelesen.",
                'Neues Outlook manuell einrichten',
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Information
            )
        } catch {
            [void] [System.Windows.Forms.MessageBox]::Show($form, $_.Exception.Message, $script:InstallerTitle, [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Error)
        }
    })
    $closeButton.Add_Click({ $form.Close() })
    $form.Add_FormClosing({
        param($sender, $eventArgs)
        if ($guiState.Installing) {
            $eventArgs.Cancel = $true
        }
    })

    [void] $form.ShowDialog()
    if (-not $guiState.Installed -and $script:LastExitCode -eq 0) {
        $script:LastExitCode = 2
        Write-InstallerStatus -Level WARNUNG -Message 'Die Einrichtung wurde ohne Installation geschlossen.'
    }
    return $script:LastExitCode
}

try {
    if ([string]::IsNullOrWhiteSpace($SourceDirectory)) {
        $SourceDirectory = $PSScriptRoot
    }
    $SourceDirectory = Get-FullPath -Path $SourceDirectory

    if ($NoGui -and -not $TestMode) {
        Throw-InstallerError -ExitCode 8 -Message 'Der unsichtbare Modus ist ausschließlich für isolierte Tests erlaubt.'
    }

    Initialize-InstallerLog

    $script:Mutex = New-Object System.Threading.Mutex($false, 'Local\RailTime-Outlook-Signatur-Installer')
    $script:MutexAcquired = $script:Mutex.WaitOne(0)
    if (-not $script:MutexAcquired) {
        Throw-InstallerError -ExitCode 9 -Message 'Die RailTime Outlook-Einrichtung läuft bereits.'
    }

    if ($TestMode) {
        $script:LastExitCode = Invoke-TestInstallation
    } else {
        $script:LastExitCode = Show-InstallerGui
    }
} catch {
    $script:LastExitCode = Get-InstallerExitCode -Exception $_.Exception
    if (-not [string]::IsNullOrWhiteSpace($script:LogPath)) {
        Write-InstallerLog -Level FEHLER -Message $_.Exception.Message
    }
    Write-Host ('[FEHLER] {0}' -f $_.Exception.Message)
} finally {
    if ($script:MutexAcquired -and $null -ne $script:Mutex) {
        [void] $script:Mutex.ReleaseMutex()
    }
    if ($null -ne $script:Mutex) {
        $script:Mutex.Dispose()
    }
}

exit $script:LastExitCode

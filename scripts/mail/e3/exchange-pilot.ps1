#Requires -Version 5.1
<#
.SYNOPSIS
Read-only Exchange inspection and explicitly approved DISABLED staging for C-K17.
.DESCRIPTION
No login is opened. Connect to the intended Exchange Online tenant separately.
Inspect is Get-only. StageDisabled creates new disabled objects, never enables
them, and refuses name collisions. Rollback disables only our marked pilot rule;
connectors remain for queue drain. There is deliberately no Enable operation.

The sample tenantId is populated from verified public metadata; it still needs
confirmation against the authenticated Get-only tenant state. The template
observation time remains empty. Populate private configuration after fresh
read-only verification; a handoff header is not fresh
production proof. Keep the ingress secret outside JSON/Git/transcripts. Supply
it as SecureString. Do not run with PowerShell transcription, verbose/debug
logging or module parameter tracing: the Exchange cmdlet necessarily receives
its cleartext value. This script never displays the value or raw cmdlet errors.

No Validate-OutboundConnector (it sends), Graph, DNS, RemoteDomain writes,
mailbox writes, automatic authentication, or actual SMTP is implemented here.

Primary references, reviewed 2026-11-04:
https://learn.microsoft.com/en-gb/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/integrate-office-365-with-an-email-add-on-service
https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/inbound-connector-faq
https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-inboundconnector
https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-outboundconnector
https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-transportrule

OnPremises certificate matching uses RequireTls/TlsSenderCertificateName.
RestrictDomainsToCertificate is documented as Partner-only in the cmdlet
reference, despite the add-on guide's conflicting example. We do not rely on
that flag, an IP-only add-on relay, or TreatMessagesAsInternal. Before activation,
the actual TLS certificate attribution must be proved at the return hop.
.EXAMPLE
.\exchange-pilot.ps1
.EXAMPLE
.\exchange-pilot.ps1 -Action StageDisabled -ConfigPath .\pilot-private.json -UserApproved -WhatIf
# All preflight guards, including a fresh template observation, still apply.
.EXAMPLE
.\exchange-pilot.ps1 -Action StageDisabled -ConfigPath .\pilot-private.json -UserApproved -IngressSecret $secret -Confirm:$false
# Requires a preceding, specific user approval. Creates disabled objects only.
.EXAMPLE
.\exchange-pilot.ps1 -Action Rollback -ConfigPath .\pilot-private.json -UserApproved -WhatIf
#>
[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
param(
    [ValidateSet('Inspect', 'StageDisabled', 'Rollback')]
    [string]$Action = 'Inspect',
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'pilot-config.example.json'),
    [switch]$UserApproved,
    [switch]$ExternalRecipientScopeApproved,
    [Security.SecureString]$IngressSecret
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$OwnedMarker = 'RailTime-C-K17-E3-20261104; disabled-staging-only'
$ExpectedPilot = 'it-technik@rail-time.de'
$ExpectedTemplateHash = '942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba'
$ExpectedNames = @{
    outboundConnectorName = 'RailTime C-K17 E3 Out'
    inboundConnectorName = 'RailTime C-K17 E3 Return'
    ruleName = 'RailTime C-K17 E3 Pilot'
}

function Stop-Safe([string]$Code) {
    throw [InvalidOperationException]::new($Code)
}

function Get-Field($Object, [string]$Name, $Default = $null) {
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $Default }
    return $property.Value
}

function Assert-Tenant($Configuration, $Organization) {
    $id = [string](Get-Field $Configuration 'tenantId' '')
    $parsed = [Guid]::Empty
    if (-not [Guid]::TryParse($id, [ref]$parsed) -or $parsed -eq [Guid]::Empty) {
        Stop-Safe 'E3_TENANT_ID_REQUIRED'
    }
    $actual = [string](Get-Field $Organization 'ExternalDirectoryOrganizationId' '')
    if ($actual -ne $parsed.ToString()) { Stop-Safe 'E3_WRONG_OR_UNKNOWN_TENANT' }
}

function Get-RedactedState($Configuration) {
    # Do not include comments, rule actions or SetHeaderValue: another rule may
    # contain a secret. These calls neither authenticate nor send test messages.
    $organization = Get-OrganizationConfig
    $accepted = @(Get-AcceptedDomain)
    $inbound = @(Get-InboundConnector)
    $outbound = @(Get-OutboundConnector)
    $rules = @(Get-TransportRule)
    $remote = @(Get-RemoteDomain)
    return [pscustomobject]@{
        Organization = $organization
        Accepted = $accepted
        Inbound = $inbound
        Outbound = $outbound
        Rules = $rules
        Remote = $remote
    }
}

function Assert-StageConfiguration($Configuration, $State) {
    if ((Get-Field $Configuration 'schemaVersion' 0) -ne 1) { Stop-Safe 'E3_CONFIG_SCHEMA' }
    if ([string](Get-Field $Configuration 'pilotSender' '') -cne $ExpectedPilot) {
        Stop-Safe 'E3_PILOT_MUST_REMAIN_IT'
    }
    if ([string](Get-Field $Configuration 'smartHost' '') -cne 'plesk.follow-flow.de') {
        Stop-Safe 'E3_SMARTHOST_MUST_REMAIN_PLESK'
    }
    if ([string](Get-Field $Configuration 'returnMx' '') -cne 'railtime-de01b.mail.protection.outlook.com') {
        Stop-Safe 'E3_RETURN_MX_REQUIRES_SEPARATE_REVIEW'
    }
    foreach ($key in $ExpectedNames.Keys) {
        if ([string](Get-Field $Configuration $key '') -cne $ExpectedNames[$key]) {
            Stop-Safe 'E3_OBJECT_NAME_SCOPE'
        }
    }
    if ([string](Get-Field $Configuration 'templateSha256' '') -cne $ExpectedTemplateHash) {
        Stop-Safe 'E3_ONLY_APPROVED_PRODUCTION_V2'
    }
    $sourcePath = Join-Path $PSScriptRoot '..\..\..\resources\mail-templates\u2e-universal-v1.html'
    $source = [IO.File]::ReadAllText($sourcePath, [Text.UTF8Encoding]::new($false, $true))
    $source = $source.Replace("`r`n", "`n")
    $hashEngine = [Security.Cryptography.SHA256]::Create()
    try {
        $digest = $hashEngine.ComputeHash([Text.Encoding]::UTF8.GetBytes($source))
        $hash = [BitConverter]::ToString($digest).Replace('-', '').ToLowerInvariant()
    } finally { $hashEngine.Dispose() }
    if ($hash -cne $ExpectedTemplateHash) { Stop-Safe 'E3_LOCAL_V2_SOURCE_CHANGED' }
    $header = [string](Get-Field $Configuration 'approvedTemplateHeader' '')
    if ($header -cnotmatch '^u2e-[0-9a-f]{24}$') { Stop-Safe 'E3_EXACT_B1_HEADER_REQUIRED' }
    $observed = [DateTimeOffset]::MinValue
    $observedText = [string](Get-Field $Configuration 'templateMetadataObservedAtUtc' '')
    if (-not $observedText.EndsWith('Z') -or
        -not [DateTimeOffset]::TryParse($observedText, [ref]$observed)) {
        Stop-Safe 'E3_FRESH_B1_METADATA_OBSERVATION_REQUIRED'
    }
    $age = ([DateTimeOffset]::UtcNow - $observed.ToUniversalTime()).TotalMinutes
    if ($age -lt 0 -or $age -gt 60) { Stop-Safe 'E3_B1_METADATA_OBSERVATION_STALE' }
    $recipients = @(Get-Field $Configuration 'recipientAllowList' @())
    if ($recipients.Count -eq 0 -or $recipients.Count -gt 5) { Stop-Safe 'E3_RECIPIENT_SCOPE_INVALID' }
    foreach ($recipient in $recipients) {
        if ([string]$recipient -cnotmatch '^[a-z0-9][a-z0-9._-]*@[a-z0-9.-]+\.[a-z]{2,}$') {
            Stop-Safe 'E3_RECIPIENT_MUST_BE_EXACT_SMTP_ADDRESS'
        }
        if ([string]$recipient -cne $ExpectedPilot -and -not $ExternalRecipientScopeApproved) {
            Stop-Safe 'E3_OTHER_RECIPIENT_REQUIRES_SEPARATE_APPROVAL'
        }
    }
    if (@($recipients | Sort-Object -Unique).Count -ne $recipients.Count) {
        Stop-Safe 'E3_DUPLICATE_RECIPIENT'
    }
    $certDomain = [string](Get-Field $Configuration 'returnTlsCertificateDomain' '')
    if ($certDomain -cnotmatch '^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$') {
        Stop-Safe 'E3_EXACT_CERTIFICATE_DOMAIN_REQUIRED'
    }
    $acceptedNames = @($State.Accepted | ForEach-Object { [string]$_.DomainName })
    if ($acceptedNames -notcontains 'rail-time.de' -or $acceptedNames -notcontains $certDomain) {
        Stop-Safe 'E3_SENDER_AND_UNIQUE_CERTIFICATE_DOMAIN_MUST_BE_ACCEPTED'
    }
    # No existing object is silently overwritten, reused, widened or re-enabled.
    if (@($State.Inbound | Where-Object { $_.Name -eq $ExpectedNames.inboundConnectorName }).Count -or
        @($State.Outbound | Where-Object { $_.Name -eq $ExpectedNames.outboundConnectorName }).Count -or
        @($State.Rules | Where-Object { $_.Name -eq $ExpectedNames.ruleName }).Count) {
        Stop-Safe 'E3_OBJECT_COLLISION_INSPECT_AND_REVIEW_MANUALLY'
    }
    # A second inbound connector using our cert makes return attribution unclear.
    if (@($State.Inbound | Where-Object {
        $existingCert = [string](Get-Field $_ 'TlsSenderCertificateName' '')
        -not [string]::IsNullOrWhiteSpace($existingCert) -and $certDomain -like $existingCert
    }).Count) { Stop-Safe 'E3_RETURN_CERTIFICATE_CONNECTOR_COLLISION' }
    # Existing routes for the pilot or broad connector routing need manual review.
    if (@($State.Rules | Where-Object {
        [string](Get-Field $_ 'State' '') -eq 'Enabled' -and
        -not [string]::IsNullOrWhiteSpace([string](Get-Field $_ 'RouteMessageOutboundConnector' ''))
    }).Count) { Stop-Safe 'E3_EXISTING_ENABLED_ROUTE_REQUIRES_REVIEW' }
    if (@($State.Outbound | Where-Object {
        (Get-Field $_ 'Enabled' $false) -and
        ((Get-Field $_ 'RouteAllMessagesViaOnPremises' $false) -or
         (Get-Field $_ 'AllAcceptedDomains' $false))
    }).Count) { Stop-Safe 'E3_BROAD_EXISTING_CONNECTOR_REQUIRES_REVIEW' }
}

try {
    $configurationText = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8
    # PowerShell 7.5+ otherwise converts JSON timestamps to DateTime and loses
    # the original explicit UTC spelling. Windows PowerShell keeps strings.
    if ((Get-Command ConvertFrom-Json).Parameters.ContainsKey('DateKind')) {
        $configuration = $configurationText | ConvertFrom-Json -DateKind String
    } else {
        $configuration = $configurationText | ConvertFrom-Json
    }
} catch { Stop-Safe 'E3_CONFIGURATION_NOT_READABLE' }

foreach ($name in @('Get-OrganizationConfig', 'Get-AcceptedDomain', 'Get-InboundConnector',
                    'Get-OutboundConnector', 'Get-TransportRule', 'Get-RemoteDomain')) {
    if (-not (Get-Command -Name $name -ErrorAction SilentlyContinue)) {
        Stop-Safe 'E3_CONNECT_TO_INTENDED_EXCHANGE_TENANT_SEPARATELY'
    }
}
try { $state = Get-RedactedState $configuration }
catch { Stop-Safe 'E3_READ_ONLY_PREFLIGHT_FAILED_NO_MUTATION' }

if ($Action -eq 'Inspect') {
    [pscustomobject]@{
        Action = 'Inspect'
        Mutations = $false
        AutomaticLogin = $false
        ActivationAvailable = $false
        ConfiguredTenantId = [string](Get-Field $configuration 'tenantId' '')
        ConnectedTenantId = [string](Get-Field $state.Organization 'ExternalDirectoryOrganizationId' '')
        PilotSender = [string](Get-Field $configuration 'pilotSender' '')
        RecipientAllowList = @(Get-Field $configuration 'recipientAllowList' @())
        AcceptedDomains = @($state.Accepted | Select-Object DomainName, DomainType)
        InboundConnectors = @($state.Inbound | Select-Object Name, Enabled, ConnectorType, RequireTls, TlsSenderCertificateName, SenderIPAddresses)
        OutboundConnectors = @($state.Outbound | Select-Object Name, Enabled, ConnectorType, IsTransportRuleScoped, SmartHosts, TlsSettings, TlsDomain)
        Rules = @($state.Rules | Select-Object Name, State, Priority)
        RemoteDomains = @($state.Remote | Select-Object Name, DomainName, TNEFEnabled)
    }
    return
}

if (-not $UserApproved) { Stop-Safe 'E3_SPECIFIC_USER_APPROVAL_REQUIRED' }
Assert-Tenant $configuration $state.Organization

if ($Action -eq 'Rollback') {
    $own = @($state.Rules | Where-Object { $_.Name -eq $ExpectedNames.ruleName })
    if ($own.Count -ne 1 -or [string](Get-Field $own[0] 'Comments' '') -cne $OwnedMarker) {
        Stop-Safe 'E3_ROLLBACK_REQUIRES_EXACT_OWNED_RULE'
    }
    if ($PSCmdlet.ShouldProcess($ExpectedNames.ruleName, 'Disable our pilot rule; retain connectors and both queues')) {
        try { Disable-TransportRule -Identity $own[0].Identity -Confirm:$false | Out-Null }
        catch { Stop-Safe 'E3_RULE_DISABLE_FAILED_INSPECT_MANUALLY' }
    }
    [pscustomobject]@{ Action='Rollback'; ConnectorsRetained=$true; QueueDrainStillRequired=$true; ActivationAvailable=$false }
    return
}

Assert-StageConfiguration $configuration $state
if ($VerbosePreference -ne 'SilentlyContinue' -or $DebugPreference -ne 'SilentlyContinue') {
    Stop-Safe 'E3_DISABLE_VERBOSE_DEBUG_AND_TRANSCRIPTION_BEFORE_SECRET_USE'
}
if (-not $WhatIfPreference -and ($null -eq $IngressSecret -or $IngressSecret.Length -ne 64)) {
    Stop-Safe 'E3_INGRESS_SECRET_SECURESTRING_REQUIRED'
}
if (-not $WhatIfPreference) {
    $secretCheckPointer = [IntPtr]::Zero
    try {
        $secretCheckPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($IngressSecret)
        $secretCheckText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($secretCheckPointer)
        if ($secretCheckText -cnotmatch '^[0-9a-f]{64}$') { Stop-Safe 'E3_SECRET_MUST_BE_256_BIT_LOWER_HEX' }
    } finally {
        $secretCheckText = $null
        if ($secretCheckPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secretCheckPointer) }
    }
}

$inboundArguments = @{
    Name = $ExpectedNames.inboundConnectorName
    ConnectorType = 'OnPremises'
    SenderDomains = '*'
    RequireTls = $true
    TlsSenderCertificateName = [string]$configuration.returnTlsCertificateDomain
    CloudServicesMailEnabled = $false
    TreatMessagesAsInternal = $false
    Enabled = $false
    Comment = $OwnedMarker
}
$outboundArguments = @{
    Name = $ExpectedNames.outboundConnectorName
    ConnectorType = 'OnPremises'
    IsTransportRuleScoped = $true
    UseMXRecord = $false
    SmartHosts = 'plesk.follow-flow.de'
    TlsSettings = 'DomainValidation'
    TlsDomain = 'plesk.follow-flow.de'
    CloudServicesMailEnabled = $false
    RouteAllMessagesViaOnPremises = $false
    AllAcceptedDomains = $false
    Enabled = $false
    Comment = $OwnedMarker
}
$recipientPatterns = @($configuration.recipientAllowList | ForEach-Object { '^' + [Regex]::Escape([string]$_) + '$' })
$ruleArguments = @{
    Name = $ExpectedNames.ruleName
    Enabled = $false
    Mode = 'Enforce'
    FromScope = 'InOrganization'
    FromAddressMatchesPatterns = '^it-technik@rail-time\.de$'
    SenderAddressLocation = 'Envelope'
    HeaderMatchesMessageHeader = 'X-RailTime-Template'
    HeaderMatchesPatterns = '^' + [Regex]::Escape([string]$configuration.approvedTemplateHeader) + '$'
    AnyOfRecipientAddressMatchesPatterns = $recipientPatterns
    RouteMessageOutboundConnector = $ExpectedNames.outboundConnectorName
    SetHeaderName = 'X-RailTime-Transit-Ingress'
    Comments = $OwnedMarker
    # No header-based loop exception and no broad StopRuleProcessing bypass.
}

try {
    if ($PSCmdlet.ShouldProcess($inboundArguments.Name, 'Create DISABLED certificate return connector')) {
        New-InboundConnector @inboundArguments -Confirm:$false | Out-Null
    }
    if ($PSCmdlet.ShouldProcess($outboundArguments.Name, 'Create DISABLED rule-scoped outbound connector')) {
        New-OutboundConnector @outboundArguments -Confirm:$false | Out-Null
    }
    if ($PSCmdlet.ShouldProcess($ruleArguments.Name, 'Create DISABLED exact-pilot rule; secret omitted from output')) {
        $pointer = [IntPtr]::Zero
        try {
            $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($IngressSecret)
            $plainSecret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
            if ($plainSecret -cnotmatch '^[0-9a-f]{64}$') { Stop-Safe 'E3_SECRET_MUST_BE_256_BIT_LOWER_HEX' }
            $ruleArguments.SetHeaderValue = $plainSecret
            New-TransportRule @ruleArguments -Confirm:$false | Out-Null
        } finally {
            [void]$ruleArguments.Remove('SetHeaderValue')
            $plainSecret = $null
            if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        }
    }
} catch {
    # Do not emit the raw Exchange error: it can contain rule action values.
    # A failure can leave a subset of DISABLED objects. Never auto-delete them.
    Stop-Safe 'E3_DISABLED_STAGE_FAILED_INSPECT_OWN_OBJECTS_NO_ACTIVATION'
}

if (-not $WhatIfPreference) {
    try {
        $readbackInbound = Get-InboundConnector -Identity $ExpectedNames.inboundConnectorName
        $readbackOutbound = Get-OutboundConnector -Identity $ExpectedNames.outboundConnectorName
        $readbackRule = Get-TransportRule -Identity $ExpectedNames.ruleName
        if ($readbackInbound.Enabled -or $readbackOutbound.Enabled -or $readbackRule.State -ne 'Disabled') {
            Stop-Safe 'E3_UNEXPECTED_ENABLED_STATE'
        }
        if ([string](Get-Field $readbackRule 'Comments' '') -cne $OwnedMarker -or
            [string](Get-Field $readbackInbound 'TlsSenderCertificateName' '') -ne [string]$configuration.returnTlsCertificateDomain -or
            @((Get-Field $readbackInbound 'SenderIPAddresses' @())).Count -ne 0) {
            Stop-Safe 'E3_CREATED_OBJECT_READBACK_MISMATCH'
        }
    } catch { Stop-Safe 'E3_DISABLED_STAGE_READBACK_FAILED_INSPECT_NO_ACTIVATION' }
}
[pscustomobject]@{
    Action = 'StageDisabled'
    WhatIf = [bool]$WhatIfPreference
    ObjectsEnabled = $false
    ActivationAvailable = $false
    AdmissionAndReinjectionEvidenceStillRequired = $true
    ExplicitActivationApprovalStillRequired = $true
    QueuesMayRequireSeparateDrain = $true
}

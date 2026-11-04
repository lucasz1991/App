#Requires -Version 5.1
<#
Offline tests only. Run in a fresh local PowerShell session:
  powershell -NoProfile -File scripts/mail/e3/test-exchange-pilot.ps1
All Exchange cmdlets used by exchange-pilot.ps1 are replaced by local functions.
Synthetic tenant and secret exist only in memory. No temporary configuration,
Exchange module import, login, HTTP/SMTP, mailbox write, or real cmdlet call.
#>
[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (@(Get-Module | Where-Object { $_.Name -match '^(ExchangeOnlineManagement|tmpEXO_)' }).Count -or
    @(Get-PSSession | Where-Object { $_.ConfigurationName -match 'Exchange' }).Count) {
    throw 'E3_TEST_REQUIRES_FRESH_LOCAL_SESSION_WITHOUT_EXCHANGE'
}
if (Get-Variable -Name E3MockState -Scope Global -ErrorAction SilentlyContinue) {
    throw 'E3_TEST_GLOBAL_STATE_ALREADY_EXISTS'
}
$script:Subject = Join-Path $PSScriptRoot 'exchange-pilot.ps1'
$script:BaseConfig = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'pilot-config.example.json'))
$script:Results = [Collections.Generic.List[string]]::new()
$script:SyntheticSecret = ConvertTo-SecureString ('a' * 64) -AsPlainText -Force

function Assert-Local([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
}
function Reset-Mocks {
    $cfg = $script:BaseConfig | ConvertFrom-Json
    $cfg.tenantId = '11111111-1111-4111-8111-111111111111'
    $cfg.templateMetadataObservedAtUtc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    $global:E3MockState = @{
        Config = $cfg; Inbound = @(); Outbound = @(); Rules = @(); Writes = 0
        CertificateAccepted = $true; FailRule = $false; SavedRuleArguments = $null
    }
}
function Get-Content {
    param($LiteralPath, [switch]$Raw, $Encoding)
    Assert-Local ($LiteralPath -ceq 'E3-MEMORY-ONLY') 'E3_TEST_UNEXPECTED_CONFIG_PATH'
    $global:E3MockState.Config | ConvertTo-Json -Depth 5
}
function Get-OrganizationConfig {
    [pscustomobject]@{ ExternalDirectoryOrganizationId = '11111111-1111-4111-8111-111111111111' }
}
function Get-AcceptedDomain {
    [pscustomobject]@{ DomainName = 'rail-time.de'; DomainType = 'Authoritative' }
    if ($global:E3MockState.CertificateAccepted) {
        [pscustomobject]@{ DomainName = 'plesk.follow-flow.de'; DomainType = 'Authoritative' }
    }
}
function Get-InboundConnector {
    param($Identity)
    if ($Identity) { $global:E3MockState.Inbound | Where-Object Name -eq $Identity }
    else { $global:E3MockState.Inbound }
}
function Get-OutboundConnector {
    param($Identity)
    if ($Identity) { $global:E3MockState.Outbound | Where-Object Name -eq $Identity }
    else { $global:E3MockState.Outbound }
}
function Get-TransportRule {
    param($Identity)
    if ($Identity) { $global:E3MockState.Rules | Where-Object Name -eq $Identity }
    else { $global:E3MockState.Rules }
}
function Get-RemoteDomain {
    [pscustomobject]@{ Name = 'Default'; DomainName = '*'; TNEFEnabled = $null }
}
function New-InboundConnector {
    param($Name, $ConnectorType, $SenderDomains, $RequireTls, $TlsSenderCertificateName,
          $CloudServicesMailEnabled, $TreatMessagesAsInternal, $Enabled, $Comment, [switch]$Confirm)
    Assert-Local (-not $Enabled) 'E3_TEST_ENABLED_INBOUND'
    $global:E3MockState.Writes++
    $global:E3MockState.Inbound += [pscustomobject]@{
        Name = $Name; ConnectorType = $ConnectorType; Enabled = $Enabled
        TlsSenderCertificateName = $TlsSenderCertificateName; SenderIPAddresses = @()
        RequireTls = $RequireTls
    }
}
function New-OutboundConnector {
    param($Name, $ConnectorType, $IsTransportRuleScoped, $UseMXRecord, $SmartHosts,
          $TlsSettings, $TlsDomain, $CloudServicesMailEnabled, $RouteAllMessagesViaOnPremises,
          $AllAcceptedDomains, $Enabled, $Comment, [switch]$Confirm)
    Assert-Local (-not $Enabled -and $IsTransportRuleScoped -and -not $UseMXRecord -and
        -not $RouteAllMessagesViaOnPremises -and -not $AllAcceptedDomains) 'E3_TEST_UNSCOPED_OUTBOUND'
    $global:E3MockState.Writes++
    $global:E3MockState.Outbound += [pscustomobject]@{
        Name = $Name; Enabled = $Enabled; IsTransportRuleScoped = $IsTransportRuleScoped
        SmartHosts = $SmartHosts; TlsSettings = $TlsSettings; TlsDomain = $TlsDomain
        ConnectorType = $ConnectorType
    }
}
function New-TransportRule {
    param($Name, $Enabled, $Mode, $FromScope, $FromAddressMatchesPatterns, $SenderAddressLocation,
          $HeaderMatchesMessageHeader, $HeaderMatchesPatterns, $AnyOfRecipientAddressMatchesPatterns,
          $RouteMessageOutboundConnector, $SetHeaderName, $SetHeaderValue, $Comments, [switch]$Confirm)
    Assert-Local (-not $Enabled -and $SetHeaderValue -ceq ('a' * 64)) 'E3_TEST_UNSAFE_RULE'
    if ($global:E3MockState.FailRule) {
        # Simulates a provider error containing a secret; only the wrapper's
        # generic error must escape. The value is never written by the harness.
        throw ('Synthetic provider error: ' + $SetHeaderValue)
    }
    $global:E3MockState.Writes++
    $global:E3MockState.SavedRuleArguments = @{
        FromScope = $FromScope; FromAddressMatchesPatterns = $FromAddressMatchesPatterns
        SenderAddressLocation = $SenderAddressLocation; HeaderName = $HeaderMatchesMessageHeader
        HeaderPattern = $HeaderMatchesPatterns; Recipients = @($AnyOfRecipientAddressMatchesPatterns)
        IngressHeaderName = $SetHeaderName; Route = $RouteMessageOutboundConnector
    }
    $global:E3MockState.Rules += [pscustomobject]@{
        Name = $Name; Identity = $Name; State = 'Disabled'; Comments = $Comments
    }
}
function Disable-TransportRule {
    param($Identity, [switch]$Confirm)
    $global:E3MockState.Writes++
    ($global:E3MockState.Rules | Where-Object Identity -eq $Identity).State = 'Disabled'
}
function Expect-Code([string]$Expected, [scriptblock]$Run) {
    try { & $Run | Out-Null; throw 'E3_TEST_EXPECTED_GUARD_MISSING' }
    catch {
        # Emit fixed expected code only, never the raw possibly sensitive error.
        Assert-Local ($_.Exception.Message -ceq $Expected) ('E3_TEST_WRONG_GUARD_EXPECTED_' + $Expected)
    }
}
function Invoke-Subject {
    param([string]$Action = 'Inspect', [switch]$Approved, [switch]$WhatIfOnly,
          [switch]$OtherRecipientsApproved, [Security.SecureString]$Secret)
    $arguments = @{
        Action = $Action; ConfigPath = 'E3-MEMORY-ONLY'; UserApproved = [bool]$Approved
        WhatIf = [bool]$WhatIfOnly; Confirm = $false
        ExternalRecipientScopeApproved = [bool]$OtherRecipientsApproved
    }
    if ($Secret) { $arguments.IngressSecret = $Secret }
    & $script:Subject @arguments
}

try {
    $tokens = $null; $parseErrors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($script:Subject, [ref]$tokens, [ref]$parseErrors)
    Assert-Local ($parseErrors.Count -eq 0) 'E3_TEST_SUBJECT_PARSE_ERROR'
    $commandNames = @($ast.FindAll({ param($node)
        $node -is [Management.Automation.Language.CommandAst]
    }, $true) | ForEach-Object { $_.GetCommandName() })
    Assert-Local (@($commandNames | Where-Object {
        $_ -match '^(Connect-|Enable-|Validate-OutboundConnector$|Set-RemoteDomain$|Remove-|Invoke-RestMethod$|Invoke-WebRequest$|Send-)'
    }).Count -eq 0) 'E3_TEST_FORBIDDEN_COMMAND_REFERENCE'
    $script:Results.Add('AST: no enable/connect/send/delete/Graph/RemoteDomain mutation reference')

    Reset-Mocks
    $r = Invoke-Subject
    Assert-Local ($r.Action -eq 'Inspect' -and $global:E3MockState.Writes -eq 0) 'E3_TEST_INSPECT'
    $script:Results.Add('Inspect performs no mock mutations')
    Expect-Code 'E3_SPECIFIC_USER_APPROVAL_REQUIRED' { Invoke-Subject -Action StageDisabled }
    $script:Results.Add('Stage requires specific user approval')
    $global:E3MockState.Config.tenantId = ''
    Expect-Code 'E3_TENANT_ID_REQUIRED' { Invoke-Subject -Action StageDisabled -Approved }
    $script:Results.Add('Empty sample tenant fails closed')
    $global:E3MockState.Config.tenantId = '22222222-2222-4222-8222-222222222222'
    Expect-Code 'E3_WRONG_OR_UNKNOWN_TENANT' { Invoke-Subject -Action StageDisabled -Approved }
    $script:Results.Add('Wrong tenant fails closed')

    Reset-Mocks
    $global:E3MockState.Config.templateMetadataObservedAtUtc = ''
    Expect-Code 'E3_FRESH_B1_METADATA_OBSERVATION_REQUIRED' { Invoke-Subject -Action StageDisabled -Approved }
    $script:Results.Add('Fresh B1 header observation required')
    Reset-Mocks
    $r = Invoke-Subject -Action StageDisabled -Approved -WhatIfOnly
    Assert-Local ($r.WhatIf -and $global:E3MockState.Writes -eq 0) 'E3_TEST_WHATIF'
    $script:Results.Add('WhatIf makes no mock mutations and needs no secret')

    Reset-Mocks
    $global:E3MockState.Config.recipientAllowList = @('outside@example.test')
    Expect-Code 'E3_OTHER_RECIPIENT_REQUIRES_SEPARATE_APPROVAL' {
        Invoke-Subject -Action StageDisabled -Approved -WhatIfOnly
    }
    $script:Results.Add('Additional recipient requires separate approval')
    Reset-Mocks
    $badSecret = ConvertTo-SecureString ('Z' * 64) -AsPlainText -Force
    Expect-Code 'E3_SECRET_MUST_BE_256_BIT_LOWER_HEX' {
        Invoke-Subject -Action StageDisabled -Approved -Secret $badSecret
    }
    Assert-Local ($global:E3MockState.Writes -eq 0) 'E3_TEST_SECRET_CHECK_AFTER_MUTATION'
    $script:Results.Add('Invalid secret rejected before every New command')

    Reset-Mocks
    $global:E3MockState.Inbound = @([pscustomobject]@{ Name='Other'; TlsSenderCertificateName='*.follow-flow.de' })
    Expect-Code 'E3_RETURN_CERTIFICATE_CONNECTOR_COLLISION' {
        Invoke-Subject -Action StageDisabled -Approved -WhatIfOnly
    }
    $script:Results.Add('Existing wildcard certificate connector blocks staging')
    Reset-Mocks
    $global:E3MockState.CertificateAccepted = $false
    Expect-Code 'E3_SENDER_AND_UNIQUE_CERTIFICATE_DOMAIN_MUST_BE_ACCEPTED' {
        Invoke-Subject -Action StageDisabled -Approved -WhatIfOnly
    }
    $script:Results.Add('Unaccepted return certificate domain blocks staging')

    Reset-Mocks
    $r = Invoke-Subject -Action StageDisabled -Approved -Secret $script:SyntheticSecret
    Assert-Local ($r.Action -eq 'StageDisabled' -and -not $r.ObjectsEnabled -and $global:E3MockState.Writes -eq 3) 'E3_TEST_DISABLED_STAGE'
    $saved = $global:E3MockState.SavedRuleArguments
    Assert-Local ($saved.FromScope -ceq 'InOrganization' -and $saved.SenderAddressLocation -ceq 'Envelope' -and
        $saved.HeaderName -ceq 'X-RailTime-Template' -and
        $saved.HeaderPattern -ceq ('^' + [Regex]::Escape($global:E3MockState.Config.approvedTemplateHeader) + '$') -and
        $saved.Recipients.Count -eq 1 -and $saved.Recipients[0] -ceq '^it-technik@rail-time\.de$') 'E3_TEST_EXACT_RULE_SCOPE'
    $script:Results.Add('Only disabled objects staged with exact pilot/header/recipient prefilter')
    Expect-Code 'E3_OBJECT_COLLISION_INSPECT_AND_REVIEW_MANUALLY' {
        Invoke-Subject -Action StageDisabled -Approved -Secret $script:SyntheticSecret
    }
    $script:Results.Add('Existing owned names are never overwritten or reused')
    $r = Invoke-Subject -Action Rollback -Approved
    Assert-Local ($r.ConnectorsRetained -and $global:E3MockState.Writes -eq 4 -and
        $global:E3MockState.Inbound.Count -eq 1 -and $global:E3MockState.Outbound.Count -eq 1) 'E3_TEST_ROLLBACK'
    $script:Results.Add('Owned rollback disables rule and retains connectors for queue drain')

    Reset-Mocks
    $global:E3MockState.Rules = @([pscustomobject]@{Name='RailTime C-K17 E3 Pilot';Comments='Not ours';Identity='abc'})
    Expect-Code 'E3_ROLLBACK_REQUIRES_EXACT_OWNED_RULE' { Invoke-Subject -Action Rollback -Approved }
    Assert-Local ($global:E3MockState.Writes -eq 0) 'E3_TEST_FOREIGN_ROLLBACK'
    $script:Results.Add('Rollback refuses a foreign rule with the same name')

    Reset-Mocks
    $global:E3MockState.FailRule = $true
    Expect-Code 'E3_DISABLED_STAGE_FAILED_INSPECT_OWN_OBJECTS_NO_ACTIVATION' {
        Invoke-Subject -Action StageDisabled -Approved -Secret $script:SyntheticSecret
    }
    Assert-Local ($global:E3MockState.Inbound.Count -eq 1 -and -not $global:E3MockState.Inbound[0].Enabled -and
        $global:E3MockState.Outbound.Count -eq 1 -and -not $global:E3MockState.Outbound[0].Enabled) 'E3_TEST_PARTIAL_STAGE'
    $script:Results.Add('Provider error is sanitized; partial created objects remain disabled')

    Reset-Mocks
    $null = Invoke-Subject -Action StageDisabled -Approved -Secret $script:SyntheticSecret
    $pattern = $global:E3MockState.SavedRuleArguments.Recipients[0]
    $envelope = @('it-technik@rail-time.de', 'unapproved@example.test')
    $prefilterMatched = @($envelope | Where-Object { $_ -match $pattern }).Count -gt 0
    $allRecipientsAllowed = @($envelope | Where-Object { $_ -notmatch $pattern }).Count -eq 0
    Assert-Local ($prefilterMatched -and -not $allRecipientsAllowed) 'E3_TEST_MULTI_RCPT_PREFILTER_SEMANTICS'
    $script:Results.Add('AnyOf recipient match is only a prefilter: mixed RCPT set still fails complete Plesk allowlist')

    [pscustomobject]@{
        Result = 'PASS'; Cases = $script:Results.Count; Tests = $script:Results.ToArray()
        RealExchangeCalls = 0; NetworkCalls = 0; TemporaryConfigFiles = 0
        MultiRecipientNote = 'Exchange AnyOf match cannot replace complete SMTP RCPT admission at Plesk.'
    } | ConvertTo-Json -Depth 4
} finally {
    if ($script:SyntheticSecret) { $script:SyntheticSecret.Dispose() }
    Remove-Variable -Name E3MockState -Scope Global -ErrorAction SilentlyContinue
}

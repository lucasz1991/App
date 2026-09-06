[CmdletBinding()]
param()

# Read-only: validate the source tree in the one authoritative App repository.
# This script never clones, initializes Git, changes the index, or rewrites files.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$serviceRoot = (Resolve-Path -LiteralPath (Split-Path -Parent $PSScriptRoot)).Path
$appRoot = (Resolve-Path -LiteralPath (Split-Path -Parent (Split-Path -Parent $serviceRoot))).Path
$comparison = if ([IO.Path]::DirectorySeparatorChar -eq '\') { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }

function Assert-SamePath([string] $Actual, [string] $Expected, [string] $Message) {
    $actualFull = [IO.Path]::GetFullPath($Actual).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    $expectedFull = [IO.Path]::GetFullPath($Expected).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    if (-not $actualFull.Equals($expectedFull, $comparison)) { throw $Message }
}

foreach ($marker in @('artisan', 'composer.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $appRoot $marker) -PathType Leaf)) {
        throw "Canonical Laravel App root marker missing: $marker"
    }
}
$gitRoot = @(& git -C $appRoot rev-parse --show-toplevel)
if ($LASTEXITCODE -ne 0 -or $gitRoot.Count -ne 1) { throw 'Cannot resolve the App Git root.' }
Assert-SamePath $gitRoot[0] $appRoot 'The OpenUEM source must belong directly to the canonical App Git repository.'
Assert-SamePath $serviceRoot (Join-Path (Join-Path $appRoot 'services') 'openuem-fork') 'Unexpected OpenUEM source location.'

foreach ($required in @(
    'LICENSE', 'NOTICE', 'upstream-lock.json',
    'extension/go.mod', 'integration/go.mod',
    'native/worker/go.mod', 'native/worker/main.go', 'native/worker/LICENSE',
    'native/agent/go.mod', 'native/agent/internal/service/windows/main.go', 'native/agent/LICENSE',
    'reference/console/go.mod', 'reference/console/main.go', 'reference/console/LICENSE',
    'reference/nats/go.mod', 'reference/nats/LICENSE'
)) {
    if (-not (Test-Path -LiteralPath (Join-Path $serviceRoot $required) -PathType Leaf)) {
        throw "Unified source/license file missing: $required"
    }
}
foreach ($required in @('extension/protocol', 'extension/server', 'extension/store', 'extension/agentexec', 'native/worker/internal', 'native/agent/internal', 'reference/console/internal')) {
    if (-not (Test-Path -LiteralPath (Join-Path $serviceRoot $required) -PathType Container)) {
        throw "Unified source directory missing: $required"
    }
}

# Traverse explicitly, including hidden entries, without following links or
# junctions. A nested Git directory, Git-file worktree or submodule manifest is
# not a flattened source tree, even if ignored by the outer repository.
$pending = [Collections.Generic.Stack[string]]::new()
$pending.Push($serviceRoot)
$sourceFiles = [Collections.Generic.List[IO.FileInfo]]::new()
while ($pending.Count -gt 0) {
    $directory = $pending.Pop()
    foreach ($entry in Get-ChildItem -LiteralPath $directory -Force) {
        if ($entry.Name -in @('.git', '.gitmodules')) { throw "Nested Git metadata is not permitted: $($entry.FullName)" }
        if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Source links/junctions are not permitted: $($entry.FullName)" }
        if ($entry.PSIsContainer) { $pending.Push($entry.FullName) } else { $sourceFiles.Add($entry) }
    }
}

$indexEntries = @(& git -C $appRoot ls-files --stage -- 'services/openuem-fork')
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the App Git index.' }
if (@($indexEntries | Where-Object { $_ -match '^160000\s' }).Count -gt 0) {
    throw 'The App Git index contains an OpenUEM gitlink; ordinary source files are required.'
}

foreach ($component in @('worker', 'agent')) {
    $modulePath = Join-Path $serviceRoot "native/$component/go.mod"
    $module = Get-Content -LiteralPath $modulePath -Raw
    $replacements = [regex]::Matches($module, '(?m)^\s*(?:replace\s+)?railtime\.local/openuem-extension(?:\s+v\S+)?\s*=>\s*(\S+)[^\r\n]*$')
    if ($replacements.Count -ne 1 -or $replacements[0].Groups[1].Value.Trim('"') -cne '../../extension') {
        throw "native/$component/go.mod must replace railtime.local/openuem-extension with exactly ../../extension."
    }
    $resolvedExtension = (Resolve-Path -LiteralPath (Join-Path (Split-Path -Parent $modulePath) '../../extension')).Path
    Assert-SamePath $resolvedExtension (Join-Path $serviceRoot 'extension') 'Native module replacement resolves outside the unified source tree.'
}

# Scan build/runtime source and configuration, not historical prose. The verifier
# itself contains the rejected legacy-path pattern and is intentionally excluded.
$runtimeExtensions = @('.go', '.ps1', '.sh', '.json', '.yml', '.yaml', '.toml', '.ini', '.conf')
foreach ($file in $sourceFiles) {
    if ($file.FullName.Equals($PSCommandPath, $comparison)) { continue }
    if ($file.Extension -notin $runtimeExtensions -and $file.Name -notin @('go.mod', 'go.work', 'Dockerfile', 'Makefile')) { continue }
    if ((Get-Content -LiteralPath $file.FullName -Raw) -match '(?i)\bdevice_app[\\/]+') {
        throw "Obsolete external source/runtime dependency remains: $($file.FullName)"
    }
}

Write-Output 'Unified OpenUEM layout verified: one App Git repository, ordinary native/reference sources, local extension references and no legacy external dependency. No files or Git state changed.'

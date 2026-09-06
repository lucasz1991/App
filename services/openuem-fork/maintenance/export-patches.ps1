[CmdletBinding()]
param([Parameter(Mandatory)][string] $NativeRoot)
$ErrorActionPreference = 'Stop'
$serviceRoot = Split-Path -Parent $PSScriptRoot
$native = (Resolve-Path -LiteralPath $NativeRoot).Path
$lock = Get-Content -LiteralPath (Join-Path $serviceRoot 'upstream-lock.json') -Raw | ConvertFrom-Json
$patchRoot = Join-Path $serviceRoot 'patches'
if (-not (Test-Path -LiteralPath $patchRoot)) { New-Item -ItemType Directory -Path $patchRoot | Out-Null }
$receipts = @()
foreach ($component in $lock.components) {
    if ($component.name -notin @('worker','agent','console')) { throw 'Unknown component.' }
    $checkout = Join-Path $native $component.name
    $head = & git -C $checkout rev-parse HEAD
    if ($LASTEXITCODE -ne 0 -or $head -ne $component.commit) { throw "Unexpected upstream base: $($component.name)" }
    $untracked = @(& git -C $checkout ls-files --others --exclude-standard)
    if ($untracked.Count -gt 0) { throw "Untracked files in $($component.name). Review source files and use git add --intent-to-add for this fork's files only before exporting." }
    $destination = Join-Path $patchRoot $component.patch
    # Git writes its binary-safe patch directly; no PowerShell text conversion.
    & git -C $checkout -c core.autocrlf=false diff --binary --full-index --no-ext-diff --output=$destination HEAD -- .
    if ($LASTEXITCODE -ne 0) { throw 'Patch export failed.' }
    $receipts += @{component=$component.name; upstream=$component.commit; patch=$component.patch; sha256=(Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()}
}
[IO.File]::WriteAllText((Join-Path $patchRoot 'checksums.json'), (@{format=1; patches=$receipts} | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
Write-Output 'Pinned source patches and SHA256 manifest exported. This does not commit, push, build or deploy anything.'

[CmdletBinding()]
param([Parameter(Mandatory)][string] $WorkspaceRoot)
$ErrorActionPreference = 'Stop'
$serviceRoot = Split-Path -Parent $PSScriptRoot
$workspace = (Resolve-Path -LiteralPath $WorkspaceRoot).Path
$extension = Join-Path $workspace 'App\services\openuem-fork\extension\go.mod'
if (-not (Test-Path -LiteralPath $extension)) { throw 'Workspace must contain App/services/openuem-fork/extension. Native replace paths intentionally use this layout.' }
$lock = Get-Content -LiteralPath (Join-Path $serviceRoot 'upstream-lock.json') -Raw | ConvertFrom-Json
$checksums = Get-Content -LiteralPath (Join-Path $serviceRoot 'patches\checksums.json') -Raw | ConvertFrom-Json
$native = Join-Path $workspace 'device_app\openuem-fork'
foreach ($component in $lock.components) {
    if ($component.name -notin @('worker','agent','console')) { throw 'Unknown component.' }
    $target = Join-Path $native $component.name
    if (Test-Path -LiteralPath $target) { throw "Target already exists: $target. Restore never resets or overwrites a checkout." }
    $patch = Join-Path (Join-Path $serviceRoot 'patches') $component.patch
    $receipt = @($checksums.patches | Where-Object component -eq $component.name)
    if ($receipt.Count -ne 1 -or $receipt[0].upstream -ne $component.commit -or (Get-FileHash -LiteralPath $patch -Algorithm SHA256).Hash.ToLowerInvariant() -ne $receipt[0].sha256) { throw 'Patch manifest mismatch.' }
}
if (-not (Test-Path -LiteralPath $native)) { New-Item -ItemType Directory -Path $native | Out-Null }
foreach ($component in $lock.components) {
    $target = Join-Path $native $component.name
    & git clone --depth 1 --branch $component.tag -- $component.repository $target
    if ($LASTEXITCODE -ne 0) { throw 'Upstream clone failed; retained for inspection.' }
    if ((& git -C $target rev-parse HEAD) -ne $component.commit) { throw 'Upstream tag moved. Refuse to apply patches.' }
    & git -C $target switch -c railtime/run-contract-v1
    if ($LASTEXITCODE -ne 0) { throw 'Local branch creation failed.' }
    $patch = Join-Path (Join-Path $serviceRoot 'patches') $component.patch
    & git -C $target apply --check -- $patch
    if ($LASTEXITCODE -ne 0) { throw 'Patch no longer applies.' }
    & git -C $target apply -- $patch
    if ($LASTEXITCODE -ne 0) { throw 'Patch apply failed; retained for inspection.' }
}
Write-Output 'Local maintained fork restored. Nothing published or deployed; run tests and build pinned sources before release.'

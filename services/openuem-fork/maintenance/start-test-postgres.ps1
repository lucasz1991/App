[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $BinaryDirectory,
    [Parameter(Mandatory)][string] $RuntimeDirectory,
    [ValidateRange(49152,65535)][int] $Port = 55479
)
$ErrorActionPreference = 'Stop'
$binPath = (Resolve-Path -LiteralPath $BinaryDirectory).Path
$runtimePath = [IO.Path]::GetFullPath($RuntimeDirectory)
if (Test-Path -LiteralPath $runtimePath) { throw 'Test runtime already exists. Inspect it; this script never overwrites a cluster.' }
if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) { throw 'Test port occupied.' }
New-Item -ItemType Directory -Path $runtimePath | Out-Null
$acl = [Security.AccessControl.DirectorySecurity]::new()
$acl.SetAccessRuleProtection($true, $false)
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$acl.SetOwner($sid)
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
Set-Acl -LiteralPath $runtimePath -AclObject $acl
# Generated throwaway local test credentials; never printed, committed or reused.
$bytes = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
$password = [Convert]::ToHexString($bytes).ToLowerInvariant()
$passwordFile = Join-Path $runtimePath 'init-password'
[IO.File]::WriteAllText($passwordFile, $password, [Text.UTF8Encoding]::new($false))
$dataPath = Join-Path $runtimePath 'data'
& (Join-Path $binPath 'initdb.exe') -D $dataPath -U railtime_test --auth-host=scram-sha-256 --auth-local=scram-sha-256 --encoding=UTF8 --locale=C --pwfile=$passwordFile
if ($LASTEXITCODE -ne 0) { throw 'Isolated initdb failed.' }
Remove-Item -LiteralPath $passwordFile
$process = Start-Process -FilePath (Join-Path $binPath 'postgres.exe') -ArgumentList @('-D', ('"' + $dataPath + '"'), '-h', '127.0.0.1', '-p', [string]$Port) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'postgres.stdout.log') -RedirectStandardError (Join-Path $runtimePath 'postgres.stderr.log')
$ready = $false
for ($attempt=0; $attempt -lt 30; $attempt++) {
    & (Join-Path $binPath 'pg_isready.exe') -h 127.0.0.1 -p $Port -t 1 | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready=$true; break }
    if ($process.HasExited) { break }
    Start-Sleep -Milliseconds 200
}
if (-not $ready) { throw 'Isolated test database did not become ready; inspect private logs.' }
$fixturePath = Join-Path $runtimePath 'connection.json'
[IO.File]::WriteAllText($fixturePath, (@{dsn="postgres://railtime_test:${password}@127.0.0.1:${Port}/postgres?sslmode=disable"; pid=$process.Id; data_directory=$dataPath; purpose='disposable local fork integration tests only'} | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
Write-Output "Disposable PostgreSQL ready on 127.0.0.1:$Port; private fixture: $fixturePath"
Write-Output 'Stop after tests using pg_ctl -D <exact data_directory> -m fast -w stop. No Windows service was registered.'

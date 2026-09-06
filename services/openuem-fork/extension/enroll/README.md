# Windows: new-only RailTime inventory enrollment

This helper is part of the existing `railtime.local/openuem-extension` module,
not another repository or installer framework. It installs **one reviewed native
Windows service**, explicitly in its RailTime dedicated inventory mode. It does
not configure Windows MDM, Microsoft accounts, remote control or execution HMAC
keys. Those capabilities require their separate reviewed configuration and real
acceptance tests. A running Windows service alone proves none of them.

## Exact local paths and authority

Paths come from Windows KnownFolder, not environment variables or caller input:

| Purpose | Fixed suffix below KnownFolder |
| --- | --- |
| Private enrollment | `ProgramData/OpenUEM/RailTimeEnrollment` |
| Native binary | `ProgramFiles/OpenUEM/Agent/openuem-agent.exe` |
| Native INI | `ProgramFiles/OpenUEM/Agent/config/openuem.ini` |
| SCM service | `openuem-agent`, LocalSystem, own process, quoted absolute EXE |

Prepare/install/start require an actually elevated Administrators token. The
helper never triggers elevation or attempts a UAC bypass. Run only after an
administrator explicitly reviews the requested action. There is no `--force`,
reset, uninstall, arbitrary destination, password, account creation or task
execution option. It never modifies an existing OpenUEM installation/service.

Created directories/files have protected DACLs granting full control only to
SYSTEM and Administrators, with Administrators ownership. Existing `OpenUEM`
parents must already satisfy this private policy; the helper will not loosen or
rewrite their ACL. Standard-users' access is intentionally denied. It rejects
reparse points in every path component, hardlinked files, remote paths, alternate
data streams, reserved names and ambiguous paths. Ancestor handles prevent
rename/delete while actions run; input file handles prohibit concurrent writes
or deletion. Trusted elevated administrators remain outside this threat boundary.

Private PKCS#1 key bytes are protected by these ACLs, not encrypted with a new
password. Protect the endpoint's disk and backups separately. **Never upload or
place the private tree in `.lmzdev`, a repository, browser, mail or support ticket.**

## 1. Prepare (no networking or service)

From an explicitly elevated PowerShell terminal, using the reviewed helper EXE:

```powershell
& 'C:\ReviewedTools\device-enroll.exe' --help
& 'C:\ReviewedTools\device-enroll.exe' prepare
```

The first command is read-only. `prepare` refuses an existing service, agent
directory or enrollment directory before generating anything. It generates a
new canonical UUIDv4 and local RSA3072 PKCS#1 private key, plus a signed CSR with
the exact DNS SAN/CN `railtime-device-<uuid>` and requested clientAuth EKU.

Only `agent-key.pem` and `request.json` are saved in the private tree. The JSON
request is also printed to stdout and contains **public data only**: version,
agent UUID, client identity, CSR PEM and public-key SHA256. Transfer only that
public request through the reviewed administrator enrollment channel. The
private key must remain on the endpoint. No service, agent task, account or
network connection is started by prepare. No synthetic agent is pre-admitted.

## 2. Review and return the public bundle

The administrator validates the CSR, explicit employee/device assignment and
admission scope, signs a client certificate with the private deployment CA, and
authorizes only the certificate-specific broker subject. This helper does not
perform those server actions. Return UTF-8 JSON with exactly these fields:

```text
version                       integer 1
agent_uuid                    UUID from request
client_identity               identity from request
tenant_id, site_id             positive integers approved by server admission
nats_url                      one tls://host:port; no user, query or path
ca_pem                        one self-signed root CA certificate, PEM
client_certificate_pem         one CA-signed leaf certificate, PEM
client_certificate_sha256      lowercase SHA256 of leaf DER
agent_sha256                  lowercase SHA256 of reviewed native agent EXE
```

The root directly signs the leaf in this initial bounded workflow; intermediate
chains are intentionally unsupported. The leaf must match the local RSA3072 key,
the exact one DNS SAN/CN, only clientAuth EKU and digitalSignature key usage, with
no additional DNS/IP/URI/email identities. Both certificate validity and chain
are checked. The broker endpoint's own server certificate will be verified by
the native agent against this same CA and its hostname.

The JSON is **not a signed manifest**. The leaf is CA-signed; the complete bundle
is independently pinned by a SHA256 received over an authenticated administrator
channel, along with the reviewed CA **DER** SHA256. The bundle pin authenticates
all metadata and the native binary hash, not just the certificate. Do not copy
the pins from an untrusted download alongside the bundle. A normal signed
software release channel can provide the EXE and these pins. Windows Authenticode
signing is not added by this helper and must not be claimed from the SHA256 check.

## 3. Install, still disabled/stopped

Supply real reviewed lowercase hashes, replacing the descriptive tokens:

```powershell
& 'C:\ReviewedTools\device-enroll.exe' install `
  --bundle 'C:\ReviewedTools\device-bundle.json' `
  --bundle-sha256 '<reviewed-exact-bundle-sha256>' `
  --ca-sha256 '<reviewed-ca-der-sha256>' `
  --agent 'C:\ReviewedTools\openuem-agent.exe'
```

All input bytes are read once and validated before installation writes. Unknown
or duplicate JSON fields, certificate/key/pin mismatches and binary hash changes
fail closed. No file is downloaded or executed during install. Public certificates
and the reviewed bundle are copied into the private tree; INI and binary are
written new-only into the fixed agent tree.

The INI explicitly includes `[RailTime] Dedicated=true`, the approved tenant/site
and TLS endpoint, absolute certificate paths, `RestartRequired=false`, 15-minute
inventory frequencies, and disabled SFTP/remote assistance with both ports zero.
No execution key or journal is fabricated. SCM creation happens after complete
configuration and creates the service **disabled and stopped**. `installed.json`
records exact fixed-file hashes (including the private key hash, not its bytes)
for subsequent local validation. Its contents are private, not a portable export.

## 4. Explicit start and acceptance

```powershell
& 'C:\ReviewedTools\device-enroll.exe' status
& 'C:\ReviewedTools\device-enroll.exe' start
& 'C:\ReviewedTools\device-enroll.exe' status
```

`status` never elevates, repairs, writes or contacts a server. It reports file
presence and SCM status only; under a standard-user account the private tree may
be inaccessible, producing a refusal instead of an inferred state.

`start` revalidates the protected installed receipt, all exact file hashes,
certificate validity/key/CA, generated INI, and fixed LocalSystem SCM identity.
Only a matching disabled, stopped installation is accepted. It switches that
service to automatic and starts it, waiting at most 30 seconds for SCM Running.
Normal failures attempt to disable and stop that same matching service. A
failure to confirm stopping is explicitly reported for manual intervention.

Running is only SCM evidence. Separately verify actual client-certificate broker
authentication, certificate-bound inventory delivery, server admission and
RailTime assignment. Output deliberately reports `execution_ready=false` and
does not claim remote-support availability. This is not full MDM provisioning.

## Failure/recovery boundary

Partial files and disabled services are preserved; repeated prepare/install is
refused, with no automatic deletion, overwrite or credential rotation. Inspect
privately before any separately authorized recovery. A power loss/process kill
is not a transactional rollback guarantee: confirm service state manually after
an interrupted start. An abandoned certificate should be revoked server-side
before any separately approved removal/re-enrollment. This helper has no such
destructive command and touches no unrelated agent or service.

## Verification

From `services/openuem-fork/extension` with the existing pinned Go toolchain:

```text
go test ./enroll ./cmd/device-enroll
go vet ./enroll ./cmd/device-enroll
go build -trimpath -o <reviewed-export>/device-enroll-windows-amd64.exe ./cmd/device-enroll
```

Tests use synthetic cryptographic material and in-memory filesystem/SCM doubles;
Windows-specific tests additionally inspect in-memory security descriptors and
reject a real hardlink inside a fresh temporary test directory. They do not
change machine ACLs, create device credentials, install services, request UAC or
start native tasks. A successful build/test is not an installed-device proof.

Official implementation references:

- [Microsoft KnownFolder API](https://learn.microsoft.com/en-us/windows/win32/api/shlobj_core/nf-shlobj_core-shgetknownfolderpath)
- [Microsoft service creation and quoted binary path](https://learn.microsoft.com/en-us/windows/win32/api/winsvc/nf-winsvc-createservicew)
- [Microsoft security descriptor format](https://learn.microsoft.com/en-us/windows/win32/secauthz/security-descriptor-string-format)
- [Microsoft effective-token membership check](https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-checktokenmembership)
- [Microsoft hard links and junctions](https://learn.microsoft.com/en-us/windows/win32/fileio/hard-links-and-junctions)

Pinned Go x/sys v0.41.0 source is used for Windows API bindings; no new dependency
or module has been introduced.

# RailTime execution v1 — opt-in Windows fork

Local implementation and synthetic tests only. No enrolled device, real key,
Windows service installation or production NATS execution is proven by this
checkout. The unchanged upstream agent/MDM requirements still apply.

The Windows native PowerShell runner now uses `-NoProfile -NonInteractive
-ExecutionPolicy RemoteSigned` on its child process. It never changes or restores
the persistent CurrentUser/LocalMachine execution policy; administrator Group
Policy retains precedence. SFTP certificates are loaded only while SFTP is enabled
(including configuration reloads). An enabled SFTP configuration with a missing or
invalid certificate fails closed. Neither change enables SFTP or remote support.

## Enrollment boundary

### Dedicated Windows inventory pilot

The new explicit `[RailTime] Dedicated=true` INI option selects a separate
Windows-only startup path. It defaults to false for legacy installations; errors
in dedicated configuration never downgrade to upstream behavior. A single
serialized inventory/reconnect job replaces legacy NATS commands, JetStream,
remote-configuration retrieval, pending deployment acknowledgements, automatic
WinGet profiles, SFTP and VNC/RustDesk listeners. Native inventory is collected
from this computer, wrapped in `railtime.inventory.v1`, and published only to the
device's exact report subject. Receipts must match protocol, report, device,
tenant and site; legacy plain acknowledgements, malformed responses and timeouts
are not success. Waiting/enabled/disabled server receipts do not rewrite local
configuration or grant local command authority.

The installer must create the native `config/openuem.ini` adjacent to the binary
and explicit absolute certificate/key paths in protected directories. The INI,
its containing directory, certificate files and their containing directories must
allow only SYSTEM, Administrators and the final service identity, without reparse
ancestors. Canonical nonzero UUID and positive canonical TenantID/SiteID are
required. Set Enabled=true, Debug=false, RestartRequired=false, SFTPPort=0,
VNCProxyPort=0, SFTPDisabled=true, RemoteAssistanceDisabled=true, and positive
DefaultFrequency/ExecuteTaskEveryXMinutes (1..1440 minutes). Use a single explicit
`tls://host:port` NATSServers value without credentials, path, query or fragment;
omit WebSocketPort. AgentCert, AgentKey and CACert are required under Certificates.

The client key must be private PKCS#1 RSA PEM (at least 2048 bits), with a matching
clientAuth certificate chained to the configured CA and exactly the DNS SAN
returned by `inventory.ClientIdentity(UUID)`. TLS validates the broker hostname
and private CA; server discovery and the upstream WebSocket fallback are disabled.
The broker must map this certificate identity to the exact per-device inventory
subject. No shared agent credential is acceptable. A public, verified endpoint
and the server's reviewed enrollment binding remain separate deployment gates.
NATS request/reply uses the device-specific `_INBOX.railtime.<UUID>` prefix;
grant this identity subscriptions only to `_INBOX.railtime.<UUID>.>` (and its
exact signed command subject after separate execution enrollment), never the
shared `_INBOX.>` namespace. Worker response publishing remains dynamically
limited to replies to requests it actually received.

Without execution.json, the dedicated mode remains inventory-only. Adding valid
protected v1 enrollment and its existing journal enables only the signed v1 path
described below; it does not enable a legacy command listener. No installation,
CSR issuance, service creation, UUID generation or INI rewrite is performed by the
dedicated agent. Restart the stopped service to apply a reviewed INI change.

The native Windows service subscribes to the versioned command subject only when
its protected enrollment exists. Outside dedicated mode, legacy subjects retain their old behavior; v1
does not fall back to legacy execution. The server must reject profiles that also
apply automatically (`ApplyToAll` or tags). A shared mutex serializes the periodic
profile path, manual legacy task path and v1 configuration execution, but it does
not convert legacy messages into deduplicated v1 requests.

Use the OS-known ProgramData directory, normally
`C:\ProgramData\OpenUEM\RailTimeExecution`. An administrator must provision this
directory and its parents without junctions/symlinks. Disable inherited ACLs on
the enrollment directory; allow full control only for SYSTEM and Administrators
(plus the exact service account if a dedicated service identity is used). Files
must inherit only those private ACLs. Standard employee accounts must have no
access. The service checks owner, every allowed ACE and all reparse ancestors.
The temporary synthetic tests additionally allow their own process identity.

Provision `execution.json` with fields `enabled`, `agent_id`, `tenant_id`,
`site_id`, `key_id`, `ledger_id`. The IDs must match the native agent's enrolled
UUID, tenant and site. `ledger_id` is a stable enrollment identity, not a password.
Keep `enabled: false` until enrollment has been reviewed. No environment variables
are read for this configuration. `agent-hmac.key` is a separate protected file
containing exactly 64 hexadecimal characters (a unique 256-bit key per agent).
The worker must hold the corresponding per-device key and key ID. No keys or
sample usable credentials are provided or generated by this repository.

With the service stopped, valid protected configuration and an explicitly
approved key already provisioned, set enabled and run the native service binary
under its intended service identity (normally LocalSystem) once with
`--railtime-init-ledger`. An interactive administrator's personal SID is not the
same identity: a journal owned by that account is intentionally rejected by a
subsequent LocalSystem process. The operation only creates a NEW private `journal`
directory and writes its enrollment identity. It refuses an existing directory;
it does not start a service or execute a profile. Start the service separately
under its intended identity only after that operation and the ACL review succeed.

Never delete/reinitialize the journal to repair an enrolled agent. Missing,
corrupt or mismatched storage disables v1 execution. Restore the complete private
backup or investigate uncertainty before approving a new enrollment. Rotate keys
only with stopped service and coordinated worker changes; changed enrollment is
rejected by a running agent. Preserve the journal across upgrades and rollback.

## Execution and recovery

Every accepted command is authenticated and durably stored with synchronous
Badger writes before the signed `accepted` response. Run, RailTime command and
correlation IDs stay bound to the immutable payload digest. The journal never
evicts replay records; at 10,000 runs new work is rejected pending an explicit
retention/archival decision. It is separate from upstream's disposable SFTP DB.

Before native `ApplyConfiguration`, the agent verifies exact snapshot task IDs,
supported resource types, current enrollment, device enabled state and the common
executor lock. It records `executing` before any native configuration side effect.
The signed snapshot itself is passed to native execution; no mutable profile is
fetched at execution time. Busy work remains prepared until free or expired.
Expired prepared work produces a durable failure without touching the device.

Completed results are stored with a stable event ID. Transport failure retries
only the saved result. A signed `persisted` acknowledgement must match run,
digest and event exactly. An interrupted `executing` record becomes `uncertain`
on restart and is never automatically executed again. A complete expected task
manifest is required before reporting success; panic/incomplete native reports
cannot prove success. Raw task stdout/stderr is not exported through v1 because
it may contain secrets; existing upstream local logging remains unchanged.

Service shutdown stops accepting new v1 commands and cancels future execution.
An already running native task is not claimed to be cancelled; forced process
termination leaves durable uncertainty. This is at-most-once execution per run,
not a distributed claim that arbitrary Windows side effects are transactional.

## Local verification / remaining release gates

Run `go test ./internal/agent ./internal/service/windows` on Windows; the shared
extension separately runs `go test ./agentexec`. Tests use disposable private
directories, synthetic signatures, in-memory test-only TLS keys/certificates and
fake native callbacks; they do not execute PowerShell, connect to a real broker,
enroll a device or create deployable credential files.

Before a live pilot: review license/upstream obligations, package signing and
service identity/ACLs; back up the existing native task state and new journal;
deploy the matching worker/database protocol migration; use NATS TLS with narrow
per-agent subject permissions; then test one explicitly approved harmless native
profile, offline result retry and restart uncertainty on an isolated pilot. No
public service route, fleet rollout or destructive command is authorized merely
by building this checkout.

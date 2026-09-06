# Native OpenUEM Worker run API

This package is linked into the maintained OpenUEM **agent worker**. It is not a
sidecar that bypasses the native model or a browser-session adapter. RailTime
uses the authenticated API; only the native worker accesses its own PostgreSQL
ledger and broker.

## Configuration and trust boundary

The existing worker gets an opt-in `--railtime-config /absolute/private/file.json`
flag. Omitting it disables the extension. A selected invalid file fails closed.
There are no new environment variables, default credentials, or automatic device
enrollment. For a file-only start include `native` with `database_url`,
`nats_servers`, `ca_certificate`, `client_certificate`, `client_private_key`.
The file is read and frozen before native prerequisite validation. Native CLI/
environment credential overrides cannot be mixed with that protected startup
snapshot. Legacy no-file startup still accepts the existing upstream flags, but
file-only startup needs no secret command arguments or environment variables.
Remote PostgreSQL requires `sslmode=verify-full`; NATS must use explicit `tls://`
or `wss://` URLs and certificate authentication. Certificate paths are absolute.

The JSON schema consists of:

- `enabled`: explicit boolean.
- `listen`: exact loopback IP and port; default `127.0.0.1:9441`.
- `tls_certificate` and `tls_private_key`: optional **pair** of absolute paths.
  With neither set the internal hop is HTTP on loopback only. Public access must
  terminate TLS at the managed reverse proxy; no public port binding is allowed.
  With both set the internal listener also uses TLS 1.2 or newer.
- `command_ttl_seconds`: default 900, valid range 30–86400.
- `principals`: objects with `id`, SHA-256 hex `bearer_sha256`, integer
  `tenant_id` / `site_id`, explicit `agent_ids` and `profile_ids` allowlists.
  The bearer itself stays in RailTime's encrypted settings, not in this file.
- `device_keys`: one object per enrolled agent with `agent_id`, unique `key_id`
  and `key_base64` containing exactly 32 random bytes. Duplicate key material
  across devices is rejected. These are secrets, unlike bearer hashes.

On Linux the file must be regular, owner-only, owned by the worker user or root;
all ancestors must have trusted ownership and not be group/world writable.
Symlinks are rejected. Native worker file loading on Windows is explicitly
unsupported until ACL verification is implemented; tests can construct synthetic
configuration in memory, which is not a production-file acceptance claim.

Never put the real file or its content in Git, logs, diagnostics, screenshots,
`.lmzdev` or command arguments. Key rotation requires a coordinated enrollment
change; replacing a key while old runs remain outstanding deliberately fails
closed. Startup does not generate, replace or restore keys.

## API

All endpoints require `Authorization: Bearer …`, return JSON with `no-store`,
and never accept console cookies as authentication.

| Endpoint | Contract |
|---|---|
| `GET /railtime/v1/health` | `protocol`, `ready`, `storage_ready`, `broker_ready`, `capabilities:["profile_runs_v1"]`; 200 or 503. This verifies the API's storage structure/write privileges and broker connection, **not** a Windows agent or completed device execution. |
| `POST /railtime/v1/runs` | `command_id`, `correlation_id`, `agent_id`, `profile_id`. **Correlation ID is the idempotency key**, scoped to principal. New committed run/outbox: 202; identical prior receipt: 200. Reusing either identifier with different inputs: 409. |
| `GET /railtime/v1/runs/{run_id}` | Immutable identity, snapshot digest, expected task names, state and optional redacted result. Principal plus tenant/site/device/profile scope is rechecked. |

The caller cannot submit executable contents, tenant/site IDs, NATS subjects or
arbitrary URLs. The native worker loads the existing enabled Windows agent and
profile through its own model, requires exactly one matching site/tenant, then
materializes a native WinGet configuration in a repeatable-read transaction.
The accepted snapshot, task manifest and digest never follow later profile edits.

V1 profiles must be dedicated explicit-run profiles: `apply_to_all=false` and no
legacy tag associations. Enabled unsupported/mixed-platform tasks are rejected,
not silently omitted. This keeps legacy periodic execution separate from the
v1 at-most-once journal. The ordinary native console/profile paths remain legacy
and are not evidence of v1 execution results.

## Persistence and retries

The native worker creates only its additive `railtime_execution_*` tables under
a migration advisory lock. Startup never drops legacy OpenUEM columns/indexes,
and an unknown extension schema version is rejected. Production DDL remains a
review/backup gate; additive Ent schema initialization is not migration-drift
verification or permission to downgrade a database.

Request acceptance commits run and outbox together. A leased dispatcher sends
the same signed bytes over a versioned per-agent NATS subject; timeout merely
reschedules. A signed accepted reply is still **not** success, including replies
with an EventID from a terminal duplicate. Only a validated signed result matching
run, device, digest and expected tasks can commit a terminal outcome.

Result validation and immutable result write commit before the positive signed
`persisted` receipt. Lost receipts are safe to replay. Results with a different
payload for an already terminal run are rejected. Native stdout/stderr remain
server-side; public polling removes output and replaces error text with stable
codes. `uncertain` remains uncertain and must not unlock automatic re-execution.

Offline, rejected or unauthorized dispatch is retained for investigation/retry;
it is never converted to success. Operator cancellation/reconciliation, retention,
backup/restore of enrollment keys and production monitoring are separate required
operational procedures, not implied by the health endpoint.

## Verification boundary

The Worker tests accept a protected **test-only** JSON connection fixture through
`-railtime-pg-fixture=<absolute-path>` and refuse any endpoint other than the
isolated `127.0.0.1:55479` test service. They create and remove random test schemas.
They exercise native Ent/WinGet materialization, PostgreSQL transaction failures,
concurrent idempotency, leases, immutable results and the real agent journal.
The synthetic executor never runs device scripts. Direct helper transport is not
a substitute for real TLS/NATS, a packaged service restart, or Windows acceptance.

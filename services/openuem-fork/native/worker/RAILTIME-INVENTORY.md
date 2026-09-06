# Certificate-bound Windows inventory and admission

The protected worker JSON may include `inventory_enrollments` entries with the
exact `agent_id` (canonical lowercase nonzero UUID), `tenant_id` and `site_id`.
Each device's NATS client certificate identity must be restricted by the broker
to publishing only `railtime.inventory.v1.report.<agent_id>`. The worker cannot
authenticate certificates from message payloads; broker ACLs are mandatory.

An explicit RailTime configuration disables **all legacy worker consumers** in
both provisioning-only and execution modes. Inventory reports use the shared
`extension/inventory` protocol, bounded strict JSON, fresh collection time and
matching subject, envelope and native payload identity/scope. Only Windows is
accepted by this slice. No fallback site or automatic admission is used.

## Persistence and retries

Before opening subscriptions, startup adds four tables atomically:
`railtime_inventory_schema`, `railtime_inventory_reports`,
`railtime_inventory_current`, and `railtime_inventory_admissions`. They reference
existing native agents/sites/tenants; startup creates no devices. Partial ledger
presence fails closed. Native schema creation is the existing worker startup path.

A genuine first report creates a native `WaitingForAdmission` agent. All native
hardware/software observations, the latest complete native JSON snapshot and
immutable receipt are committed in one transaction. The raw snapshot remains in
the protected native database and is not logged or returned to the device.
Release claims are retained as observations only: no downloads or trusted release
association changes occur. Existing status, manual fields, site, task state and
remote-service/certificate authorization flags are never copied from reports.

Per-agent transaction locks serialize reports/admission. Identical report-ID
retries return the original committed receipt; conflicting reuse is refused.
New reports must be strictly newer than the latest accepted timestamp, retaining
nanosecond precision. No acknowledgement is sent for validation/write failures.
A later admission does not rewrite earlier receipts; a fresh report observes it.

## Explicit protected-file admission

`openuem-worker admit-inventory --config /absolute/private/admission.json`

The operator must provide these six JSON fields:

| Field | Required value |
| --- | --- |
| `worker_config` | Absolute path to the existing protected worker configuration |
| `request_id` | Unique canonical lowercase nonzero UUID for this approval |
| `agent_id` | Exact configured device UUID |
| `tenant_id`, `site_id` | Exact configured existing scope (integers) |
| `reason` | Nonempty, single-line approval reason, at most 1000 bytes |

Both configuration files require Linux owner-protection checks. The command
opens only the configured database, with no environment overrides, and requires
an actual existing waiting native row plus its committed certificate-bound
report. It atomically writes an audit event (request, report, scope, reason and
OS operator UID) and changes only native admission status to `Enabled`.
An identical request is idempotent while still enabled; it cannot re-enable a
subsequently disabled device. It never creates certificates/credentials/profiles,
starts a worker, connects to NATS or executes an action on the endpoint.

Admission alone does **not** enable execution or remote support. Execution keys,
explicit agent/profile authorization, validated profiles, transport and endpoint
readiness are separate gates. `provisioning_only=true` still returns unready API
health and rejects execution after inventory/admission has succeeded.

## Verification

Native `go test ./... -count=1 -railtime-pg-fixture <private fixture>` exercises
real isolated PostgreSQL schemas on loopback port 55479, including atomic native
inventory, rollback, concurrency, nanosecond staleness, immutable retries,
preserved server fields/scope and report-backed admission. Receiver/config tests
reject malformed/forged inputs before database access. Protected Linux file
success is platform-specific; on Windows the tests verify fail-closed rejection.

# Explicit native database bootstrap

The fork provides a bounded first-install command, separate from worker startup:

```text
openuem-worker bootstrap --config /srv/railtime-openuem/worker/bootstrap.json
```

The administrator must first provision a **dedicated PostgreSQL database and
non-superuser database owner**. Do not point this command at RailTime's database
or an existing third-party OpenUEM installation. It never creates database roles,
passwords, device records, executable profiles, user accounts or certificates.
It does not start a service, access NATS or send mail.

## Protected configuration

The JSON object has exactly four required string fields:

| Field | Meaning |
| --- | --- |
| `database_url` | Explicit PostgreSQL URL with user, protected password, host, port and dedicated database name. Use the actual separately provisioned loopback port. |
| `tenant_name` | Administrator-selected organization name; no default tenant identity is invented. |
| `site_name` | Administrator-selected real organizational site name. |
| `site_domain` | Lowercase DNS name for this site, without scheme, path, port or wildcard. |

Only `sslmode`, `sslrootcert` and `connect_timeout` database URL options are
accepted. `sslmode=verify-full` is required except for explicit loopback, where
`sslmode=disable` is allowed. Connection fallback hosts, an implicit database,
arbitrary search paths, duplicate JSON/URL fields and PostgreSQL `PG*` environment
configuration are rejected. The CLI pins the database schema to `public`.

The file and its path use the same Linux protection rules as worker startup:
owner-only regular file, trusted worker/root ownership, trusted non-writable
ancestors, no symlinks. Keep the file outside Git, web-accessible directories,
logs and `.lmzdev`. No secret belongs in an argument or environment variable.
The operation is bounded to 90 seconds and reports safe error categories.

## Write and repeat boundaries

An initial run requires an empty owned schema. A transaction-scoped advisory
lock serializes bootstrap attempts. **All native Ent schema DDL and metadata
inserts are in one PostgreSQL transaction**, including Ent's internal migration
calls. Any failure rolls back both schema and rows.

It creates exactly:

- one default tenant and one linked default site using the supplied names;
- one global settings row and one tenant settings row;
- auto-admission **off**, SFTP **off**, remote assistance **off**, remote-agent
  detection **off**, and remote-assistance PIN requirement **on**;
- no country/contact identity guess, mail credentials or default administrator.

Successful output contains only `status`, actual tenant/site/settings IDs and
`provisioning_only: true`. `status: created` confirms initialization, **not an
enrolled device or working MDM**.

An immediate identical repeat validates the pinned native table/column names,
exact tenant/site/settings structure, unchanged settings and empty remaining
native tables. It returns `status: unchanged` without migration or writes.
Foreign data, partial state, changed settings, extra tables/columns/functions/types,
enrolled devices, profiles or execution-ledger tables cause refusal. Bootstrap
is deliberately not a complete migration/constraint audit, upgrade, recovery or
reconfiguration command; never clear
the database to make a repeated bootstrap pass.

## Next: infrastructure-only worker

After bootstrap, use the actual returned tenant/site IDs for a real health-only
RailTime service principal. The separate protected worker startup JSON uses
`enabled: true`, `provisioning_only: true`, its native database/NATS/certificate
configuration, and **empty** device-key and principal agent/profile allowlists.
Do not invent device or profile IDs just to pass startup checks.

```text
openuem-worker agents start --railtime-config /srv/railtime-openuem/worker/config.json
```

Use a private writable service working directory for upstream's `PIDFILE` and
restrict its umask. In provisioning-only mode the fork does not subscribe to
legacy reporting/deployment/profile subjects or start an execution dispatcher.
Its authenticated loopback health endpoint intentionally reports `ready: false`,
`provisioning_only: true`, empty capabilities and HTTP 503, even when storage and
broker are available. Commands remain rejected. Real per-device enrollment,
NATS permissions, key provisioning and a separately accepted Windows pilot are
still required before leaving that mode.

## Tests

In this module, `go test ./internal/common ./internal/models` runs pure validation
tests. Real PostgreSQL tests require the explicit private
`-railtime-pg-fixture=<absolute path>` fixture and reject every endpoint except
the isolated local `127.0.0.1:55479` test service. They create and remove randomly
named synthetic schemas; they must never point at a production database.

Covered with actual PostgreSQL: initialization, read-only identical repeat,
concurrent bootstrap, preservation on foreign/partial/changed/populated state,
schema drift rejection and rollback of both Ent DDL and inserted rows.

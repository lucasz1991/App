# Dedicated OpenUEM infrastructure on the Plesk host

This directory is one bounded production-deployment slice: **dedicated PostgreSQL
17.11 and a real NATS 2.11.17 TLS broker**, both persistent and isolated from the
RailTime application's MySQL, Redis and existing services. It is not a fixture
server or a claim that a Windows agent has been enrolled.

| Component | Host endpoint | Persistence | Runtime identity |
|---|---|---|---|
| PostgreSQL | `127.0.0.1:15432` via systemd proxy | `/srv/railtime-openuem/postgres/data` | container UID/GID54321 |
| NATS mTLS | `127.0.0.1:14443` via systemd proxy | `/srv/railtime-openuem/nats/data` | container UID/GID54322 |
| Native Worker API | `127.0.0.1:9441` | separate protected worker state/config | separate host service account |

The native Worker is deliberately **not** started by this Compose file. Its
empty-database bootstrap, protected file startup and operating-system service are
separate reviewed steps. No Console, certificate-manager worker, notification
worker or automatic enrollment runs here.

## Internal network and host-loopback access (Docker29 correction)

Docker29.8.0 on the actual Plesk host kept requested `PortBindings` but did not
create a publication for an internal-only endpoint. PostgreSQL was healthy
inside its container, while host15432 refused connections. Consequently this
configuration now declares **no Docker published ports**. The internal network
and absence of container Internet egress remain intact.

The reviewed dedicated network uses subnet `172.25.0.0/16`, gateway172.25.0.1,
PostgreSQL172.25.0.10 and NATS172.25.0.11. These addresses were chosen after
inspecting the target host; before using the files on another host, verify
conflicts against its networks and routes. Do not silently reuse an occupied
network or assign an address already owned by another container.

Four explicit systemd units provide transparent TCP forwarding:

- `railtime-openuem-postgres-proxy.socket/.service`:127.0.0.1:15432 →172.25.0.10:5432.
- `railtime-openuem-nats-proxy.socket/.service`:127.0.0.1:14443 →172.25.0.11:4222.

Services run with `DynamicUser`, no capabilities, strict filesystem protection
and IP filtering allowing only local clients and the exact backend. TLS still
terminates at NATS; the proxy does not decrypt it or hold certificates. Verify
the host's `/usr/lib/systemd/systemd-socket-proxyd` and the loaded units before
activation. These are host socket proxies, **not** host-network containers or
an external Docker bridge. The Worker unit requires and starts after both proxy
sockets. Socket readiness is not PostgreSQL/NATS application readiness.

For an already initialized data directory, changing static addresses requires
recreating only these exact containers/network as reviewed, preserving their
bind-mounted data, secrets and identities. Do not rerun initialization or use
`down -v`. The parent deployment operation must verify that no unrelated
containers belong to this dedicated network before any recreation.

## Files, ownership and credentials

Install reviewed source configurations as root-owned0644 files in
`/srv/railtime-openuem/config/`: `postgres-start.sh`, `postgresql.conf`,
`pg_hba.conf`, `postgres.passwd`, `postgres.group` and `nats.conf` (from `nats.conf.example`). The startup script is
invoked by `/bin/sh`, so executable permission is not required. Preserve LF
line endings. The supplied NATS file defines exactly one infrastructure Worker
identity; its client certificate must contain DNS SAN `railtime-openuem-worker`.

Provision these **private operator-supplied files**, never in Git, `.lmzdev`,
browser output, process arguments or environment variables:

- `secrets/postgres-bootstrap-password`: UID54321,0400/0600, at least24bytes.
- `secrets/nats/{ca.crt,server.crt,server.key}`: UID54322,0400/0600; containing
  directory UID54322,0700. Server certificate requires `serverAuth` and IP SAN
  `127.0.0.1` for the configured local Worker connection. The CA private key must
  not be mounted in either container.
- The separate Worker's client certificate/key, CA trust, database connection
  file, bearer hash and any later per-device keys stay in its protected private
  directory. Never reuse one device key or private client key across devices.

The runtime root is root-owned0711 (or0700 if no native Worker needs traversal).
Intermediate directories are root-owned and not group/world writable. Data
directories must already exist: PG54321:543210700; NATS54322:543220700. Mounts use
`create_host_path:false`, avoiding silent root-owned directory creation when a
file is missing. Compose does not generate keys/passwords or create host users.

No new environment variables or `.env` entries are required. Docker's official
base images still contain their upstream built-in environment; no installation
secret or RailTime setting is supplied through it. The PostgreSQL entrypoint is
overridden to use explicit command arguments and protected password-file paths.

UID/GID54321 and54322 must be verified unused on the host before initialization.
Do not reuse Plesk administrator/subscription IDs (the upstream PostgreSQL UID999
and an arbitrary UID10001 collide on this server). The two static, password-free
NSS files are mounted read-only at the PostgreSQL container's `/etc/passwd` and
`/etc/group`, mapping `postgres` to54321 for `initdb` and local peer authentication.
They do not change the host's account files. Verify `id`/`getent passwd postgres`
inside the pinned image before the first explicit initialization.

## Explicit initialization, never on `up`

First verify/pin the exact source package, install the reviewed private files,
and make sure this is a **new dedicated empty PostgreSQL data directory**. Pull
only the exact images in `images.lock.json`. Registry lookup and digest pins were
checked on2026-09-06 for Linux/amd64; updates require a reviewed pin change,
backup/restore and regression, never `latest` or automatic major upgrades.

From this deploy directory on the Linux host:

```sh
python3 -B -m unittest discover -s tests -v
python3 -B preflight.py --phase postgres-init
docker compose -f compose.yaml run --rm --no-deps postgres init
docker compose -f compose.yaml up -d postgres
```

Separately install the four reviewed proxy units and Worker unit as root-owned
0644 files under `/etc/systemd/system`; refuse unexpected pre-existing versions
or unreviewed drop-ins. Run `systemd-analyze verify` for the exact unit files,
then reload systemd configuration. Activate only the PostgreSQL proxy socket
before the native database bootstrap:

```sh
systemctl start railtime-openuem-postgres-proxy.socket
```

Confirm both the exact127.0.0.1:15432 listener and a successful authenticated
connection to the dedicated database before invoking the native bootstrap.

The explicit `init` creates only the PostgreSQL cluster with its administrative
role and checksum-enabled storage. It refuses nonempty data. The normal `serve`
command refuses an uninitialized or non17 cluster; it never repairs, erases or
reinitializes a volume.

Before Worker bootstrap, provision the database and a dedicated login role
named `openuem`, with `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION` and
ownership of only the dedicated `openuem` database/schema. This is a separate
explicit administrator SQL operation using a private input file or protected
interactive session; no password belongs in CLI arguments/history or this
documentation. PostgreSQL TCP access permits only this role/database via SCRAM;
the cluster administrator is limited to local container peer authentication.
The Worker's connection must use that restricted role, **not** `postgres`.

Then perform the native Worker's reviewed empty-schema bootstrap. It must not
invent an agent, profile or device identity. With real CA/server/Worker files
available, validate NATS before starting it:

```sh
python3 -B preflight.py --phase start
docker compose -f compose.yaml run --rm --no-deps nats -t -c /etc/nats/nats.conf
docker compose -f compose.yaml up -d nats
systemctl start railtime-openuem-nats-proxy.socket
python3 -B preflight.py --phase status
```

Run `docker compose config`/inspection only through safe summaries if production
overrides are introduced later; do not dump secret values. No provider is enabled
and no device command is dispatched by these steps. Start the native Worker
separately after all bootstrap prerequisites and protected-file checks pass.

## Broker trust and least privilege

NATS requires mTLS **and** certificate-to-user mapping. A CA-signed certificate
without an explicit configured identity is not admitted. The Worker user is
restricted to native Worker subscriptions and the versioned execution subjects;
response permissions allow a single bounded reply without arbitrary inbox
publishing. There is no anonymous/token fallback and no unrestricted user.

Native clients currently expect NATS INFO followed by TLS; enabling
`handshake_first:true` would break them. Do not equate an unsuccessful raw
`openssl s_client` test against that port with broken TLS. Verify with an actual
NATS client and show both authorized success and rejection without a client
certificate and with an unknown certificate identity.

The supplied configuration has no enrolled agent. Add each real certificate
identity and **exact** per-device command/result subjects only in the enrollment
step. A changed NATS configuration intentionally fails the baseline preflight
until its new permission contract is reviewed; do not disable that check to
obtain a green status. Native legacy reporting/profile subjects also need a
separate enrollment audit; this baseline is not blanket permission for them.

JetStream has persistent storage available, but v1 command delivery uses Core
NATS plus the PostgreSQL durable outbox and agent journal. Core NATS messages are
not made persistent merely by enabling JetStream. Device identities, outcomes
and uncertainty rules remain in the native Worker/agent contracts.

## Exposure and acceptance

Both systemd host sockets bind exclusively to127.0.0.1; the container bridge stays
internal and has **zero requested and zero actual Docker publications**.
No Plesk proxy, DNS, public firewall rule or website listener is changed. A remote
Windows agent cannot connect yet: provide a separately authorized VPN/private
route or reviewed public mTLS endpoint before enrollment. Never expose the
PostgreSQL port or Worker API by changing only the bind address.

Preflight does not explicitly start services or change files/configuration. It
checks paths/owners/modes/links, exact baseline config, Compose isolation, pinned
images and (in status mode) actual network IPAM/peers, static container addresses,
labels, image IDs, mounts, users, absent Docker publications, loaded proxy files,
unreviewed drop-ins and actual host listening addresses. Status TCP probes can
activate an already configured local proxy and check both front and backend
TCP availability. This side effect is reported explicitly. Missing/wrong/public
listeners are rejected; matching requested configuration alone is insufficient.
Output contains only status codes, not secrets. The checks do not prove DB
credentials, certificate validity, an authenticated broker exchange, end-to-end
application forwarding or a running Worker. `pg_isready` is
only a PostgreSQL readiness probe, not a successful database login.

Required real acceptance: restricted-role DB connect; broker authentication and
negative access tests; protected native Worker health with storage/broker ready;
restart with unchanged persistent identity; backup/restore into an isolated
clone; then the separately authorized actual Windows-agent pilot. Record these
as separate evidence, not as a consequence of static/unit tests passing.

See [backup-restore.md](backup-restore.md) before writing production data.

## Official sources checked

- [PostgreSQL17 initdb](https://www.postgresql.org/docs/17/app-initdb.html) and [authentication rules](https://www.postgresql.org/docs/17/auth-pg-hba-conf.html).
- [Official PostgreSQL image source](https://github.com/docker-library/postgres/tree/2603e26e245e558218728ee14e0a42dcb020dc7f/17/bookworm), including UID999.
- [NATS TLS/authentication](https://docs.nats.io/learn/security/encryption), [subject permissions](https://docs.nats.io/learn/security/authorization) and [exact2.11.17 parser](https://github.com/nats-io/nats-server/blob/v2.11.17/server/opts.go).
- [Docker Compose service definitions](https://docs.docker.com/reference/compose-file/services/).
- [Docker network/IPAM definitions](https://docs.docker.com/reference/compose-file/networks/) and [host/internal bridge behavior](https://docs.docker.com/engine/network/port-publishing/).
- [systemd255 socket-proxyd documentation](https://github.com/systemd/systemd/blob/v255/man/systemd-socket-proxyd.xml).

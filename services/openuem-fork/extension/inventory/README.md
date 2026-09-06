# Certificate-bound inventory v1

This channel carries observations, never commands or account passwords. It is
independent of the signed execution contract. An inventory receipt is not a
remote-control or execution readiness signal.

The operator records each real device's canonical UUID, tenant and site in the
protected Worker's `inventory_enrollments` array. This scope must match an
existing API principal's tenant/site. Entries grant no execution permission;
`provisioning_only` can stay true, and its API continues reporting HTTP503 with
no execution capabilities. An empty list accepts no device reports.

The device generates its own private key locally. Its reviewed clientAuth
certificate has the single DNS identity `railtime-device-<UUID>`. NATS must use
`verify_and_map` and grant that identity publication only to
`railtime.inventory.v1.report.<UUID>`. A shared client certificate or wildcard
device publisher defeats this boundary. Only the Worker can subscribe to these
reports and send the request's reply. Legacy `report` permission is not used.

The envelope binds protocol, unique report ID, agent UUID, explicit tenant/site,
collection time and the native JSON report. The Worker additionally compares
the native report's identity to the envelope and configured subject. Envelope
size is capped at8MiB; duplicate keys (including nested payload keys), unknown
envelope fields, invalid IDs, JSON nesting over64 and collection times outside
15minutes past/5minutes future are rejected. Device/server clocks need to agree.

The Worker persists before returning an exactly correlated receipt with status
`waiting_for_admission`, `enabled` or `disabled`. The device must not accept the
upstream plain-text ACK. First observation does not admit or enable a device;
admission and any later execution enrollment remain separate operator actions.
Reports cannot overwrite server-owned admission or employee/site assignments.

## Current deployment boundary

Shared protocol tests are synthetic. Worker PostgreSQL tests use an isolated
test database. They do not prove a real Windows service has been installed or
that a public certificate-verified broker endpoint is available. Certificate
issuance, exact per-device broker ACLs, actual report/admission evidence and the
Windows administrator installation must be verified separately.

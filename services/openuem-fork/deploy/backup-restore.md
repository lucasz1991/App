# Backup and restore gate

The deployment files do not take a backup automatically. A running container or
an archive with a matching checksum is not restore proof. Keep protected copies
outside the App source and outside public Plesk directories. Restrict owner/mode,
encrypt offsite backups, and assign retention/monitoring to an operator.

## What must be backed up together

- Dedicated PostgreSQL database, its extension ledger/outbox and native model;
  database roles/grants as needed for rebuilding the isolated cluster.
- NATS persistent data and exact configuration; all certificate identity and
  authorization mappings.
- Native Worker's protected file configuration, per-device keys, certificate
  material and private state. Preserve CA recovery material offline separately.
- Every agent's durable journal/enrollment identity through an explicitly
  designed endpoint-recovery process; a server backup is not an agent backup.
- Exact image digests, executable hashes, schema version and file owners/modes.

Never restore just PostgreSQL while silently replacing keys or discarding an
agent journal. That can lose the durable identity needed to reason about already
executed or uncertain commands.

## Small safe first production procedure

1. Disable new dispatch through the normal application workflow. Inspect pending
   runs, leases, outbox entries and uncertainty. Do not delete or mark them
   successful. Stop the native Worker cleanly, then stop its two exact proxy
   sockets/services and only this Compose project's services. No global Docker
   stop/down, global systemd action or volume removal is permitted.
2. Verify both exact containers are stopped. Take a cold snapshot of the exact
   `/srv/railtime-openuem` data/config/secret subtrees plus separately located
   Worker state into a **new private backup destination**. Preserve ownership and
   modes; record a nonsecret hash manifest. Copying live PGDATA is not a valid
   standalone database backup. Existing production services stay untouched.
3. Restart the original exact containers, proxy sockets and Worker and verify authenticated health
   and identity. Keep dispatch paused until the planned acceptance is complete.
4. Restore the snapshot into a **new isolated private clone path**, never onto
   the original. Use the same pinned images, no published ports, network-none or
   a separate internal clone network, distinct names and no Worker/agent external
   connection. Verify PostgreSQL startup/schema/ledger rows and the broker's
   persistent store/certificate identity without executing queued commands.
5. Stop the clone, preserve the evidence and keep the original backup. Compare
   snapshot hashes and schema versions. Confirm the original service remains
   healthy. Only then resume authorized dispatch. A failed clone restore blocks
   production readiness; it must not trigger an in-place destructive restore.

For regular online PostgreSQL backups use the version-matched
[logical backup procedure](https://www.postgresql.org/docs/17/backup-dump.html)
or a reviewed physical/WAL procedure; an `openuem`-only dump does not include
cluster-wide roles. Secret connection files must be owner-only, not shell
arguments or exported environment values. Test restores and alerting regularly.

## Rollback is not downgrade

A source-file rollback does not undo database state or device effects. New
schema versions/command types and already accepted work must be checked before
restoring older software. Never blindly downgrade PG17 data to another major,
delete extension tables, reset an agent journal or rerun an uncertain command.
When in doubt leave dispatch disabled and reconcile the exact run with its
device evidence rather than claiming successful rollback.

// Package store owns the additive PostgreSQL run ledger used INSIDE OpenUEM.
// It is not a database interface for RailTime or a substitute for the native API.
package store

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"railtime.local/openuem-extension/protocol"
)

var (
	ErrNotFound  = errors.New("run not found")
	ErrConflict  = errors.New("immutable run conflict")
	ErrLeaseLost = errors.New("dispatch lease lost")
)

type Record struct {
	Principal string
	Request   protocol.RunRequest
	Command   protocol.Command
	KeyID     string
	Wire      []byte
	Status    string
	Result    *protocol.Result
}

func (r Record) View() protocol.RunView {
	c := r.Command
	v := protocol.RunView{RunID: c.RunID, CommandID: c.CommandID, CorrelationID: c.CorrelationID, AgentID: c.AgentID, ProfileID: c.ProfileID, PayloadSHA256: c.PayloadSHA256, ExpectedTasks: append([]string(nil), c.ExpectedTasks...), Status: r.Status}
	if r.Result != nil {
		// Native task output can contain credentials or personal data. The machine
		// API exposes outcomes and stable codes, never raw stdout/stderr.
		clean := *r.Result
		clean.Tasks = make([]protocol.TaskResult, len(r.Result.Tasks))
		for i, t := range r.Result.Tasks {
			clean.Tasks[i] = protocol.TaskResult{Name: t.Name, Succeeded: t.Succeeded}
			if t.Error != "" {
				clean.Tasks[i].Error = "task_failed"
			}
		}
		if clean.Error != "" {
			clean.Error = "execution_failed_or_uncertain"
		}
		v.Result = &clean
	}
	return v
}

type Delivery struct {
	Record   Record
	LeaseID  string
	Attempts int
}

type Ledger interface {
	Health(context.Context) error
	FindRequest(context.Context, string, string) (Record, error)
	Get(context.Context, string, string) (Record, error)
	GetInternal(context.Context, string) (Record, error)
	Accept(context.Context, Record) (Record, bool, error)
	Claim(context.Context, time.Time, time.Duration) (Delivery, error)
	Reschedule(context.Context, Delivery, time.Time) error
	Accepted(context.Context, Delivery) error
	Complete(context.Context, string, protocol.Result) (bool, error)
}

type Postgres struct{ db *sql.DB }

func New(db *sql.DB) *Postgres { return &Postgres{db: db} }

// Migrate is deliberately additive and serialized across workers. It never
// alters legacy OpenUEM tables and refuses an unknown extension schema version.
func (p *Postgres) Migrate(ctx context.Context) error {
	tx, err := p.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err = tx.ExecContext(ctx, `SELECT pg_advisory_xact_lock(1381258061, 1)`); err != nil {
		return err
	}
	for _, statement := range schemaStatements {
		if _, err = tx.ExecContext(ctx, statement); err != nil {
			return fmt.Errorf("run ledger migration failed: %w", err)
		}
	}
	var version int
	if err = tx.QueryRowContext(ctx, `SELECT version FROM railtime_execution_schema WHERE singleton = 1`).Scan(&version); err != nil {
		return err
	}
	if version != 1 {
		return errors.New("unsupported run ledger schema version")
	}
	return tx.Commit()
}

func (p *Postgres) Health(ctx context.Context) error {
	var version int
	if err := p.db.QueryRowContext(ctx, `SELECT version FROM railtime_execution_schema WHERE singleton = 1`).Scan(&version); err != nil {
		return err
	}
	if version != 1 {
		return errors.New("unsupported run ledger schema version")
	}
	// Refer to every required column so a stale version marker is not readiness.
	rows, err := p.db.QueryContext(ctx, `SELECT r.run_id,r.principal,r.command_id,r.correlation_id,r.request_json,r.command_json,r.key_id,r.command_wire,r.status,r.result_json,r.result_hash,o.run_id,o.lease_id,o.lease_until,o.next_attempt,o.attempts FROM railtime_execution_runs r LEFT JOIN railtime_execution_outbox o ON o.run_id=r.run_id LIMIT 0`)
	if err != nil {
		return err
	}
	return rows.Close()
}

const recordColumns = `principal,request_json,command_json,key_id,command_wire,status,result_json`

type scanner interface{ Scan(...any) error }

func scanRecord(s scanner) (Record, error) {
	var r Record
	var request, command, result []byte
	err := s.Scan(&r.Principal, &request, &command, &r.KeyID, &r.Wire, &r.Status, &result)
	if errors.Is(err, sql.ErrNoRows) {
		return r, ErrNotFound
	}
	if err != nil {
		return r, err
	}
	if err = protocol.Decode(request, &r.Request); err != nil {
		return r, err
	}
	if err = protocol.Decode(command, &r.Command); err != nil {
		return r, err
	}
	if err = r.Command.Validate(); err != nil {
		return r, err
	}
	if len(result) > 0 {
		var v protocol.Result
		if err = protocol.Decode(result, &v); err != nil {
			return r, err
		}
		if err = v.ValidateFor(r.Command); err != nil {
			return r, err
		}
		r.Result = &v
	}
	return r, nil
}
func (p *Postgres) FindRequest(ctx context.Context, principal, correlation string) (Record, error) {
	return scanRecord(p.db.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE principal=$1 AND correlation_id=$2`, principal, correlation))
}
func (p *Postgres) Get(ctx context.Context, principal, id string) (Record, error) {
	return scanRecord(p.db.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE principal=$1 AND run_id=$2`, principal, id))
}
func (p *Postgres) GetInternal(ctx context.Context, id string) (Record, error) {
	return scanRecord(p.db.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE run_id=$1`, id))
}

func (p *Postgres) Accept(ctx context.Context, r Record) (Record, bool, error) {
	if err := r.Request.Validate(); err != nil {
		return Record{}, false, err
	}
	if err := r.Command.Validate(); err != nil {
		return Record{}, false, err
	}
	if r.Principal == "" || r.KeyID == "" || len(r.Wire) == 0 || len(r.Wire) > protocol.MaxWireBytes {
		return Record{}, false, errors.New("invalid persisted run")
	}
	if r.Request.CommandID != r.Command.CommandID || r.Request.CorrelationID != r.Command.CorrelationID || r.Request.AgentID != r.Command.AgentID || r.Request.ProfileID != r.Command.ProfileID {
		return Record{}, false, ErrConflict
	}
	request, _ := json.Marshal(r.Request)
	command, _ := json.Marshal(r.Command)
	tx, err := p.db.BeginTx(ctx, nil)
	if err != nil {
		return Record{}, false, err
	}
	defer tx.Rollback()
	// Serialize BOTH unique request identifiers per principal. This avoids a
	// race reusing command_id with a new correlation_id, including two workers.
	if _, err = tx.ExecContext(ctx, `SELECT pg_advisory_xact_lock(hashtextextended($1,0))`, r.Principal); err != nil {
		return Record{}, false, err
	}
	existing, err := scanRecord(tx.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE principal=$1 AND (correlation_id=$2 OR command_id=$3)`, r.Principal, r.Request.CorrelationID, r.Request.CommandID))
	if err == nil {
		if existing.Request != r.Request {
			return Record{}, false, ErrConflict
		}
		return existing, false, tx.Commit()
	}
	if !errors.Is(err, ErrNotFound) {
		return Record{}, false, err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO railtime_execution_runs(run_id,principal,command_id,correlation_id,agent_id,tenant_id,site_id,profile_id,request_json,command_json,key_id,command_wire,status,created_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'queued',$13)`, r.Command.RunID, r.Principal, r.Command.CommandID, r.Command.CorrelationID, r.Command.AgentID, r.Command.TenantID, r.Command.SiteID, r.Command.ProfileID, string(request), string(command), r.KeyID, r.Wire, r.Command.IssuedAt)
	if err != nil {
		return Record{}, false, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO railtime_execution_outbox(run_id,next_attempt) VALUES($1,$2)`, r.Command.RunID, r.Command.IssuedAt); err != nil {
		return Record{}, false, err
	}
	if err = tx.Commit(); err != nil {
		return Record{}, false, err
	}
	r.Status = "queued"
	return r, true, nil
}

func (p *Postgres) Claim(ctx context.Context, now time.Time, lease time.Duration) (Delivery, error) {
	var d Delivery
	if lease < time.Second || lease > 5*time.Minute {
		return d, errors.New("invalid dispatch lease")
	}
	tx, err := p.db.BeginTx(ctx, nil)
	if err != nil {
		return d, err
	}
	defer tx.Rollback()
	var runID string
	err = tx.QueryRowContext(ctx, `SELECT run_id,attempts FROM railtime_execution_outbox WHERE next_attempt<=$1 AND (lease_until IS NULL OR lease_until<$1) ORDER BY next_attempt,run_id FOR UPDATE SKIP LOCKED LIMIT 1`, now).Scan(&runID, &d.Attempts)
	if errors.Is(err, sql.ErrNoRows) {
		return d, ErrNotFound
	}
	if err != nil {
		return d, err
	}
	d.Record, err = scanRecord(tx.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE run_id=$1`, runID))
	if err != nil {
		return d, err
	}
	d.LeaseID, err = protocol.NewID()
	if err != nil {
		return d, err
	}
	_, err = tx.ExecContext(ctx, `UPDATE railtime_execution_outbox SET lease_id=$2,lease_until=$3,attempts=attempts+1 WHERE run_id=$1`, runID, d.LeaseID, now.Add(lease))
	if err != nil {
		return d, err
	}
	d.Attempts++
	return d, tx.Commit()
}

func (p *Postgres) Reschedule(ctx context.Context, d Delivery, at time.Time) error {
	r, err := p.db.ExecContext(ctx, `UPDATE railtime_execution_outbox SET next_attempt=$3,lease_id=NULL,lease_until=NULL WHERE run_id=$1 AND lease_id=$2`, d.Record.Command.RunID, d.LeaseID, at)
	if err != nil {
		return err
	}
	n, err := r.RowsAffected()
	if err != nil {
		return err
	}
	if n != 1 {
		return ErrLeaseLost
	}
	return nil
}

func (p *Postgres) Accepted(ctx context.Context, d Delivery) error {
	tx, err := p.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	// Lock run before outbox, same ordering as completion. Result can beat the
	// acceptance response; a terminal row must never be downgraded.
	var status string
	if err = tx.QueryRowContext(ctx, `SELECT status FROM railtime_execution_runs WHERE run_id=$1 FOR UPDATE`, d.Record.Command.RunID).Scan(&status); err != nil {
		return err
	}
	if status == "succeeded" || status == "failed" || status == "uncertain" {
		return tx.Commit()
	}
	r, err := tx.ExecContext(ctx, `DELETE FROM railtime_execution_outbox WHERE run_id=$1 AND lease_id=$2`, d.Record.Command.RunID, d.LeaseID)
	if err != nil {
		return err
	}
	n, err := r.RowsAffected()
	if err != nil {
		return err
	}
	if n != 1 {
		return ErrLeaseLost
	}
	if _, err = tx.ExecContext(ctx, `UPDATE railtime_execution_runs SET status='accepted' WHERE run_id=$1 AND status='queued'`, d.Record.Command.RunID); err != nil {
		return err
	}
	return tx.Commit()
}

func (p *Postgres) Complete(ctx context.Context, keyID string, result protocol.Result) (bool, error) {
	tx, err := p.db.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	r, err := scanRecord(tx.QueryRowContext(ctx, `SELECT `+recordColumns+` FROM railtime_execution_runs WHERE run_id=$1 FOR UPDATE`, result.RunID))
	if err != nil {
		return false, err
	}
	if r.KeyID != keyID {
		return false, ErrConflict
	}
	if err = result.ValidateFor(r.Command); err != nil {
		return false, err
	}
	payload, _ := json.Marshal(result)
	digestBytes := sha256.Sum256(payload)
	hash := hex.EncodeToString(digestBytes[:])
	if r.Result != nil {
		old, _ := json.Marshal(r.Result)
		sum := sha256.Sum256(old)
		if hex.EncodeToString(sum[:]) != hash {
			return false, ErrConflict
		}
		return false, tx.Commit()
	}
	// A globally unique event_id prevents a signed event being rebound to a run.
	_, err = tx.ExecContext(ctx, `INSERT INTO railtime_execution_results(event_id,run_id,result_hash,result_json,received_at) VALUES($1,$2,$3,$4,$5)`, result.EventID, result.RunID, hash, string(payload), time.Now().UTC())
	if err != nil {
		return false, err
	}
	_, err = tx.ExecContext(ctx, `UPDATE railtime_execution_runs SET status=$2,result_json=$3,result_hash=$4 WHERE run_id=$1 AND result_json IS NULL`, result.RunID, result.Status, string(payload), hash)
	if err != nil {
		return false, err
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM railtime_execution_outbox WHERE run_id=$1`, result.RunID); err != nil {
		return false, err
	}
	return true, tx.Commit()
}

var schemaStatements = []string{
	`CREATE TABLE IF NOT EXISTS railtime_execution_schema(singleton smallint PRIMARY KEY CHECK(singleton=1),version integer NOT NULL CHECK(version>0))`,
	`INSERT INTO railtime_execution_schema(singleton,version) VALUES(1,1) ON CONFLICT(singleton) DO NOTHING`,
	`CREATE TABLE IF NOT EXISTS railtime_execution_runs(run_id uuid PRIMARY KEY,principal text NOT NULL,command_id uuid NOT NULL,correlation_id uuid NOT NULL,agent_id text NOT NULL,tenant_id integer NOT NULL CHECK(tenant_id>0),site_id integer NOT NULL CHECK(site_id>0),profile_id integer NOT NULL CHECK(profile_id>0),request_json jsonb NOT NULL,command_json jsonb NOT NULL,key_id text NOT NULL,command_wire bytea NOT NULL,status text NOT NULL CHECK(status IN('queued','accepted','succeeded','failed','uncertain')),result_json jsonb,result_hash text,created_at timestamptz NOT NULL,UNIQUE(principal,correlation_id),UNIQUE(principal,command_id))`,
	`CREATE TABLE IF NOT EXISTS railtime_execution_outbox(run_id uuid PRIMARY KEY REFERENCES railtime_execution_runs(run_id) ON DELETE RESTRICT,next_attempt timestamptz NOT NULL,lease_id uuid,lease_until timestamptz,attempts integer NOT NULL DEFAULT 0 CHECK(attempts>=0))`,
	`CREATE INDEX IF NOT EXISTS rt_exec_outbox_due ON railtime_execution_outbox(next_attempt)`,
	`CREATE TABLE IF NOT EXISTS railtime_execution_results(event_id uuid PRIMARY KEY,run_id uuid NOT NULL UNIQUE REFERENCES railtime_execution_runs(run_id) ON DELETE RESTRICT,result_hash text NOT NULL,result_json jsonb NOT NULL,received_at timestamptz NOT NULL)`,
}

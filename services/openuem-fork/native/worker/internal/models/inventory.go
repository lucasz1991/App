package models

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"
	"unicode"

	entsql "entgo.io/ent/dialect/sql"
	"github.com/open-uem/ent"
	"github.com/open-uem/ent/agent"
	native "github.com/open-uem/nats"
	"railtime.local/openuem-extension/inventory"
)

var ErrInventoryRejected = errors.New("inventory rejected: identity, scope, state or persistence conflict")

type InventoryStore struct{ DB *sql.DB }

// Migrate adds only the versioned inventory ledger. It never creates devices,
// changes native tables, enables admission or repairs a partial ledger.
func (s InventoryStore) Migrate(ctx context.Context) error {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err = tx.ExecContext(ctx, `SELECT pg_advisory_xact_lock(hashtextextended('railtime-inventory-schema:' || current_database() || ':' || current_schema(),0))`); err != nil {
		return err
	}
	var count int
	if err = tx.QueryRowContext(ctx, `SELECT count(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name IN ('railtime_inventory_schema','railtime_inventory_reports','railtime_inventory_current','railtime_inventory_admissions')`).Scan(&count); err != nil {
		return err
	}
	if count != 0 {
		var version int
		if count != 4 || tx.QueryRowContext(ctx, `SELECT version FROM railtime_inventory_schema WHERE singleton=true`).Scan(&version) != nil || version != 1 {
			return ErrInventoryRejected
		}
		return nil
	}
	_, err = tx.ExecContext(ctx, `
CREATE TABLE railtime_inventory_schema(singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton),version integer NOT NULL CHECK(version=1));
INSERT INTO railtime_inventory_schema VALUES(true,1);
CREATE TABLE railtime_inventory_reports(
 report_id uuid PRIMARY KEY, agent_id varchar NOT NULL REFERENCES agents(oid) ON DELETE RESTRICT,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 site_id bigint NOT NULL REFERENCES sites(id) ON DELETE RESTRICT,
 collected_ns bigint NOT NULL, digest varchar(64) NOT NULL, receipt jsonb NOT NULL,
 accepted_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE INDEX railtime_inventory_reports_agent ON railtime_inventory_reports(agent_id);
CREATE TABLE railtime_inventory_current(
 agent_id varchar PRIMARY KEY REFERENCES agents(oid) ON DELETE RESTRICT,
 report_id uuid NOT NULL UNIQUE REFERENCES railtime_inventory_reports(report_id) ON DELETE RESTRICT,
 collected_ns bigint NOT NULL, native_report jsonb NOT NULL);
CREATE TABLE railtime_inventory_admissions(
 request_id uuid PRIMARY KEY, agent_id varchar NOT NULL UNIQUE REFERENCES agents(oid) ON DELETE RESTRICT,
 report_id uuid NOT NULL REFERENCES railtime_inventory_reports(report_id) ON DELETE RESTRICT,
 tenant_id bigint NOT NULL, site_id bigint NOT NULL, reason varchar(1000) NOT NULL,
 operator_uid varchar(128) NOT NULL, admitted_at timestamptz NOT NULL DEFAULT clock_timestamp());`)
	if err != nil {
		return err
	}
	return tx.Commit()
}

// ValidateNativeReport is deliberately called before opening a DB transaction.
// Transport identity comes from the server's exact certificate-bound subject,
// never from the payload's claimed identifier.
func ValidateNativeReport(e inventory.Enrollment, subject string, report inventory.Report, data *native.AgentReport, now time.Time) error {
	want, err := inventory.Subject(e.AgentID)
	if err != nil || subject != want || report.Validate(now) != nil || report.AgentID != e.AgentID || report.TenantID != e.TenantID || report.SiteID != e.SiteID || data == nil || data.AgentID != e.AgentID || data.Tenant != strconv.Itoa(e.TenantID) || data.Site != strconv.Itoa(e.SiteID) || !strings.EqualFold(data.OS, "windows") || strings.TrimSpace(data.Hostname) == "" || len(data.Hostname) > 255 {
		return ErrInventoryRejected
	}
	return nil
}

// Save accepts the already strictly decoded native payload but checks its
// binding again; one transaction owns observations, receipt and latest snapshot.
func (s InventoryStore) Save(ctx context.Context, e inventory.Enrollment, subject string, report inventory.Report, data *native.AgentReport, now time.Time) (inventory.Receipt, error) {
	var empty inventory.Receipt
	if err := ValidateNativeReport(e, subject, report, data, now); err != nil {
		return empty, err
	}
	// Normalize native JSON so harmless whitespace/order changes do not affect
	// retries. No raw inventory is logged or returned to the device.
	raw, err := json.Marshal(data)
	if err != nil {
		return empty, ErrInventoryRejected
	}
	normalized := report
	normalized.Report = raw
	normalized.CollectedAt = normalized.CollectedAt.UTC()
	encoded, err := json.Marshal(normalized)
	if err != nil {
		return empty, ErrInventoryRejected
	}
	sum := sha256.Sum256(encoded)
	digest := hex.EncodeToString(sum[:])
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return empty, err
	}
	defer tx.Rollback()
	if err = inventoryLock(ctx, tx, e); err != nil {
		return empty, err
	}
	var state string
	agentErr := tx.QueryRowContext(ctx, `SELECT agent_status FROM agents WHERE oid=$1 FOR UPDATE`, e.AgentID).Scan(&state)
	if agentErr != nil && !errors.Is(agentErr, sql.ErrNoRows) {
		return empty, agentErr
	}
	if agentErr == nil {
		if err = exactInventoryAgentScope(ctx, tx, e); err != nil {
			return empty, err
		}
	}
	var oldDigest, oldAgent string
	var oldTenant, oldSite int
	var oldReceipt []byte
	err = tx.QueryRowContext(ctx, `SELECT agent_id,tenant_id,site_id,digest,receipt FROM railtime_inventory_reports WHERE report_id=$1`, report.ReportID).Scan(&oldAgent, &oldTenant, &oldSite, &oldDigest, &oldReceipt)
	if err == nil {
		if agentErr != nil || oldAgent != e.AgentID || oldTenant != e.TenantID || oldSite != e.SiteID || oldDigest != digest || json.Unmarshal(oldReceipt, &empty) != nil || empty.Validate(report) != nil {
			return inventory.Receipt{}, ErrInventoryRejected
		}
		// Original receipt is immutable, even after a later admission. A fresh
		// report is required to observe the new server-owned state.
		if err = tx.Commit(); err != nil {
			return inventory.Receipt{}, err
		}
		return empty, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return empty, err
	}
	var latest int64
	err = tx.QueryRowContext(ctx, `SELECT collected_ns FROM railtime_inventory_current WHERE agent_id=$1`, e.AgentID).Scan(&latest)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return empty, err
	}
	if err == nil && report.CollectedAt.UnixNano() <= latest {
		return empty, ErrInventoryRejected
	}
	client := ent.NewClient(ent.Driver(&bootstrapDriver{Conn: entsql.Conn{ExecQuerier: tx}}))
	if errors.Is(agentErr, sql.ErrNoRows) {
		_, err = client.Agent.Create().SetID(e.AgentID).SetOs(data.OS).SetHostname(data.Hostname).SetNickname(data.Hostname).
			SetAgentStatus(agent.AgentStatusWaitingForAdmission).SetSftpService(false).SetRemoteAssistance(false).SetCertificateReady(false).
			SetFirstContact(now).SetLastContact(now).AddSiteIDs(e.SiteID).Save(ctx)
		state = string(agent.AgentStatusWaitingForAdmission)
	} else {
		err = nil
	}
	if err != nil {
		return empty, err
	}
	// Server-owned status, site, nickname, notes, description, configuration,
	// release association and task state are intentionally not in this update.
	err = client.Agent.UpdateOneID(e.AgentID).SetOs(data.OS).SetHostname(data.Hostname).SetIP(data.IP).SetMAC(data.MACAddress).
		SetWan(data.WAN).SetVnc(data.SupportedVNCServer).SetHasRustdesk(data.HasRustDesk).SetIsFlatpakRustdesk(data.IsFlatpakRustDesk).
		SetIsWayland(data.IsWayland).SetRestartRequired(data.RestartRequired).SetLastContact(now).Exec(ctx)
	if err != nil {
		return empty, err
	}
	if err = saveInventoryObservations(ctx, client, data); err != nil {
		return empty, err
	}
	status := inventoryStatus(state)
	receipt := inventory.Receipt{Protocol: report.Protocol, ReportID: report.ReportID, AgentID: e.AgentID, TenantID: e.TenantID, SiteID: e.SiteID, Status: status}
	wire, err := json.Marshal(receipt)
	if err != nil {
		return empty, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO railtime_inventory_reports(report_id,agent_id,tenant_id,site_id,collected_ns,digest,receipt) VALUES($1,$2,$3,$4,$5,$6,$7)`, report.ReportID, e.AgentID, e.TenantID, e.SiteID, report.CollectedAt.UnixNano(), digest, string(wire)); err != nil {
		return empty, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO railtime_inventory_current(agent_id,report_id,collected_ns,native_report) VALUES($1,$2,$3,$4) ON CONFLICT(agent_id) DO UPDATE SET report_id=EXCLUDED.report_id,collected_ns=EXCLUDED.collected_ns,native_report=EXCLUDED.native_report`, e.AgentID, report.ReportID, report.CollectedAt.UnixNano(), string(raw)); err != nil {
		return empty, err
	}
	if err = tx.Commit(); err != nil {
		return empty, err
	}
	return receipt, nil
}

func inventoryStatus(state string) string {
	switch state {
	case string(agent.AgentStatusWaitingForAdmission):
		return "waiting_for_admission"
	case string(agent.AgentStatusEnabled):
		return "enabled"
	default:
		return "disabled"
	}
}

func inventoryLock(ctx context.Context, tx *sql.Tx, e inventory.Enrollment) error {
	if _, err := tx.ExecContext(ctx, `SELECT pg_advisory_xact_lock(hashtextextended('railtime-inventory-agent:' || current_database() || ':' || current_schema() || ':' || $1,0))`, e.AgentID); err != nil {
		return err
	}
	var tenant int
	// Lock configured site against concurrent tenant reassignment.
	if err := tx.QueryRowContext(ctx, `SELECT tenant_sites FROM sites WHERE id=$1 FOR SHARE`, e.SiteID).Scan(&tenant); err != nil || tenant != e.TenantID {
		return ErrInventoryRejected
	}
	return nil
}

func exactInventoryAgentScope(ctx context.Context, tx *sql.Tx, e inventory.Enrollment) error {
	rows, err := tx.QueryContext(ctx, `SELECT site_id FROM site_agents WHERE agent_id=$1 FOR UPDATE`, e.AgentID)
	if err != nil {
		return err
	}
	defer rows.Close()
	n := 0
	for rows.Next() {
		var id int
		if err = rows.Scan(&id); err != nil {
			return err
		}
		if id != e.SiteID {
			return ErrInventoryRejected
		}
		n++
	}
	if err = rows.Err(); err != nil {
		return err
	}
	if n != 1 {
		return ErrInventoryRejected
	}
	return nil
}

type InventoryAdmission struct {
	RequestID string `json:"request_id"`
	AgentID   string `json:"agent_id"`
	TenantID  int    `json:"tenant_id"`
	SiteID    int    `json:"site_id"`
	Reason    string `json:"reason"`
}

type InventoryAdmissionResult struct {
	RequestID string `json:"request_id"`
	AgentID   string `json:"agent_id"`
	TenantID  int    `json:"tenant_id"`
	SiteID    int    `json:"site_id"`
	Status    string `json:"status"`
}

func (s InventoryStore) Admit(ctx context.Context, e inventory.Enrollment, r InventoryAdmission, operatorUID string) (InventoryAdmissionResult, error) {
	var empty InventoryAdmissionResult
	if _, err := inventory.Subject(r.RequestID); err != nil || e.Validate() != nil || r.AgentID != e.AgentID || r.TenantID != e.TenantID || r.SiteID != e.SiteID || strings.TrimSpace(r.Reason) == "" || len(r.Reason) > 1000 || strings.IndexFunc(r.Reason, unicode.IsControl) >= 0 || len(operatorUID) == 0 || len(operatorUID) > 128 {
		return empty, ErrInventoryRejected
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return empty, err
	}
	defer tx.Rollback()
	if err = inventoryLock(ctx, tx, e); err != nil {
		return empty, err
	}
	var state string
	if err = tx.QueryRowContext(ctx, `SELECT agent_status FROM agents WHERE oid=$1 FOR UPDATE`, e.AgentID).Scan(&state); err != nil {
		return empty, ErrInventoryRejected
	}
	if err = exactInventoryAgentScope(ctx, tx, e); err != nil {
		return empty, err
	}
	var reportID string
	if err = tx.QueryRowContext(ctx, `SELECT r.report_id FROM railtime_inventory_current c JOIN railtime_inventory_reports r ON r.report_id=c.report_id WHERE c.agent_id=$1 AND r.agent_id=$1 AND r.tenant_id=$2 AND r.site_id=$3`, e.AgentID, e.TenantID, e.SiteID).Scan(&reportID); err != nil {
		return empty, ErrInventoryRejected
	}
	var oldAgent, reason, uid string
	var tenant, site int
	err = tx.QueryRowContext(ctx, `SELECT agent_id,tenant_id,site_id,reason,operator_uid FROM railtime_inventory_admissions WHERE request_id=$1`, r.RequestID).Scan(&oldAgent, &tenant, &site, &reason, &uid)
	if err == nil {
		if oldAgent != e.AgentID || tenant != e.TenantID || site != e.SiteID || reason != r.Reason || uid != operatorUID || state != string(agent.AgentStatusEnabled) {
			return empty, ErrInventoryRejected
		}
	} else {
		if !errors.Is(err, sql.ErrNoRows) {
			return empty, err
		}
		if state != string(agent.AgentStatusWaitingForAdmission) {
			return empty, ErrInventoryRejected
		}
		if _, err = tx.ExecContext(ctx, `INSERT INTO railtime_inventory_admissions(request_id,agent_id,report_id,tenant_id,site_id,reason,operator_uid) VALUES($1,$2,$3,$4,$5,$6,$7)`, r.RequestID, e.AgentID, reportID, e.TenantID, e.SiteID, r.Reason, operatorUID); err != nil {
			return empty, err
		}
		res, err := tx.ExecContext(ctx, `UPDATE agents SET agent_status=$1 WHERE oid=$2 AND agent_status=$3`, string(agent.AgentStatusEnabled), e.AgentID, string(agent.AgentStatusWaitingForAdmission))
		if err != nil {
			return empty, err
		}
		if n, err := res.RowsAffected(); err != nil || n != 1 {
			return empty, ErrInventoryRejected
		}
	}
	if err = tx.Commit(); err != nil {
		return empty, err
	}
	return InventoryAdmissionResult{RequestID: r.RequestID, AgentID: e.AgentID, TenantID: e.TenantID, SiteID: e.SiteID, Status: "enabled"}, nil
}

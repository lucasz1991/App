package models

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"slices"
	"strings"

	"entgo.io/ent/dialect"
	entsql "entgo.io/ent/dialect/sql"
	"github.com/jackc/pgx/v5"
	"github.com/open-uem/ent"
	"github.com/open-uem/ent/migrate"
	"github.com/open-uem/ent/settings"
)

var ErrBootstrapConflict = errors.New("native bootstrap refused: database is not empty or an exact unchanged bootstrap")

type BootstrapMetadata struct {
	TenantName string
	SiteName   string
	SiteDomain string
}

type BootstrapResult struct {
	Status           string `json:"status"`
	TenantID         int    `json:"tenant_id"`
	SiteID           int    `json:"site_id"`
	GlobalSettingsID int    `json:"global_settings_id"`
	TenantSettingsID int    `json:"tenant_settings_id"`
	ProvisioningOnly bool   `json:"provisioning_only"`
}

// bootstrapDriver binds ALL Ent DDL and inserts to the same PostgreSQL
// transaction. Nested Ent commit/rollback calls intentionally do not end it;
// only BootstrapEmptyDatabase owns the final commit or rollback.
type bootstrapDriver struct{ entsql.Conn }

func (d *bootstrapDriver) Dialect() string { return dialect.Postgres }
func (d *bootstrapDriver) Close() error    { return nil }
func (d *bootstrapDriver) Tx(context.Context) (dialect.Tx, error) {
	return dialect.NopTx(d), nil
}

func BootstrapEmptyDatabase(ctx context.Context, db *sql.DB, metadata BootstrapMetadata) (BootstrapResult, error) {
	var result BootstrapResult
	if metadata.TenantName == "" || metadata.SiteName == "" || metadata.SiteDomain == "" {
		return result, ErrBootstrapConflict
	}
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return result, err
	}
	defer tx.Rollback()
	var schema string
	if err = tx.QueryRowContext(ctx, `SELECT current_schema()`).Scan(&schema); err != nil || schema == "" || strings.HasPrefix(schema, "pg_") || schema == "information_schema" {
		return result, ErrBootstrapConflict
	}
	if _, err = tx.ExecContext(ctx, `SELECT pg_advisory_xact_lock(hashtextextended('railtime-native-bootstrap:' || current_database() || ':' || current_schema(),0))`); err != nil {
		return result, err
	}
	var owner bool
	if err = tx.QueryRowContext(ctx, `SELECT n.nspowner=(SELECT oid FROM pg_roles WHERE rolname=current_user) OR (n.nspname='public' AND d.datdba=(SELECT oid FROM pg_roles WHERE rolname=current_user)) FROM pg_namespace n JOIN pg_database d ON d.datname=current_database() WHERE n.nspname=current_schema()`).Scan(&owner); err != nil || !owner {
		return result, ErrBootstrapConflict
	}
	// Pin one schema; other search_path entries must never receive model reads
	// or initialization writes through a missing/unexpected relation.
	if _, err = tx.ExecContext(ctx, `SET LOCAL search_path TO `+pgx.Identifier{schema}.Sanitize()); err != nil {
		return result, err
	}
	var objects int
	if err = tx.QueryRowContext(ctx, `SELECT (SELECT count(*) FROM pg_class WHERE relnamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema()))+(SELECT count(*) FROM pg_proc WHERE pronamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema()))+(SELECT count(*) FROM pg_type WHERE typnamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema()) AND typtype IN ('e','d'))`).Scan(&objects); err != nil {
		return result, err
	}
	client := ent.NewClient(ent.Driver(&bootstrapDriver{Conn: entsql.Conn{ExecQuerier: tx}}))
	if objects != 0 {
		// Never run Ent's migration against a nonempty schema. Even an idempotent
		// repeat is strictly a read path and cannot repair or reconfigure data.
		return verifyExactBootstrap(ctx, tx, client, metadata)
	}
	if err = client.Schema.Create(ctx); err != nil {
		return result, err
	}
	t, err := client.Tenant.Create().SetDescription(metadata.TenantName).SetIsDefault(true).Save(ctx)
	if err != nil {
		return result, err
	}
	s, err := client.Site.Create().SetDescription(metadata.SiteName).SetIsDefault(true).SetDomain(metadata.SiteDomain).SetTenantID(t.ID).Save(ctx)
	if err != nil {
		return result, err
	}
	global, err := initialSettings(client, metadata).Save(ctx)
	if err != nil {
		return result, err
	}
	tenantSettings, err := initialSettings(client, metadata).SetTenantID(t.ID).Save(ctx)
	if err != nil {
		return result, err
	}
	result = BootstrapResult{Status: "created", TenantID: t.ID, SiteID: s.ID, GlobalSettingsID: global.ID, TenantSettingsID: tenantSettings.ID, ProvisioningOnly: true}
	if err = tx.Commit(); err != nil {
		return BootstrapResult{}, err
	}
	return result, nil
}

func initialSettings(client *ent.Client, metadata BootstrapMetadata) *ent.SettingsCreate {
	return client.Settings.Create().SetOrganization(metadata.TenantName).SetCountry("").
		SetAutoAdmitAgents(false).SetDisableSftp(true).SetDisableRemoteAssistance(true).
		SetDetectRemoteAgents(false).SetRequestVncPin(true)
}

func verifyExactBootstrap(ctx context.Context, tx *sql.Tx, client *ent.Client, metadata BootstrapMetadata) (BootstrapResult, error) {
	var result BootstrapResult
	// Exactly the pinned native table/column names must exist. Extension tables,
	// user-defined objects, partial migrations and foreign applications refuse.
	expected := map[string][]string{}
	for _, table := range migrate.Tables {
		for _, column := range table.Columns {
			expected[table.Name] = append(expected[table.Name], column.Name)
		}
		slices.Sort(expected[table.Name])
	}
	rows, err := tx.QueryContext(ctx, `SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,column_name`)
	if err != nil {
		return result, err
	}
	actual := map[string][]string{}
	for rows.Next() {
		var table, column string
		if err = rows.Scan(&table, &column); err != nil {
			rows.Close()
			return result, err
		}
		actual[table] = append(actual[table], column)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return result, err
	}
	if len(actual) != len(expected) {
		return result, ErrBootstrapConflict
	}
	for table, columns := range expected {
		if !slices.Equal(columns, actual[table]) {
			return result, ErrBootstrapConflict
		}
		var count int
		if err = tx.QueryRowContext(ctx, `SELECT count(*) FROM `+pgx.Identifier{table}.Sanitize()).Scan(&count); err != nil {
			return result, err
		}
		want := 0
		switch table {
		case "tenants", "sites":
			want = 1
		case "settings":
			want = 2
		}
		if count != want {
			return result, ErrBootstrapConflict
		}
	}
	var extras int
	if err = tx.QueryRowContext(ctx, `SELECT (SELECT count(*) FROM pg_proc WHERE pronamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema()))+(SELECT count(*) FROM pg_type WHERE typnamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema()) AND typtype IN ('e','d'))`).Scan(&extras); err != nil || extras != 0 {
		return result, ErrBootstrapConflict
	}
	t, err := client.Tenant.Query().Only(ctx)
	if err != nil || t.Description != metadata.TenantName || !t.IsDefault {
		return result, ErrBootstrapConflict
	}
	s, err := client.Site.Query().WithTenant().Only(ctx)
	if err != nil || s.Description != metadata.SiteName || !s.IsDefault || s.Domain != metadata.SiteDomain || s.Edges.Tenant == nil || s.Edges.Tenant.ID != t.ID {
		return result, ErrBootstrapConflict
	}
	settingRows, err := client.Settings.Query().WithTenant().WithTag().All(ctx)
	if err != nil {
		return result, err
	}
	result = BootstrapResult{Status: "unchanged", TenantID: t.ID, SiteID: s.ID, ProvisioningOnly: true}
	for _, row := range settingRows {
		if row.Edges.Tag != nil || !settingsUnchanged(row, metadata) {
			return BootstrapResult{}, ErrBootstrapConflict
		}
		if row.Edges.Tenant == nil && result.GlobalSettingsID == 0 {
			result.GlobalSettingsID = row.ID
		} else if row.Edges.Tenant != nil && row.Edges.Tenant.ID == t.ID && result.TenantSettingsID == 0 {
			result.TenantSettingsID = row.ID
		} else {
			return BootstrapResult{}, ErrBootstrapConflict
		}
	}
	if result.GlobalSettingsID == 0 || result.TenantSettingsID == 0 {
		return BootstrapResult{}, ErrBootstrapConflict
	}
	return result, nil
}

func settingsUnchanged(row *ent.Settings, metadata BootstrapMetadata) bool {
	expected := ent.Settings{
		ID: row.ID, Created: row.Created, Modified: row.Modified, Edges: row.Edges,
		Organization: metadata.TenantName, Country: "",
		SMTPPort: settings.DefaultSMTPPort, SMTPAuth: settings.DefaultSMTPAuth,
		SMTPTLS: settings.DefaultSMTPTLS, SMTPStarttls: settings.DefaultSMTPStarttls,
		MaxUploadSize: settings.DefaultMaxUploadSize, UserCertYearsValid: settings.DefaultUserCertYearsValid,
		NatsRequestTimeoutSeconds:     settings.DefaultNatsRequestTimeoutSeconds,
		RefreshTimeInMinutes:          settings.DefaultRefreshTimeInMinutes,
		SessionLifetimeInMinutes:      settings.DefaultSessionLifetimeInMinutes,
		UpdateChannel:                 settings.DefaultUpdateChannel,
		AgentReportFrequenceInMinutes: settings.DefaultAgentReportFrequenceInMinutes,
		RequestVncPin:                 true, ProfilesApplicationFrequenceInMinutes: settings.DefaultProfilesApplicationFrequenceInMinutes,
		UseWinget: settings.DefaultUseWinget, UseFlatpak: settings.DefaultUseFlatpak, UseBrew: settings.DefaultUseBrew,
		DisableSftp: true, DisableRemoteAssistance: true, AutoAdmitAgents: false,
		DetectRemoteAgents: false, DefaultItemsPerPage: settings.DefaultDefaultItemsPerPage,
	}
	a, err := json.Marshal(row)
	if err != nil {
		return false
	}
	b, err := json.Marshal(expected)
	return err == nil && bytes.Equal(a, b)
}

// Compile-time assertion: this driver is not a connection pool, and no schema
// migration can escape its owning transaction through a hidden sql.DB.
var _ dialect.Driver = (*bootstrapDriver)(nil)

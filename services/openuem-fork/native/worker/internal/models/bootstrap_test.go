package models

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"flag"
	"os"
	"strings"
	"sync"
	"testing"

	entsql "entgo.io/ent/dialect/sql"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/open-uem/ent"
	"railtime.local/openuem-extension/protocol"
)

var bootstrapPGFixture = flag.String("railtime-pg-fixture", "", "private isolated loopback55479 PostgreSQL test fixture only")

func newBootstrapPG(t *testing.T) *sql.DB {
	t.Helper()
	if *bootstrapPGFixture == "" {
		t.Skip("real PostgreSQL bootstrap fixture not provided")
	}
	b, err := os.ReadFile(*bootstrapPGFixture)
	if err != nil {
		t.Fatal("private PostgreSQL fixture unavailable")
	}
	var f struct {
		DSN string `json:"dsn"`
	}
	if json.Unmarshal(b, &f) != nil {
		t.Fatal("invalid private fixture")
	}
	c, err := pgx.ParseConfig(f.DSN)
	if err != nil || c.Host != "127.0.0.1" || c.Port != 55479 {
		t.Fatal("refusing non-isolated PostgreSQL fixture")
	}
	admin := stdlib.OpenDB(*c)
	t.Cleanup(func() { _ = admin.Close() })
	id, _ := protocol.NewID()
	schema := "rt_bootstrap_test_" + strings.ReplaceAll(id, "-", "")
	quoted := pgx.Identifier{schema}.Sanitize()
	if _, err = admin.ExecContext(context.Background(), `CREATE SCHEMA `+quoted); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = admin.ExecContext(context.Background(), `DROP SCHEMA `+quoted+` CASCADE`) })
	c.RuntimeParams = map[string]string{"search_path": schema}
	db := stdlib.OpenDB(*c)
	db.SetMaxOpenConns(5)
	t.Cleanup(func() { _ = db.Close() })
	return db
}

func bootstrapTestMetadata() BootstrapMetadata {
	return BootstrapMetadata{TenantName: "Synthetic tenant", SiteName: "Synthetic site", SiteDomain: "example.invalid"}
}

func TestBootstrapPostgresInitialAndExactRepeat(t *testing.T) {
	db := newBootstrapPG(t)
	ctx := context.Background()
	first, err := BootstrapEmptyDatabase(ctx, db, bootstrapTestMetadata())
	if err != nil || first.Status != "created" || !first.ProvisioningOnly || first.TenantID <= 0 || first.SiteID <= 0 {
		t.Fatalf("first bootstrap: %#v %v", first, err)
	}
	var before string
	if err = db.QueryRowContext(ctx, `SELECT json_agg(s ORDER BY id)::text FROM settings s`).Scan(&before); err != nil {
		t.Fatal(err)
	}
	again, err := BootstrapEmptyDatabase(ctx, db, bootstrapTestMetadata())
	if err != nil || again.Status != "unchanged" || again.TenantID != first.TenantID || again.SiteID != first.SiteID || again.GlobalSettingsID != first.GlobalSettingsID || again.TenantSettingsID != first.TenantSettingsID {
		t.Fatalf("repeat bootstrap: %#v %v", again, err)
	}
	var after string
	if err = db.QueryRowContext(ctx, `SELECT json_agg(s ORDER BY id)::text FROM settings s`).Scan(&after); err != nil || before != after {
		t.Fatal("repeat changed settings or timestamps", err)
	}
	for _, table := range []string{"agents", "profiles", "tasks", "users"} {
		var n int
		if err = db.QueryRowContext(ctx, `SELECT count(*) FROM `+pgx.Identifier{table}.Sanitize()).Scan(&n); err != nil || n != 0 {
			t.Fatalf("bootstrap created forbidden business records in %s: %d %v", table, n, err)
		}
	}
}

func TestBootstrapPostgresRejectsForeignPartialAndChangedState(t *testing.T) {
	for _, scenario := range []string{"foreign", "partial", "changed", "different-input", "populated", "extra-column"} {
		t.Run(scenario, func(t *testing.T) {
			db := newBootstrapPG(t)
			ctx := context.Background()
			metadata := bootstrapTestMetadata()
			if scenario == "foreign" {
				if _, err := db.ExecContext(ctx, `CREATE TABLE foreign_sentinel(id integer PRIMARY KEY); INSERT INTO foreign_sentinel VALUES(7)`); err != nil {
					t.Fatal(err)
				}
			} else {
				if _, err := BootstrapEmptyDatabase(ctx, db, metadata); err != nil {
					t.Fatal(err)
				}
				var query string
				switch scenario {
				case "partial":
					query = `DELETE FROM settings WHERE tenant_settings IS NOT NULL`
				case "changed":
					query = `UPDATE settings SET auto_admit_agents=true`
				case "different-input":
					metadata.SiteName = "Other site"
				case "populated":
					query = `INSERT INTO profiles(name,apply_to_all,disabled) VALUES('Synthetic existing profile',false,true)`
				case "extra-column":
					query = `ALTER TABLE settings ADD COLUMN unexpected text`
				}
				if query != "" {
					if _, err := db.ExecContext(ctx, query); err != nil {
						t.Fatal(err)
					}
				}
			}
			if _, err := BootstrapEmptyDatabase(ctx, db, metadata); !errors.Is(err, ErrBootstrapConflict) {
				t.Fatalf("conflict was not refused: %v", err)
			}
			if scenario == "foreign" {
				var tables, id int
				if err := db.QueryRowContext(ctx, `SELECT count(*) FROM information_schema.tables WHERE table_schema=current_schema()`).Scan(&tables); err != nil || tables != 1 {
					t.Fatal("foreign schema mutated", err)
				}
				if err := db.QueryRowContext(ctx, `SELECT id FROM foreign_sentinel`).Scan(&id); err != nil || id != 7 {
					t.Fatal("foreign record changed", err)
				}
			} else if scenario == "changed" {
				var stillChanged int
				if err := db.QueryRowContext(ctx, `SELECT count(*) FROM settings WHERE auto_admit_agents=true`).Scan(&stillChanged); err != nil || stillChanged != 2 {
					t.Fatal("bootstrap repaired changed settings", err)
				}
			} else if scenario == "partial" {
				var stillPartial int
				if err := db.QueryRowContext(ctx, `SELECT count(*) FROM settings`).Scan(&stillPartial); err != nil || stillPartial != 1 {
					t.Fatal("bootstrap repaired partial settings", err)
				}
			}
		})
	}
}

func TestBootstrapPostgresConcurrent(t *testing.T) {
	db := newBootstrapPG(t)
	var wg sync.WaitGroup
	results := make(chan BootstrapResult, 2)
	errorsFound := make(chan error, 2)
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			r, err := BootstrapEmptyDatabase(context.Background(), db, bootstrapTestMetadata())
			results <- r
			errorsFound <- err
		}()
	}
	wg.Wait()
	close(results)
	close(errorsFound)
	for err := range errorsFound {
		if err != nil {
			t.Fatal(err)
		}
	}
	statuses := map[string]int{}
	for r := range results {
		statuses[r.Status]++
	}
	if statuses["created"] != 1 || statuses["unchanged"] != 1 {
		t.Fatalf("unexpected concurrent outcome %#v", statuses)
	}
}

func TestBootstrapPostgresSchemaAndRowsRollbackTogether(t *testing.T) {
	db := newBootstrapPG(t)
	ctx := context.Background()
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	client := ent.NewClient(ent.Driver(&bootstrapDriver{Conn: entsql.Conn{ExecQuerier: tx}}))
	if err = client.Schema.Create(ctx); err != nil {
		t.Fatal(err)
	}
	if err = client.Tenant.Create().SetDescription("Never committed").Exec(ctx); err != nil {
		t.Fatal(err)
	}
	if err = tx.Rollback(); err != nil {
		t.Fatal(err)
	}
	var count int
	if err = db.QueryRowContext(ctx, `SELECT count(*) FROM pg_class WHERE relnamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema())`).Scan(&count); err != nil || count != 0 {
		t.Fatalf("Ent nested transaction escaped outer rollback: objects=%d err=%v", count, err)
	}
}

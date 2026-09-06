package common

import (
	"bytes"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"entgo.io/ent/dialect"
	entsql "entgo.io/ent/dialect/sql"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/open-uem/ent"
	"github.com/open-uem/ent/agent"
	"github.com/open-uem/ent/task"
	"github.com/open-uem/openuem-worker/internal/models"
	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/server"
	"railtime.local/openuem-extension/store"
)

var railTimePGFixture = flag.String("railtime-pg-fixture", "", "private JSON connection fixture for isolated PostgreSQL integration tests (never a production endpoint)")

type pgFixture struct {
	db        *sql.DB
	openDB    func() *sql.DB
	client    *ent.Client
	ledger    *store.Postgres
	service   *server.Service
	principal server.Principal
	request   protocol.RunRequest
	transport *testRunTransport
	key       server.DeviceKey
}

func newPGFixture(t *testing.T) *pgFixture {
	t.Helper()
	if *railTimePGFixture == "" {
		t.Skip("real PostgreSQL fixture not provided")
	}
	b, err := os.ReadFile(*railTimePGFixture)
	if err != nil {
		t.Fatal("private PostgreSQL fixture unavailable")
	}
	var fixture struct {
		DSN string `json:"dsn"`
	}
	if json.Unmarshal(b, &fixture) != nil {
		t.Fatal("invalid fixture")
	}
	config, err := pgx.ParseConfig(fixture.DSN)
	if err != nil {
		t.Fatal("invalid private fixture DSN")
	}
	if config.Host != "127.0.0.1" || config.Port != 55479 {
		t.Fatal("refusing non-isolated PostgreSQL endpoint")
	}
	adminDB := stdlib.OpenDB(*config)
	t.Cleanup(func() { _ = adminDB.Close() })
	ctx := context.Background()
	id, _ := protocol.NewID()
	schema := "rt_run_test_" + strings.ReplaceAll(id, "-", "")
	if _, err = adminDB.ExecContext(ctx, `CREATE SCHEMA `+schema); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = adminDB.ExecContext(context.Background(), `DROP SCHEMA `+schema+` CASCADE`) })
	if config.RuntimeParams == nil {
		config.RuntimeParams = map[string]string{}
	}
	config.RuntimeParams["search_path"] = schema
	db := stdlib.OpenDB(*config)
	db.SetMaxOpenConns(16)
	t.Cleanup(func() { _ = db.Close() })
	client := ent.NewClient(ent.Driver(entsql.OpenDB(dialect.Postgres, db)))
	if err = client.Schema.Create(ctx); err != nil {
		t.Fatal(err)
	}
	ledger := store.New(db)
	if err = ledger.Migrate(ctx); err != nil {
		t.Fatal(err)
	}
	tenant, err := client.Tenant.Create().SetDescription("Synthetic run-contract tenant").Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	site, err := client.Site.Create().SetTenant(tenant).SetDescription("Synthetic site").Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	const agentID = "fixture-windows-agent"
	a, err := client.Agent.Create().SetID(agentID).SetHostname("synthetic-no-real-device").SetOs("windows").SetAgentStatus(agent.AgentStatusEnabled).AddSite(site).Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	profile, err := client.Profile.Create().SetName("Dedicated explicit-run fixture").SetSite(site).SetApplyToAll(false).Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	_, err = client.Task.Create().SetName("Synthetic no-op fixture").SetType(task.TypePowershellScript).SetAgentType(task.AgentTypeWindows).SetScript("Write-Output 'synthetic-only'").SetProfile(profile).Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	token := strings.Repeat("fixture-token-not-production-", 2)
	tokenHash := sha256.Sum256([]byte(token))
	principal := server.Principal{ID: "fixture-railtime", BearerSHA256: hex.EncodeToString(tokenHash[:]), TenantID: tenant.ID, SiteID: site.ID, AgentIDs: []string{a.ID}, ProfileIDs: []int{profile.ID}}
	key := server.DeviceKey{AgentID: a.ID, KeyID: "fixture-device-key", KeyBase64: base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{19}, 32))}
	transport := &testRunTransport{key: key, connected: true}
	service := &server.Service{Config: server.Config{Enabled: true, Listen: "127.0.0.1:9441", TLSCertificate: "C:/synthetic/tls.crt", TLSPrivateKey: "C:/synthetic/tls.key", CommandTTLSeconds: 900, Principals: []server.Principal{principal}, DeviceKeys: []server.DeviceKey{key}}, Ledger: ledger, Profiles: nativeProfiles{worker: &Worker{Model: &models.Model{Client: client, DB: db}}}, Transport: transport}
	commandID, _ := protocol.NewID()
	correlationID, _ := protocol.NewID()
	return &pgFixture{db: db, openDB: func() *sql.DB { return stdlib.OpenDB(*config) }, client: client, ledger: ledger, service: service, principal: principal, request: protocol.RunRequest{CommandID: commandID, CorrelationID: correlationID, AgentID: a.ID, ProfileID: profile.ID}, transport: transport, key: key}
}

type testRunTransport struct {
	mu         sync.Mutex
	key        server.DeviceKey
	connected  bool
	fail       bool
	wires      [][]byte
	ackStatus  string
	ackEventID string
}

func (t *testRunTransport) Connected() bool { return t.connected }
func (t *testRunTransport) Request(_ context.Context, subject string, wire []byte) ([]byte, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.wires = append(t.wires, append([]byte(nil), wire...))
	if t.fail {
		return nil, errors.New("synthetic reply lost")
	}
	var c protocol.Command
	k, _ := t.key.Bytes()
	if err := protocol.Verify(protocol.CommandContext, t.key.KeyID, k, wire, &c); err != nil {
		return nil, err
	}
	expected, _ := protocol.CommandSubject(c.AgentID)
	if subject != expected {
		return nil, errors.New("wrong device subject")
	}
	status := t.ackStatus
	if status == "" {
		status = "accepted"
	}
	return protocol.Sign(protocol.AckContext, t.key.KeyID, k, protocol.Ack{Version: protocol.Version, RunID: c.RunID, PayloadSHA256: c.PayloadSHA256, EventID: t.ackEventID, Status: status})
}

func (f *pgFixture) accept(t *testing.T) protocol.RunView {
	t.Helper()
	v, status, err := f.service.Accept(context.Background(), f.principal, f.request)
	if err != nil || status != 202 {
		t.Fatalf("accept: status=%d err=%v", status, err)
	}
	return v
}
func (f *pgFixture) result(t *testing.T, v protocol.RunView) protocol.Result {
	t.Helper()
	event, _ := protocol.NewID()
	tasks := []protocol.TaskResult{}
	for _, name := range v.ExpectedTasks {
		tasks = append(tasks, protocol.TaskResult{Name: name, Succeeded: true, Output: "synthetic-sensitive-output"})
	}
	return protocol.Result{Version: protocol.Version, EventID: event, RunID: v.RunID, CorrelationID: v.CorrelationID, AgentID: v.AgentID, PayloadSHA256: v.PayloadSHA256, Status: "succeeded", FinishedAt: time.Now().UTC(), Tasks: tasks}
}
func (f *pgFixture) resultWire(t *testing.T, r protocol.Result) []byte {
	t.Helper()
	key, _ := f.key.Bytes()
	b, err := protocol.Sign(protocol.ResultContext, f.key.KeyID, key, r)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestRailTimePostgresNativePipeline(t *testing.T) {
	f := newPGFixture(t)
	ctx := context.Background()
	t.Run("additive migration repeat and health", func(t *testing.T) {
		if err := f.ledger.Migrate(ctx); err != nil {
			t.Fatal(err)
		}
		if err := f.ledger.Health(ctx); err != nil {
			t.Fatal(err)
		}
	})
	v := f.accept(t)
	t.Run("native immutable snapshot and replay", func(t *testing.T) {
		r, err := f.ledger.GetInternal(ctx, v.RunID)
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Contains(r.Command.Snapshot, []byte("synthetic-only")) || len(r.Command.ExpectedTasks) != 1 {
			t.Fatal("native WinGet materialization missing")
		}
		if _, err = f.client.Task.Update().SetScript("Write-Output 'changed-after-accept'").Save(ctx); err != nil {
			t.Fatal(err)
		}
		replayed, status, err := f.service.Accept(ctx, f.principal, f.request)
		if err != nil || status != 200 || replayed.RunID != v.RunID || replayed.PayloadSHA256 != v.PayloadSHA256 {
			t.Fatal("retry changed command")
		}
	})
	t.Run("dispatch timeout keeps identical outbox payload", func(t *testing.T) {
		f.transport.fail = true
		if err := f.service.DispatchOne(ctx); err == nil || errors.Is(err, store.ErrNotFound) {
			t.Fatalf("transport timeout must be observed, got %v", err)
		}
		if _, err := f.db.ExecContext(ctx, `UPDATE railtime_execution_outbox SET next_attempt='2000-01-01T00:00:00Z'`); err != nil {
			t.Fatal(err)
		}
		f.transport.fail = false
		if err := f.service.DispatchOne(ctx); err != nil {
			t.Fatal(err)
		}
		if len(f.transport.wires) != 2 || !bytes.Equal(f.transport.wires[0], f.transport.wires[1]) {
			t.Fatal("retry changed payload")
		}
		var count int
		if err := f.db.QueryRowContext(ctx, `SELECT count(*) FROM railtime_execution_outbox`).Scan(&count); err != nil || count != 0 {
			t.Fatal("accepted command outbox retained")
		}
		view, err := f.service.Get(ctx, f.principal, v.RunID)
		if err != nil || view.Status != "accepted" || view.Result != nil {
			t.Fatal("acceptance confused with success")
		}
	})
	result := f.result(t, v)
	wire := f.resultWire(t, result)
	t.Run("database result failure never acknowledged", func(t *testing.T) {
		if _, err := f.db.ExecContext(ctx, `ALTER TABLE railtime_execution_results ADD CONSTRAINT fixture_reject_result CHECK(false) NOT VALID`); err != nil {
			t.Fatal(err)
		}
		ack, err := f.service.ReceiveResult(ctx, f.request.AgentID, wire)
		if err == nil || len(ack) != 0 {
			t.Fatal("failed persistence was acknowledged")
		}
		r, err := f.ledger.GetInternal(ctx, v.RunID)
		if err != nil || r.Result != nil || r.Status != "accepted" {
			t.Fatal("failed transaction leaked result")
		}
		if _, err = f.db.ExecContext(ctx, `ALTER TABLE railtime_execution_results DROP CONSTRAINT fixture_reject_result`); err != nil {
			t.Fatal(err)
		}
	})
	t.Run("signed durable receipt and immutable result replay", func(t *testing.T) {
		ack, err := f.service.ReceiveResult(ctx, f.request.AgentID, wire)
		if err != nil {
			t.Fatal(err)
		}
		var receipt protocol.Ack
		k, _ := f.key.Bytes()
		if err = protocol.Verify(protocol.AckContext, f.key.KeyID, k, ack, &receipt); err != nil || receipt.Status != "persisted" || receipt.EventID != result.EventID {
			t.Fatal("invalid durable receipt")
		}
		if _, err = f.service.ReceiveResult(ctx, f.request.AgentID, wire); err != nil {
			t.Fatal("identical replay rejected", err)
		}
		changed := result
		changed.Tasks = append([]protocol.TaskResult(nil), result.Tasks...)
		changed.Tasks[0].Output = "different"
		if _, err = f.service.ReceiveResult(ctx, f.request.AgentID, f.resultWire(t, changed)); !errors.Is(err, store.ErrConflict) {
			t.Fatal("terminal result mutated", err)
		}
		view, err := f.service.Get(ctx, f.principal, v.RunID)
		if err != nil || view.Status != "succeeded" || view.Result == nil {
			t.Fatal("durable success missing")
		}
		public, _ := json.Marshal(view)
		if bytes.Contains(public, []byte("synthetic-sensitive-output")) {
			t.Fatal("raw output exposed")
		}
	})
	t.Run("new pool reads persisted correct run", func(t *testing.T) {
		db := f.openDB()
		defer db.Close()
		other := store.New(db)
		r, err := other.Get(ctx, f.principal.ID, v.RunID)
		if err != nil || r.Result == nil || r.Result.EventID != result.EventID {
			t.Fatal("not durable")
		}
	})
}

func TestRailTimePostgresRequestAtomicityAndConcurrency(t *testing.T) {
	f := newPGFixture(t)
	ctx := context.Background()
	if _, err := f.db.ExecContext(ctx, `ALTER TABLE railtime_execution_outbox ADD CONSTRAINT fixture_reject_outbox CHECK(false) NOT VALID`); err != nil {
		t.Fatal(err)
	}
	if _, _, err := f.service.Accept(ctx, f.principal, f.request); err == nil {
		t.Fatal("expected outbox insertion failure")
	}
	var count int
	if err := f.db.QueryRowContext(ctx, `SELECT count(*) FROM railtime_execution_runs`).Scan(&count); err != nil || count != 0 {
		t.Fatal("partial run committed")
	}
	if _, err := f.db.ExecContext(ctx, `ALTER TABLE railtime_execution_outbox DROP CONSTRAINT fixture_reject_outbox`); err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	results := make(chan protocol.RunView, 8)
	failures := make(chan error, 8)
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			view, _, err := f.service.Accept(ctx, f.principal, f.request)
			if err != nil {
				failures <- err
			} else {
				results <- view
			}
		}()
	}
	wg.Wait()
	close(results)
	close(failures)
	for err := range failures {
		t.Error(err)
	}
	runID := ""
	for view := range results {
		if runID == "" {
			runID = view.RunID
		}
		if view.RunID != runID {
			t.Fatal("concurrent retry created another run")
		}
	}
	if err := f.db.QueryRowContext(ctx, `SELECT count(*) FROM railtime_execution_runs`).Scan(&count); err != nil || count != 1 {
		t.Fatal("duplicate runs")
	}
	conflict := f.request
	conflict.CommandID, _ = protocol.NewID()
	if _, status, err := f.service.Accept(ctx, f.principal, conflict); status != 409 || err == nil {
		t.Fatal("correlation rebound")
	}
	conflict = f.request
	conflict.CorrelationID, _ = protocol.NewID()
	if _, status, err := f.service.Accept(ctx, f.principal, conflict); status != 409 || err == nil {
		t.Fatal("command rebound")
	}
	first, err := f.ledger.Claim(ctx, time.Now().UTC(), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = f.ledger.Claim(ctx, time.Now().UTC(), time.Second); !errors.Is(err, store.ErrNotFound) {
		t.Fatal("concurrent lease duplication")
	}
	second, err := f.ledger.Claim(ctx, time.Now().UTC().Add(2*time.Second), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	if first.LeaseID == second.LeaseID {
		t.Fatal("stale lease reused")
	}
	if err = f.ledger.Accepted(ctx, first); !errors.Is(err, store.ErrLeaseLost) {
		t.Fatal("stale dispatcher accepted")
	}
	if err = f.ledger.Accepted(ctx, second); err != nil {
		t.Fatal(err)
	}
}

func TestRailTimePostgresScopeAndOutcomeGuards(t *testing.T) {
	f := newPGFixture(t)
	ctx := context.Background()
	t.Run("dedicated profile required", func(t *testing.T) {
		if err := f.client.Profile.UpdateOneID(f.request.ProfileID).SetApplyToAll(true).Exec(ctx); err != nil {
			t.Fatal(err)
		}
		if _, _, err := f.service.Accept(ctx, f.principal, f.request); err == nil {
			t.Fatal("auto-applied profile accepted")
		}
		if err := f.client.Profile.UpdateOneID(f.request.ProfileID).SetApplyToAll(false).Exec(ctx); err != nil {
			t.Fatal(err)
		}
		tag, err := f.client.Tag.Create().SetTag("synthetic-legacy-tag").SetColor("red").Save(ctx)
		if err != nil {
			t.Fatal(err)
		}
		if err = f.client.Profile.UpdateOneID(f.request.ProfileID).AddTags(tag).Exec(ctx); err != nil {
			t.Fatal(err)
		}
		if _, _, err = f.service.Accept(ctx, f.principal, f.request); err == nil {
			t.Fatal("legacy tag profile accepted")
		}
		if err = f.client.Profile.UpdateOneID(f.request.ProfileID).ClearTags().Exec(ctx); err != nil {
			t.Fatal(err)
		}
	})
	t.Run("device native tenant and status required", func(t *testing.T) {
		wrong := f.principal
		wrong.TenantID++
		if _, _, err := f.service.Accept(ctx, wrong, f.request); err == nil {
			t.Fatal("cross tenant accepted")
		}
		if err := f.client.Agent.UpdateOneID(f.request.AgentID).SetAgentStatus(agent.AgentStatusDisabled).Exec(ctx); err != nil {
			t.Fatal(err)
		}
		if _, _, err := f.service.Accept(ctx, f.principal, f.request); err == nil {
			t.Fatal("disabled agent accepted")
		}
		if err := f.client.Agent.UpdateOneID(f.request.AgentID).SetAgentStatus(agent.AgentStatusEnabled).Exec(ctx); err != nil {
			t.Fatal(err)
		}
	})
	v := f.accept(t)
	t.Run("result cannot impersonate another subject", func(t *testing.T) {
		result := f.result(t, v)
		if _, err := f.service.ReceiveResult(ctx, "different-device", f.resultWire(t, result)); err == nil {
			t.Fatal("cross device accepted")
		}
		wire := f.resultWire(t, result)
		wire[len(wire)-5] ^= 1
		if _, err := f.service.ReceiveResult(ctx, f.request.AgentID, wire); err == nil {
			t.Fatal("tampered result accepted")
		}
	})
	t.Run("missing task cannot prove success", func(t *testing.T) {
		result := f.result(t, v)
		result.Tasks = nil
		if _, err := f.service.ReceiveResult(ctx, f.request.AgentID, f.resultWire(t, result)); err == nil {
			t.Fatal("incomplete success accepted")
		}
	})
	t.Run("terminal result before acceptance is never downgraded", func(t *testing.T) {
		d, err := f.ledger.Claim(ctx, time.Now().UTC(), time.Second)
		if err != nil {
			t.Fatal(err)
		}
		result := f.result(t, v)
		result.Status = "uncertain"
		result.Tasks = nil
		result.Error = "synthetic-restart-uncertainty"
		if _, err = f.service.ReceiveResult(ctx, f.request.AgentID, f.resultWire(t, result)); err != nil {
			t.Fatal(err)
		}
		if err = f.ledger.Accepted(ctx, d); err != nil {
			t.Fatal(err)
		}
		view, err := f.service.Get(ctx, f.principal, v.RunID)
		if err != nil || view.Status != "uncertain" {
			t.Fatal("late ack downgraded terminal state")
		}
	})
	t.Run("wrong principal cannot read run", func(t *testing.T) {
		wrong := f.principal
		wrong.ID = "other-service"
		if _, err := f.service.Get(ctx, wrong, v.RunID); !errors.Is(err, store.ErrNotFound) {
			t.Fatal("cross principal read allowed")
		}
		wrong = f.principal
		wrong.TenantID++
		if _, err := f.service.Get(ctx, wrong, v.RunID); !errors.Is(err, server.ErrForbidden) {
			t.Fatal("changed tenant read allowed")
		}
	})
}

func TestRailTimePostgresHTTPContract(t *testing.T) {
	f := newPGFixture(t)
	token := strings.Repeat("fixture-token-not-production-", 2)
	handler := f.service.Handler()
	request := func(method, path, auth, body string) *httptest.ResponseRecorder {
		req := httptest.NewRequest(method, path, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		if auth != "" {
			req.Header.Set("Authorization", auth)
		}
		w := httptest.NewRecorder()
		handler.ServeHTTP(w, req)
		return w
	}
	if w := request("GET", "/railtime/v1/health", "", ""); w.Code != 401 {
		t.Fatal("unauthenticated health")
	}
	if w := request("GET", "/railtime/v1/health", "Bearer "+token, ""); w.Code != 200 || !strings.Contains(w.Body.String(), `"storage_ready":true`) {
		t.Fatal("real readiness missing", w.Code, w.Body.String())
	}
	f.transport.connected = false
	if w := request("GET", "/railtime/v1/health", "Bearer "+token, ""); w.Code != 503 || strings.Contains(w.Body.String(), `"ready":true`) {
		t.Fatal("broker failure green")
	}
	f.transport.connected = true
	b, _ := json.Marshal(f.request)
	w := request("POST", "/railtime/v1/runs", "Bearer "+token, string(b))
	if w.Code != http.StatusAccepted {
		t.Fatal(w.Code, w.Body.String())
	}
	var view protocol.RunView
	if err := json.Unmarshal(w.Body.Bytes(), &view); err != nil {
		t.Fatal(err)
	}
	if view.RunID == "" || len(view.ExpectedTasks) != 1 || strings.Contains(w.Body.String(), "snapshot") {
		t.Fatal("receipt contract invalid")
	}
	if w = request("POST", "/railtime/v1/runs", "Bearer "+token, string(b)); w.Code != 200 {
		t.Fatal("HTTP replay not idempotent")
	}
	if w = request("GET", "/railtime/v1/runs/"+view.RunID, "Bearer "+token, ""); w.Code != 200 {
		t.Fatal("HTTP result unavailable")
	}
	if w = request("POST", "/railtime/v1/runs", "Bearer "+token, fmt.Sprintf(`{"command_id":%q,"correlation_id":%q,"agent_id":%q,"profile_id":%d,"snapshot":"injected"}`, f.request.CommandID, f.request.CorrelationID, f.request.AgentID, f.request.ProfileID)); w.Code != 422 {
		t.Fatal("caller supplied snapshot accepted")
	}
}

// Package integration verifies real local sockets and storage using a synthetic
// counter executor. It NEVER imports or invokes PowerShell or native device tasks.
package integration

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	natsserver "github.com/nats-io/nats-server/v2/server"
	"github.com/nats-io/nats.go"
	"railtime.local/openuem-extension/agentexec"
	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/server"
	"railtime.local/openuem-extension/store"
)

var pgFixture = flag.String("pg-fixture", "", "Private local PostgreSQL test-fixture JSON; its DSN is never logged")

type socketTransport struct{ connection *nats.Conn }

func (s socketTransport) Connected() bool { return s.connection.IsConnected() }
func (s socketTransport) Request(ctx context.Context, subject string, wire []byte) ([]byte, error) {
	// Bound the injected lost-reply case without cancelling the outer ledger
	// transaction context: the real service can durably reschedule the outbox.
	requestCtx, cancel := context.WithTimeout(ctx, 350*time.Millisecond)
	defer cancel()
	reply, err := s.connection.RequestWithContext(requestCtx, subject, wire)
	if err != nil {
		return nil, err
	}
	return reply.Data, nil
}

type syntheticProfile struct{}

func (syntheticProfile) Authorize(_ context.Context, principal server.Principal, request protocol.RunRequest) error {
	if principal.TenantID != 71 || principal.SiteID != 72 || !principal.Allows(request.AgentID, request.ProfileID) {
		return server.ErrForbidden
	}
	return nil
}
func (syntheticProfile) Snapshot(_ context.Context, _ server.Principal, _ protocol.RunRequest) (server.Snapshot, error) {
	return server.Snapshot{YAML: []byte("synthetic_counter_only: true\n"), ExpectedTasks: []string{"synthetic_counter"}}, nil
}

func TestRealSocketPipelineWithLostRepliesAndDeviceForgery(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	db, ledger := postgresFixture(t, ctx)
	broker, workerConn, agentConn := natsFixture(t)
	_ = broker
	key := randomBytes(t, 32)
	otherKey := randomBytes(t, 32)
	bearer := hex.EncodeToString(randomBytes(t, 32))
	bearerHash := sha256.Sum256([]byte(bearer))
	cfg := server.Config{
		Enabled: true, Listen: "127.0.0.1:9441", CommandTTLSeconds: 900,
		Principals: []server.Principal{{ID: "fixture-railtime", BearerSHA256: hex.EncodeToString(bearerHash[:]), TenantID: 71, SiteID: 72, AgentIDs: []string{"socket-device-a"}, ProfileIDs: []int{73}}},
		DeviceKeys: []server.DeviceKey{
			{AgentID: "socket-device-a", KeyID: "fixture-key-a", KeyBase64: base64.StdEncoding.EncodeToString(key)},
			{AgentID: "socket-device-b", KeyID: "fixture-key-b", KeyBase64: base64.StdEncoding.EncodeToString(otherKey)},
		},
	}
	if err := cfg.Validate(); err != nil {
		t.Fatal("synthetic server configuration failed validation")
	}
	var clockOffset atomic.Int64
	svc := &server.Service{Config: cfg, Ledger: ledger, Profiles: syntheticProfile{}, Transport: socketTransport{workerConn},
		Now: func() time.Time { return time.Now().UTC().Add(time.Duration(clockOffset.Load())) }}
	httpServer := httptest.NewServer(svc.Handler()) // actual loopback TCP, not ResponseRecorder
	httpAddress := strings.TrimPrefix(httpServer.URL, "http://")
	t.Cleanup(func() {
		httpServer.Close()
		assertClosed(t, httpAddress)
	})

	journalPath := filepath.Join(t.TempDir(), "private-native-journal")
	if err := agentexec.Initialize(journalPath, "socket-device-a", "socket-fixture-ledger"); err != nil {
		t.Fatal("private synthetic Badger enrollment failed")
	}
	journal, err := agentexec.Open(journalPath, "socket-device-a", "socket-fixture-ledger")
	if err != nil {
		t.Fatal("private synthetic Badger journal unavailable")
	}
	t.Cleanup(func() {
		if journal != nil {
			_ = journal.Close()
		}
	})
	agentCfg := agentexec.Config{Enabled: true, AgentID: "socket-device-a", TenantID: 71, SiteID: 72, KeyID: "fixture-key-a", LedgerID: "socket-fixture-ledger", Key: key}
	var executed atomic.Int64
	executor := func(command protocol.Command, begin func() error) agentexec.Outcome {
		if err := begin(); err != nil {
			return agentexec.Outcome{Status: "failed", Error: "synthetic_start_rejected"}
		}
		executed.Add(1) // The ONLY synthetic device effect in this entire harness.
		return agentexec.Outcome{Status: "succeeded", Tasks: []protocol.TaskResult{{Name: command.ExpectedTasks[0], Succeeded: true, Output: "synthetic-private-output-must-not-reach-http"}}}
	}
	agentTransport := func(subject string, wire []byte) ([]byte, error) {
		message, err := agentConn.Request(subject, wire, 350*time.Millisecond)
		if err != nil {
			return nil, err
		}
		return message.Data, nil
	}
	newEngine := func() *agentexec.Engine {
		engine, err := agentexec.NewEngine(agentCfg, journal, executor, agentTransport)
		if err != nil {
			t.Fatal("synthetic execution engine could not start")
		}
		return engine
	}
	var currentEngine atomic.Pointer[agentexec.Engine]
	currentEngine.Store(newEngine())
	commandSubject, _ := protocol.CommandSubject("socket-device-a")
	resultSubject, _ := protocol.ResultSubject("socket-device-a")
	var dropCommandReply atomic.Bool
	dropCommandReply.Store(true)
	var dropResultReply atomic.Bool
	dropResultReply.Store(true)
	var commandDeliveries atomic.Int64
	var resultDeliveries atomic.Int64
	var rejectedResults atomic.Int64
	_, err = agentConn.Subscribe(commandSubject, func(message *nats.Msg) {
		commandDeliveries.Add(1)
		ack, err := currentEngine.Load().Accept(message.Data)
		if err != nil {
			_ = message.Respond([]byte("rejected"))
			return
		}
		if dropCommandReply.CompareAndSwap(true, false) {
			return
		} // accepted locally, socket reply lost
		_ = message.Respond(ack)
	})
	if err != nil {
		t.Fatal("actual NATS command subscription failed")
	}
	_, err = workerConn.Subscribe(resultSubject, func(message *nats.Msg) {
		ack, err := svc.ReceiveResult(context.Background(), "socket-device-a", message.Data)
		if err != nil {
			rejectedResults.Add(1)
			_ = message.Respond([]byte("rejected"))
			return
		}
		resultDeliveries.Add(1)
		if dropResultReply.CompareAndSwap(true, false) {
			return
		} // committed remotely, socket reply lost
		_ = message.Respond(ack)
	})
	if err != nil {
		t.Fatal("actual NATS result subscription failed")
	}
	if agentConn.FlushTimeout(time.Second) != nil || workerConn.FlushTimeout(time.Second) != nil {
		t.Fatal("actual NATS subscriptions not ready")
	}

	request := protocol.RunRequest{CommandID: newID(t), CorrelationID: newID(t), AgentID: "socket-device-a", ProfileID: 73}
	var health server.Health
	httpJSON(t, httpServer, bearer, http.MethodGet, "/railtime/v1/health", nil, 200, &health)
	if !health.Ready {
		t.Fatal("real PostgreSQL/NATS server health not ready")
	}
	var accepted protocol.RunView
	httpJSON(t, httpServer, bearer, http.MethodPost, "/railtime/v1/runs", request, 202, &accepted)
	if accepted.Status != "queued" || accepted.Result != nil || executed.Load() != 0 {
		t.Fatal("HTTP acceptance was mistaken for execution")
	}
	assertCount(t, db, "railtime_execution_outbox", 1)
	assertCount(t, db, "railtime_execution_results", 0)
	if err = svc.DispatchOne(ctx); err == nil {
		t.Fatal("injected command reply loss was not observed")
	}
	prepared, err := journal.Get(accepted.RunID)
	if err != nil || prepared.State != "prepared" || executed.Load() != 0 {
		t.Fatal("lost acknowledgment did not preserve the prepared local journal")
	}
	clockOffset.Store(int64(6 * time.Second))
	if err = svc.DispatchOne(ctx); err != nil {
		t.Fatalf("actual NATS redelivery failed: %v", err)
	}
	if commandDeliveries.Load() != 2 || executed.Load() != 0 {
		t.Fatal("command redelivery executed prematurely")
	}
	assertCount(t, db, "railtime_execution_outbox", 0)

	// An authenticated broker peer is not the device identity. Exercise actual
	// socket publications with another device's key and mismatching payload ID.
	forged := protocol.Result{Version: protocol.Version, EventID: newID(t), RunID: accepted.RunID, CorrelationID: request.CorrelationID,
		AgentID: request.AgentID, PayloadSHA256: accepted.PayloadSHA256, Status: "succeeded", FinishedAt: time.Now().UTC(),
		Tasks: []protocol.TaskResult{{Name: "synthetic_counter", Succeeded: true}}}
	forgedWire, _ := protocol.Sign(protocol.ResultContext, "fixture-key-b", otherKey, forged)
	assertRejectedWire(t, agentConn, resultSubject, forgedWire)
	forged.AgentID = "socket-device-b"
	forgedWire, _ = protocol.Sign(protocol.ResultContext, "fixture-key-a", key, forged)
	assertRejectedWire(t, agentConn, resultSubject, forgedWire)
	if rejectedResults.Load() != 2 {
		t.Fatal("cross-device forged results were not rejected")
	}
	assertCount(t, db, "railtime_execution_results", 0)

	if err = currentEngine.Load().Step(); err != nil {
		t.Fatal("synthetic engine step failed")
	}
	local, err := journal.Get(accepted.RunID)
	if err != nil || executed.Load() != 1 || local.Delivered || local.Result == nil {
		t.Fatal("lost result receipt did not preserve the completed journal outbox")
	}
	eventID := local.Result.EventID
	assertCount(t, db, "railtime_execution_results", 1)
	if err = currentEngine.Load().Step(); err != nil {
		t.Fatal("synthetic result redelivery failed")
	}
	local, _ = journal.Get(accepted.RunID)
	if executed.Load() != 1 || !local.Delivered || local.Result.EventID != eventID || resultDeliveries.Load() != 2 {
		t.Fatal("result retry was not immutable and at-most-once")
	}

	// Reopen the actual Badger files and resend the exact command over NATS.
	// Only the saved terminal result may replay; the executor counter stays one.
	if journal.Close() != nil {
		t.Fatal("journal close failed")
	}
	journal = nil
	journal, err = agentexec.Open(journalPath, "socket-device-a", "socket-fixture-ledger")
	if err != nil {
		t.Fatal("journal restart failed")
	}
	currentEngine.Store(newEngine())
	persisted, err := ledger.GetInternal(ctx, accepted.RunID)
	if err != nil {
		t.Fatal("saved immutable server command unavailable")
	}
	if _, err = workerConn.Request(commandSubject, persisted.Wire, time.Second); err != nil {
		t.Fatal("terminal duplicate command socket request failed")
	}
	if err = currentEngine.Load().Step(); err != nil {
		t.Fatal("terminal duplicate replay failed")
	}
	if executed.Load() != 1 || resultDeliveries.Load() != 3 {
		t.Fatal("reopened terminal duplicate executed again or failed replay")
	}
	assertCount(t, db, "railtime_execution_results", 1)
	assertCount(t, db, "railtime_execution_outbox", 0)

	var terminal protocol.RunView
	httpJSON(t, httpServer, bearer, http.MethodGet, "/railtime/v1/runs/"+accepted.RunID, nil, 200, &terminal)
	if terminal.Status != "succeeded" || terminal.RunID != accepted.RunID || terminal.PayloadSHA256 != accepted.PayloadSHA256 || terminal.Result == nil || terminal.Result.EventID != eventID || terminal.Result.Tasks[0].Output != "" {
		t.Fatal("HTTP terminal result is not bound, immutable and redacted")
	}
	var repeated protocol.RunView
	httpJSON(t, httpServer, bearer, http.MethodPost, "/railtime/v1/runs", request, 200, &repeated)
	if repeated.RunID != accepted.RunID || repeated.Result == nil || repeated.Result.EventID != eventID {
		t.Fatal("HTTP idempotent replay changed the run")
	}
	conflict := request
	conflict.CommandID = newID(t)
	httpJSON(t, httpServer, bearer, http.MethodPost, "/railtime/v1/runs", conflict, 409, nil)
	if executed.Load() != 1 {
		t.Fatal("idempotent HTTP replay executed again")
	}
	t.Log("PASS: real HTTP -> PostgreSQL outbox -> authenticated local NATS sockets -> actual Badger journal -> synthetic counter exactly 1 -> signed result -> immutable redacted HTTP result; command/result reply loss, journal reopen and cross-device forgeries covered")
}

func postgresFixture(t *testing.T, ctx context.Context) (*sql.DB, *store.Postgres) {
	t.Helper()
	if *pgFixture == "" {
		t.Skip("explicit -pg-fixture is required; no ambient database fallback")
	}
	path, err := filepath.Abs(*pgFixture)
	if err != nil {
		t.Fatal("invalid private PostgreSQL fixture path")
	}
	b, err := os.ReadFile(path)
	if err != nil || len(b) > 16384 {
		t.Fatal("private PostgreSQL fixture unavailable")
	}
	var fixture struct {
		DSN string `json:"dsn"`
	}
	if json.Unmarshal(b, &fixture) != nil || fixture.DSN == "" {
		t.Fatal("invalid private PostgreSQL fixture")
	}
	cfg, err := pgx.ParseConfig(fixture.DSN)
	if err != nil || cfg.Host != "127.0.0.1" || cfg.Port != 55479 || cfg.Database != "postgres" || cfg.User != "railtime_test" || len(cfg.Fallbacks) != 0 {
		t.Fatal("PostgreSQL fixture must be the exact dedicated local test cluster; refusing all other targets")
	}
	base := stdlib.OpenDB(*cfg)
	if base.PingContext(ctx) != nil {
		_ = base.Close()
		t.Fatal("dedicated local PostgreSQL fixture unavailable")
	}
	schema := "rt_socket_" + hex.EncodeToString(randomBytes(t, 8))
	if !regexp.MustCompile(`\Art_socket_[0-9a-f]{16}\z`).MatchString(schema) {
		t.Fatal("invalid disposable schema name")
	}
	quoted := pgx.Identifier{schema}.Sanitize()
	if _, err = base.ExecContext(ctx, "CREATE SCHEMA "+quoted); err != nil {
		_ = base.Close()
		t.Fatal("isolated PostgreSQL schema creation failed")
	}
	childCfg := cfg.Copy()
	childCfg.RuntimeParams["search_path"] = schema
	db := stdlib.OpenDB(*childCfg)
	db.SetMaxOpenConns(4)
	t.Cleanup(func() {
		_ = db.Close()
		cleanupCtx, cleanupCancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cleanupCancel()
		if !regexp.MustCompile(`\Art_socket_[0-9a-f]{16}\z`).MatchString(schema) {
			t.Error("refusing unsafe schema cleanup")
			_ = base.Close()
			return
		}
		if _, err := base.ExecContext(cleanupCtx, "DROP SCHEMA "+quoted+" CASCADE"); err != nil {
			t.Error("own isolated schema cleanup failed")
		}
		var remaining int
		if err := base.QueryRowContext(cleanupCtx, "SELECT count(*) FROM pg_namespace WHERE nspname=$1", schema).Scan(&remaining); err != nil || remaining != 0 {
			t.Error("own schema removal not confirmed")
		}
		_ = base.Close()
	})
	ledger := store.New(db)
	if err = ledger.Migrate(ctx); err != nil {
		t.Fatalf("additive isolated ledger migration failed: %v", err)
	}
	return db, ledger
}

func natsFixture(t *testing.T) (*natsserver.Server, *nats.Conn, *nats.Conn) {
	t.Helper()
	token := hex.EncodeToString(randomBytes(t, 32))
	broker, err := natsserver.NewServer(&natsserver.Options{Host: "127.0.0.1", Port: natsserver.RANDOM_PORT, Authorization: token, NoLog: true, NoSigs: true})
	if err != nil {
		t.Fatal("embedded NATS server creation failed")
	}
	go broker.Start()
	if !broker.ReadyForConnections(5 * time.Second) {
		broker.Shutdown()
		t.Fatal("embedded NATS server did not become ready")
	}
	address := broker.Addr().String()
	if host, _, err := net.SplitHostPort(address); err != nil || host != "127.0.0.1" {
		broker.Shutdown()
		t.Fatal("NATS fixture must bind exact loopback")
	}
	connections := []*nats.Conn{}
	t.Cleanup(func() {
		for _, connection := range connections {
			connection.Close()
		}
		broker.Shutdown()
		broker.WaitForShutdown()
		assertClosed(t, address)
	})
	for range 2 {
		connection, err := nats.Connect("nats://"+address, nats.Token(token), nats.NoReconnect(), nats.Timeout(time.Second))
		if err != nil {
			t.Fatal("authenticated local NATS client connection failed")
		}
		connections = append(connections, connection)
	}
	return broker, connections[0], connections[1]
}

func httpJSON(t *testing.T, s *httptest.Server, token, method, path string, payload any, expected int, target any) {
	t.Helper()
	var body io.Reader
	if payload != nil {
		b, err := json.Marshal(payload)
		if err != nil {
			t.Fatal(err)
		}
		body = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, s.URL+path, body)
	if err != nil {
		t.Fatal("HTTP fixture request invalid")
	}
	req.Header.Set("Authorization", "Bearer "+token)
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	client := s.Client()
	client.Timeout = 5 * time.Second
	reply, err := client.Do(req)
	if err != nil {
		t.Fatal("real local machine API request failed")
	}
	defer reply.Body.Close()
	b, err := io.ReadAll(io.LimitReader(reply.Body, 65537))
	if err != nil || len(b) > 65536 || reply.StatusCode != expected {
		t.Fatalf("machine API response mismatch: status %d expected %d", reply.StatusCode, expected)
	}
	if bytes.Contains(b, []byte("synthetic-private-output")) {
		t.Fatal("raw native output leaked into the machine API")
	}
	if target != nil && json.Unmarshal(b, target) != nil {
		t.Fatal("machine API JSON invalid")
	}
}
func assertRejectedWire(t *testing.T, connection *nats.Conn, subject string, wire []byte) {
	t.Helper()
	reply, err := connection.Request(subject, wire, time.Second)
	if err != nil || !bytes.Equal(reply.Data, []byte("rejected")) {
		t.Fatal("forged device message was not rejected over the actual socket")
	}
}
func assertCount(t *testing.T, db *sql.DB, table string, expected int) {
	t.Helper()
	if table != "railtime_execution_results" && table != "railtime_execution_outbox" {
		t.Fatal("non-allowlisted count table")
	}
	var count int
	if err := db.QueryRow("SELECT count(*) FROM " + table).Scan(&count); err != nil || count != expected {
		t.Fatalf("isolated %s row count mismatch: got %d want %d", table, count, expected)
	}
}
func assertClosed(t *testing.T, address string) {
	t.Helper()
	connection, err := net.DialTimeout("tcp", address, 150*time.Millisecond)
	if err == nil {
		_ = connection.Close()
		t.Error("owned fixture listener remains open")
	}
}
func randomBytes(t *testing.T, length int) []byte {
	t.Helper()
	b := make([]byte, length)
	if _, err := rand.Read(b); err != nil {
		t.Fatal("fixture randomness unavailable")
	}
	return b
}
func newID(t *testing.T) string {
	t.Helper()
	id, err := protocol.NewID()
	if err != nil {
		t.Fatal("fixture ID unavailable")
	}
	return id
}

var _ = errors.Is // keep compile-time error wrapping compatibility explicit

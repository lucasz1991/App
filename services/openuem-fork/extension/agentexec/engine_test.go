package agentexec

import (
	"bytes"
	"context"
	"errors"
	"github.com/dgraph-io/badger/v4"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"railtime.local/openuem-extension/protocol"
)

func command() protocol.Command {
	r, _ := protocol.NewID()
	id, _ := protocol.NewID()
	cor, _ := protocol.NewID()
	c := protocol.Command{Version: protocol.Version, RunID: r, CommandID: id, CorrelationID: cor, AgentID: "test-agent", ProfileID: 1, TenantID: 2, SiteID: 3, IssuedAt: time.Now().UTC().Add(-time.Minute), ExpiresAt: time.Now().UTC().Add(time.Hour), Snapshot: []byte("immutable: test"), ExpectedTasks: []string{"task_1_1"}}
	c.PayloadSHA256 = c.Fingerprint()
	return c
}

func fixture(t *testing.T) (*Journal, string, Config) {
	t.Helper()
	root := filepath.Join(t.TempDir(), "private")
	if err := makePrivateDirectory(root); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(root, "journal")
	if err := Initialize(path, "test-agent", "fixture-ledger"); err != nil {
		t.Fatal(err)
	}
	j, err := Open(path, "test-agent", "fixture-ledger")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = j.Close() })
	return j, path, Config{Enabled: true, AgentID: "test-agent", TenantID: 2, SiteID: 3, KeyID: "test-key", LedgerID: "fixture-ledger", Key: bytes.Repeat([]byte{7}, 32)}
}

func wire(t *testing.T, c protocol.Command, cfg Config) []byte {
	t.Helper()
	b, err := protocol.Sign(protocol.CommandContext, cfg.KeyID, cfg.Key, c)
	if err != nil {
		t.Fatal(err)
	}
	return b
}
func success(c protocol.Command, begin func() error) Outcome {
	if begin() != nil {
		return Outcome{Error: "start_refused"}
	}
	return Outcome{Status: "succeeded", Tasks: []protocol.TaskResult{{Name: c.ExpectedTasks[0], Succeeded: true}}}
}
func persisted(cfg Config) Transport {
	return func(subject string, b []byte) ([]byte, error) {
		var r protocol.Result
		if err := protocol.Verify(protocol.ResultContext, cfg.KeyID, cfg.Key, b, &r); err != nil {
			return nil, err
		}
		return protocol.Sign(protocol.AckContext, cfg.KeyID, cfg.Key, protocol.Ack{Version: protocol.Version, RunID: r.RunID, PayloadSHA256: r.PayloadSHA256, EventID: r.EventID, Status: "persisted"})
	}
}

func TestDuplicateExecutesOnceAndResultRetryIsStable(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	count := 0
	attempt := 0
	event := ""
	e, _ := NewEngine(cfg, j, func(c protocol.Command, b func() error) Outcome { count++; return success(c, b) }, func(s string, b []byte) ([]byte, error) {
		attempt++
		var r protocol.Result
		_ = protocol.Verify(protocol.ResultContext, cfg.KeyID, cfg.Key, b, &r)
		if event != "" && event != r.EventID {
			t.Error("event changed")
		}
		event = r.EventID
		if attempt == 1 {
			return nil, errors.New("offline")
		}
		return persisted(cfg)(s, b)
	})
	for i := 0; i < 2; i++ {
		if _, err := e.Accept(wire(t, c, cfg)); err != nil {
			t.Fatal(err)
		}
		if err := e.Step(); err != nil {
			t.Fatal(err)
		}
	}
	r, _ := j.Get(c.RunID)
	if count != 1 || attempt != 2 || !r.Delivered {
		t.Fatalf("count=%d attempt=%d delivered=%v", count, attempt, r.Delivered)
	}
}

func TestDeliveredTerminalDuplicateReplaysSameSavedResult(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	executed := 0
	events := []string{}
	e, _ := NewEngine(cfg, j, func(c protocol.Command, b func() error) Outcome { executed++; return success(c, b) }, func(s string, b []byte) ([]byte, error) {
		var r protocol.Result
		if err := protocol.Verify(protocol.ResultContext, cfg.KeyID, cfg.Key, b, &r); err != nil {
			return nil, err
		}
		events = append(events, r.EventID)
		return persisted(cfg)(s, b)
	})
	for i := 0; i < 2; i++ {
		if _, err := e.Accept(wire(t, c, cfg)); err != nil {
			t.Fatal(err)
		}
		if err := e.Step(); err != nil {
			t.Fatal(err)
		}
	}
	if executed != 1 || len(events) != 2 || events[0] != events[1] {
		t.Fatalf("executed=%d results=%d stable=%v", executed, len(events), len(events) == 2 && events[0] == events[1])
	}
}

func TestStoppedEngineRejectsFurtherAcceptance(t *testing.T) {
	j, _, cfg := fixture(t)
	e, _ := NewEngine(cfg, j, success, persisted(cfg))
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	e.Run(ctx)
	if _, err := e.Accept(wire(t, command(), cfg)); err == nil {
		t.Fatal("stopped engine accepted work")
	}
}

func TestJournalFailurePreventsNativeEffects(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	effects := 0
	e, _ := NewEngine(cfg, j, func(c protocol.Command, begin func() error) Outcome {
		_ = j.Close()
		if begin() == nil {
			effects++
		}
		return Outcome{Status: "failed"}
	}, persisted(cfg))
	_, _ = e.Accept(wire(t, c, cfg))
	if e.Step() == nil {
		t.Fatal("journal failure hidden")
	}
	if effects != 0 {
		t.Fatal("effect without durable start")
	}
}

func TestConfigChangesInvalidateEnrollment(t *testing.T) {
	_, _, cfg := fixture(t)
	if !cfg.SameEnrollment(cfg) {
		t.Fatal("same config mismatch")
	}
	for _, change := range []func(*Config){func(c *Config) { c.Enabled = false }, func(c *Config) { c.Key = bytes.Repeat([]byte{8}, 32) }, func(c *Config) { c.KeyID = "rotated" }, func(c *Config) { c.LedgerID = "changed" }, func(c *Config) { c.TenantID++ }, func(c *Config) { c.SiteID++ }} {
		other := cfg
		change(&other)
		if cfg.SameEnrollment(other) {
			t.Fatal("changed enrollment matched")
		}
	}
}

func TestConcurrentDuplicateAcceptance(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	var count atomic.Int32
	e, _ := NewEngine(cfg, j, func(c protocol.Command, b func() error) Outcome { count.Add(1); return success(c, b) }, persisted(cfg))
	w := wire(t, c, cfg)
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); _, _ = e.Accept(w) }()
	}
	wg.Wait()
	if err := e.Step(); err != nil {
		t.Fatal(err)
	}
	if count.Load() != 1 {
		t.Fatal("reexecuted")
	}
}

func TestJournalIdentityAndPayloadReuseRejected(t *testing.T) {
	j, _, _ := fixture(t)
	c := command()
	if _, _, err := j.Prepare(c, time.Now()); err != nil {
		t.Fatal(err)
	}
	for _, change := range []func(*protocol.Command){func(v *protocol.Command) { v.Snapshot = []byte("different") }, func(v *protocol.Command) { v.RunID, _ = protocol.NewID() }, func(v *protocol.Command) { v.RunID, _ = protocol.NewID(); v.CorrelationID, _ = protocol.NewID() }} {
		v := c
		change(&v)
		v.PayloadSHA256 = v.Fingerprint()
		if _, _, err := j.Prepare(v, time.Now()); !errors.Is(err, ErrConflict) {
			t.Fatalf("expected conflict got %v", err)
		}
	}
}

func TestRestartAfterStartBecomesUncertainAndNeverExecutes(t *testing.T) {
	j, path, cfg := fixture(t)
	c := command()
	_, _, _ = j.Prepare(c, time.Now())
	if err := j.Start(c.RunID, time.Now()); err != nil {
		t.Fatal(err)
	}
	_ = j.Close()
	reopened, err := Open(path, c.AgentID, cfg.LedgerID)
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	e, _ := NewEngine(cfg, reopened, func(protocol.Command, func() error) Outcome { t.Fatal("uncertain run executed"); return Outcome{} }, persisted(cfg))
	if err := e.Step(); err != nil {
		t.Fatal(err)
	}
	r, _ := reopened.Get(c.RunID)
	if r.State != "uncertain" || !r.Delivered {
		t.Fatal(r.State)
	}
}

func TestPreparedRestartExecutesOnce(t *testing.T) {
	j, path, cfg := fixture(t)
	c := command()
	_, _, _ = j.Prepare(c, time.Now())
	_ = j.Close()
	reopened, err := Open(path, c.AgentID, cfg.LedgerID)
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	count := 0
	e, _ := NewEngine(cfg, reopened, func(c protocol.Command, b func() error) Outcome { count++; return success(c, b) }, persisted(cfg))
	_ = e.Step()
	_ = e.Step()
	if count != 1 {
		t.Fatal(count)
	}
}

func TestExpiredCommandProducesDurableFailure(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	c.IssuedAt = time.Now().Add(-2 * time.Hour)
	c.ExpiresAt = time.Now().Add(-time.Hour)
	c.PayloadSHA256 = c.Fingerprint()
	e, _ := NewEngine(cfg, j, func(protocol.Command, func() error) Outcome { t.Fatal("expired executed"); return Outcome{} }, persisted(cfg))
	if _, err := e.Accept(wire(t, c, cfg)); err != nil {
		t.Fatal(err)
	}
	_ = e.Step()
	r, _ := j.Get(c.RunID)
	if r.State != "failed" || r.Result.Error != "expired_before_execution" {
		t.Fatal(r)
	}
	if _, _, err := j.Prepare(c, time.Now().Add(48*time.Hour)); err != nil {
		t.Fatal("expired duplicate lost", err)
	}
}

func TestBadSignaturesAndScopeNeverPersist(t *testing.T) {
	j, _, cfg := fixture(t)
	e, _ := NewEngine(cfg, j, success, persisted(cfg))
	for _, change := range []func(*protocol.Command){func(c *protocol.Command) { c.TenantID++ }, func(c *protocol.Command) { c.SiteID++ }, func(c *protocol.Command) { c.AgentID = "other-agent" }} {
		c := command()
		change(&c)
		c.PayloadSHA256 = c.Fingerprint()
		if _, err := e.Accept(wire(t, c, cfg)); err == nil {
			t.Fatal("wrong scope accepted")
		}
	}
	c := command()
	w := wire(t, c, cfg)
	w[len(w)/2] ^= 1
	if _, err := e.Accept(w); err == nil {
		t.Fatal("tamper accepted")
	}
	rows, _ := j.Pending(128)
	if len(rows) != 0 {
		t.Fatal("untrusted persisted")
	}
}

func TestInvalidResultAndPanicAreUncertain(t *testing.T) {
	for _, mode := range []string{"missing", "panic"} {
		t.Run(mode, func(t *testing.T) {
			j, _, cfg := fixture(t)
			c := command()
			e, _ := NewEngine(cfg, j, func(c protocol.Command, b func() error) Outcome {
				if b() != nil {
					t.Fatal("begin")
				}
				if mode == "panic" {
					panic("synthetic")
				}
				return Outcome{Status: "succeeded"}
			}, persisted(cfg))
			_, _ = e.Accept(wire(t, c, cfg))
			_ = e.Step()
			r, _ := j.Get(c.RunID)
			if r.State != "uncertain" {
				t.Fatal(r.State)
			}
		})
	}
}

func TestBusyDoesNotStartAndPreflightNeverClaimsSuccess(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	busy := true
	e, _ := NewEngine(cfg, j, func(c protocol.Command, b func() error) Outcome {
		if busy {
			return Outcome{Error: "native_busy"}
		}
		return Outcome{Status: "succeeded"}
	}, persisted(cfg))
	_, _ = e.Accept(wire(t, c, cfg))
	_ = e.Step()
	r, _ := j.Get(c.RunID)
	if r.State != "prepared" {
		t.Fatal(r.State)
	}
	busy = false
	_ = e.Step()
	r, _ = j.Get(c.RunID)
	if r.State != "failed" {
		t.Fatal(r.State)
	}
}

func TestWrongAckDoesNotRemoveOutbox(t *testing.T) {
	j, _, cfg := fixture(t)
	c := command()
	e, _ := NewEngine(cfg, j, success, func(s string, b []byte) ([]byte, error) {
		return protocol.Sign(protocol.AckContext, cfg.KeyID, cfg.Key, protocol.Ack{Version: protocol.Version, RunID: c.RunID, PayloadSHA256: c.PayloadSHA256, EventID: "wrong", Status: "persisted"})
	})
	_, _ = e.Accept(wire(t, c, cfg))
	_ = e.Step()
	r, _ := j.Get(c.RunID)
	if r.Delivered {
		t.Fatal("wrong ack accepted")
	}
}

func TestMissingJournalAndWrongEnrollmentNeverReinitialize(t *testing.T) {
	j, path, cfg := fixture(t)
	_ = j.Close()
	if Initialize(path, cfg.AgentID, cfg.LedgerID) == nil {
		t.Fatal("overwritten")
	}
	if _, err := Open(path, cfg.AgentID, "other-ledger"); err == nil {
		t.Fatal("wrong enrollment")
	}
	if _, err := Open(filepath.Join(filepath.Dir(path), "missing"), cfg.AgentID, cfg.LedgerID); err == nil {
		t.Fatal("created missing ledger")
	}
	if _, err := os.Lstat(filepath.Join(filepath.Dir(path), "missing")); !os.IsNotExist(err) {
		t.Fatal("missing path mutated")
	}
}

func TestCorruptRecordFailsClosedOnRestart(t *testing.T) {
	j, path, cfg := fixture(t)
	c := command()
	_, _, _ = j.Prepare(c, time.Now())
	if err := j.db.Update(func(tx *badger.Txn) error { return tx.Set([]byte("run/"+c.RunID), []byte(`{"state":"prepared"}`)) }); err != nil {
		t.Fatal(err)
	}
	_ = j.Close()
	if reopened, err := Open(path, cfg.AgentID, cfg.LedgerID); err == nil {
		_ = reopened.Close()
		t.Fatal("corrupt journal accepted")
	}
}

func TestMissingConfigDisabledAndStrictPrivateConfig(t *testing.T) {
	root := filepath.Join(t.TempDir(), "private")
	if err := makePrivateDirectory(root); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadConfig(root); !errors.Is(err, ErrDisabled) {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "execution.json"), []byte(`{"enabled":true,"agent_id":"test-agent","tenant_id":2,"site_id":3,"key_id":"test-key","ledger_id":"test-ledger","unknown":true}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadConfig(root); err == nil {
		t.Fatal("unknown config field accepted")
	}
	if err := os.WriteFile(filepath.Join(root, "execution.json"), []byte(`{"enabled":true,"agent_id":"test-agent","tenant_id":2,"site_id":3,"key_id":"test-key","ledger_id":"test-ledger"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadConfig(root); err == nil {
		t.Fatal("missing key accepted")
	}
	if err := os.WriteFile(filepath.Join(root, "agent-hmac.key"), bytes.Repeat([]byte("ab"), 32), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadConfig(root); err != nil {
		t.Fatal(err)
	}
}

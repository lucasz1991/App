package common

import (
	"context"
	"errors"
	"path/filepath"
	"testing"

	"railtime.local/openuem-extension/agentexec"
	"railtime.local/openuem-extension/protocol"
)

type engineTransport struct {
	engine    *agentexec.Engine
	loseReply bool
}

func (t *engineTransport) Connected() bool { return true }
func (t *engineTransport) Request(_ context.Context, _ string, wire []byte) ([]byte, error) {
	ack, err := t.engine.Accept(wire)
	if err != nil {
		return nil, err
	}
	if t.loseReply {
		t.loseReply = false
		return nil, errors.New("synthetic acceptance receipt loss")
	}
	return ack, nil
}

func TestRailTimeRealAgentJournalAndPostgresResultReplay(t *testing.T) {
	f := newPGFixture(t)
	ctx := context.Background()
	journalPath := filepath.Join(t.TempDir(), "execution-journal")
	const ledgerID = "synthetic-explicit-enrollment"
	if err := agentexec.Initialize(journalPath, f.request.AgentID, ledgerID); err != nil {
		t.Fatal(err)
	}
	journal, err := agentexec.Open(journalPath, f.request.AgentID, ledgerID)
	if err != nil {
		t.Fatal(err)
	}
	defer journal.Close()
	key, _ := f.key.Bytes()
	cfg := agentexec.Config{Enabled: true, AgentID: f.request.AgentID, TenantID: f.principal.TenantID, SiteID: f.principal.SiteID, KeyID: f.key.KeyID, LedgerID: ledgerID, Key: key}
	nativeExecutions := 0
	blockResult := true
	loseDurableReceipt := false
	deliveredEvents := []string{}
	engine, err := agentexec.NewEngine(cfg, journal, func(c protocol.Command, begin func() error) agentexec.Outcome {
		if err := begin(); err != nil {
			return agentexec.Outcome{Status: "failed", Error: "synthetic_begin_failed"}
		}
		nativeExecutions++
		tasks := []protocol.TaskResult{}
		for _, name := range c.ExpectedTasks {
			tasks = append(tasks, protocol.TaskResult{Name: name, Succeeded: true})
		}
		return agentexec.Outcome{Status: "succeeded", Tasks: tasks}
	}, func(subject string, wire []byte) ([]byte, error) {
		if blockResult {
			return nil, errors.New("synthetic offline result path")
		}
		expected, _ := protocol.ResultSubject(f.request.AgentID)
		if subject != expected {
			return nil, errors.New("wrong result subject")
		}
		var result protocol.Result
		if err := protocol.Verify(protocol.ResultContext, f.key.KeyID, key, wire, &result); err != nil {
			return nil, err
		}
		deliveredEvents = append(deliveredEvents, result.EventID)
		ack, err := f.service.ReceiveResult(ctx, f.request.AgentID, wire)
		if err != nil {
			return nil, err
		}
		if loseDurableReceipt {
			loseDurableReceipt = false
			return nil, errors.New("synthetic persisted receipt loss")
		}
		return ack, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	f.service.Transport = &engineTransport{engine: engine, loseReply: true}
	v := f.accept(t)
	if err = f.service.DispatchOne(ctx); err == nil {
		t.Fatal("lost acceptance receipt not observed")
	}
	if err = engine.Step(); err != nil {
		t.Fatal(err)
	}
	row, err := journal.Get(v.RunID)
	if err != nil || row.Result == nil || row.Delivered || nativeExecutions != 1 {
		t.Fatal("native execution journal not durable")
	}
	eventID := row.Result.EventID
	if _, err = f.db.ExecContext(ctx, `UPDATE railtime_execution_outbox SET next_attempt='2000-01-01T00:00:00Z'`); err != nil {
		t.Fatal(err)
	}
	// Agent now answers exact duplicate with its terminal EventID. Server must
	// accept that as a receipt, NOT as proof of the still-undelivered result.
	if err = f.service.DispatchOne(ctx); err != nil {
		t.Fatal(err)
	}
	view, err := f.service.Get(ctx, f.principal, v.RunID)
	if err != nil || view.Status != "accepted" || view.Result != nil {
		t.Fatal("terminal duplicate ack was mistaken for completion")
	}
	blockResult = false
	loseDurableReceipt = true
	if err = engine.Step(); err != nil {
		t.Fatal(err)
	}
	row, err = journal.Get(v.RunID)
	if err != nil || row.Delivered {
		t.Fatal("lost durable receipt incorrectly cleared agent outbox")
	}
	if err = engine.Step(); err != nil {
		t.Fatal(err)
	}
	row, err = journal.Get(v.RunID)
	if err != nil || !row.Delivered || nativeExecutions != 1 || len(deliveredEvents) != 2 || deliveredEvents[0] != eventID || deliveredEvents[1] != eventID {
		t.Fatal("retry changed result identity or reran native effects")
	}
	view, err = f.service.Get(ctx, f.principal, v.RunID)
	if err != nil || view.Status != "succeeded" || view.Result == nil || view.Result.EventID != eventID {
		t.Fatal("server durable completion missing")
	}
	if err = journal.Close(); err != nil {
		t.Fatal(err)
	}
	journal, err = agentexec.Open(journalPath, f.request.AgentID, ledgerID)
	if err != nil {
		t.Fatal(err)
	}
	defer journal.Close()
	row, err = journal.Get(v.RunID)
	if err != nil || row.Result == nil || row.Result.EventID != eventID || !row.Delivered {
		t.Fatal("agent journal restart lost result receipt")
	}
}

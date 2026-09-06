package agentexec

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"time"

	"railtime.local/openuem-extension/protocol"
)

var ErrBusy = errors.New("native executor busy")

type Outcome struct {
	Status string
	Error  string
	Tasks  []protocol.TaskResult
}

// Execute must acquire its native lock and finish preflight BEFORE begin. No
// device effects may occur unless begin succeeds. A panic after begin becomes
// uncertain, not permission to run the script again.
type Execute func(protocol.Command, func() error) Outcome
type Transport func(subject string, wire []byte) ([]byte, error)

type Engine struct {
	cfg       Config
	journal   *Journal
	execute   Execute
	transport Transport
	mu        sync.Mutex
	halted atomic.Bool
}

func NewEngine(c Config, j *Journal, execute Execute, transport Transport) (*Engine, error) {
	if !c.Enabled || j == nil || execute == nil || transport == nil || c.AgentID != j.agentID || c.TenantID <= 0 || c.SiteID <= 0 {
		return nil, ErrJournal
	}
	if _, err := protocol.Sign(protocol.AckContext, c.KeyID, c.Key, protocol.Ack{}); err != nil {
		return nil, ErrJournal
	}
	return &Engine{cfg: c, journal: j, execute: execute, transport: transport}, nil
}

func (e *Engine) Accept(wire []byte) ([]byte, error) {
	if e.halted.Load(){return nil,ErrJournal}
	var c protocol.Command
	if protocol.Verify(protocol.CommandContext, e.cfg.KeyID, e.cfg.Key, wire, &c) != nil || c.Validate() != nil || c.AgentID != e.cfg.AgentID || c.TenantID != e.cfg.TenantID || c.SiteID != e.cfg.SiteID {
		return nil, ErrState
	}
	r, _, err := e.journal.Prepare(c, time.Now().UTC())
	ack := protocol.Ack{Version: protocol.Version, RunID: c.RunID, PayloadSHA256: c.PayloadSHA256, Status: "accepted"}
	if err != nil {
		ack.Status, ack.Error = "rejected", "journal_or_identity_rejected"
	} else if r.Result != nil {
		// Prepare durably reopens the result outbox for an exact duplicate. This
		// also repairs a lost/restored server receipt without executing again.
		ack.EventID = r.Result.EventID
	}
	return protocol.Sign(protocol.AckContext, e.cfg.KeyID, e.cfg.Key, ack)
}

// Step processes one bounded journal page. Delivery failures leave results in
// the durable outbox; retries never revisit the native execution function.
func (e *Engine) Step() error {
	return e.step(context.Background())
}

func (e *Engine) step(ctx context.Context) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	rows, err := e.journal.Pending(32)
	if err != nil {
		return ErrJournal
	}
	for _, row := range rows {
		if ctx.Err() != nil {
			return nil
		}
		if row.State == "prepared" {
			if err := e.run(ctx, row.Command.RunID); err != nil {
				return err
			}
		}
		current, err := e.journal.Get(row.Command.RunID)
		if err != nil {
			return ErrJournal
		}
		if current.Result != nil && !current.Delivered {
			e.deliver(current)
		}
	}
	return nil
}

func (e *Engine) run(ctx context.Context, runID string) error {
	r, err := e.journal.Get(runID)
	if err != nil {
		return ErrJournal
	}
	if r.State != "prepared" {
		return nil
	}
	if !time.Now().Before(r.Command.ExpiresAt) {
		_, err := e.journal.Finish(runID, "failed", "expired_before_execution", nil)
		return err
	}
	begun := false
	outcome := func() (out Outcome) {
		defer func() {
			if recover() != nil {
				if begun {
					out = Outcome{Status: "uncertain", Error: "native_executor_panicked"}
				} else {
					out = Outcome{Status: "failed", Error: "native_preflight_panicked"}
				}
			}
		}()
		return e.execute(r.Command, func() error {
			if begun || ctx.Err() != nil {
				return ErrState
			}
			if err := e.journal.Start(runID, time.Now().UTC()); err != nil {
				return err
			}
			begun = true
			return nil
		})
	}()
	if !begun {
		if outcome.Error == "native_busy" {
			return nil
		} // still prepared; no effects
		outcome = Outcome{Status: "failed", Error: "native_preflight_rejected"}
	}
	if outcome.Status != "succeeded" && outcome.Status != "failed" && outcome.Status != "uncertain" {
		outcome = Outcome{Status: "uncertain", Error: "invalid_native_result"}
	}
	_, err = e.journal.Finish(runID, outcome.Status, outcome.Error, outcome.Tasks)
	if err != nil && begun {
		// Incomplete/invalid native reports cannot prove success. If storage itself
		// failed this also fails, leaving executing -> uncertain at next restart.
		_, err = e.journal.Finish(runID, "uncertain", "invalid_or_incomplete_native_result", nil)
	}
	return err
}

func (e *Engine) deliver(r Record) {
	wire, err := protocol.Sign(protocol.ResultContext, e.cfg.KeyID, e.cfg.Key, r.Result)
	if err != nil {
		return
	}
	subject, _ := protocol.ResultSubject(e.cfg.AgentID)
	reply, err := e.transport(subject, wire)
	if err != nil {
		return
	}
	var ack protocol.Ack
	if protocol.Verify(protocol.AckContext, e.cfg.KeyID, e.cfg.Key, reply, &ack) != nil {
		return
	}
	if ack.Version != protocol.Version || ack.Status != "persisted" || ack.RunID != r.Command.RunID || ack.PayloadSHA256 != r.Command.PayloadSHA256 || ack.EventID != r.Result.EventID {
		return
	}
	_ = e.journal.Acknowledge(ack.RunID, ack.EventID, ack.PayloadSHA256)
}

func (e *Engine) Run(ctx context.Context) {
	defer e.halted.Store(true)
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}
		if e.step(ctx) != nil {
			return
		} // fail closed, keep the journal for repair
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

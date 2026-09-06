// Package agentexec provides the opt-in agent's durable, at-most-once run journal.
// A missing/corrupt journal is an error, never an invitation to execute again.
package agentexec

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/dgraph-io/badger/v4"
	"railtime.local/openuem-extension/protocol"
)

var (
	ErrJournal  = errors.New("execution journal unavailable")
	ErrConflict = errors.New("execution identifier already bound to another request")
	ErrState    = errors.New("execution transition not permitted")
)

const maxRecords = 10000

type Record struct {
	Command   protocol.Command `json:"command"`
	State     string           `json:"state"`
	Result    *protocol.Result `json:"result,omitempty"`
	Delivered bool             `json:"delivered"`
}

type identity struct {
	AgentID  string `json:"agent_id"`
	LedgerID string `json:"ledger_id"`
}

type Journal struct {
	mu      sync.Mutex
	db      *badger.DB
	agentID string
	closed  bool
}

func options(path string) badger.Options {
	return badger.DefaultOptions(path).WithLogger(nil).WithSyncWrites(true).
		WithMemTableSize(16 << 20).WithValueLogFileSize(16 << 20).
		WithNumMemtables(2).WithNumCompactors(2)
}

// Initialize is an explicit enrollment operation. It refuses every existing path.
// The service never calls this function while opening an enrolled installation.
func Initialize(path, agentID, ledgerID string) error {
	if _, err := protocol.CommandSubject(agentID); err != nil || ledgerID == "" || len(ledgerID) > 128 {
		return ErrJournal
	}
	if _, err := os.Lstat(path); !os.IsNotExist(err) {
		return ErrJournal
	}
	if err := makePrivateDirectory(path); err != nil {
		return ErrJournal
	}
	db, err := badger.Open(options(path))
	if err != nil {
		return ErrJournal
	}
	defer db.Close()
	b, _ := json.Marshal(identity{agentID, ledgerID})
	if err := db.Update(func(tx *badger.Txn) error { return tx.Set([]byte("identity"), b) }); err != nil {
		return ErrJournal
	}
	return db.Sync()
}

func Open(path, agentID, ledgerID string) (*Journal, error) {
	if err := CheckPrivatePath(path, true); err != nil {
		return nil, ErrJournal
	}
	if err := CheckPrivatePath(filepath.Join(path, "MANIFEST"), false); err != nil {
		return nil, ErrJournal
	}
	entries, err := os.ReadDir(path)
	if err != nil || len(entries) > 100000 {
		return nil, ErrJournal
	}
	for _, entry := range entries {
		if entry.IsDir() || CheckPrivatePath(filepath.Join(path, entry.Name()), false) != nil {
			return nil, ErrJournal
		}
	}
	db, err := badger.Open(options(path))
	if err != nil {
		return nil, ErrJournal
	}
	j := &Journal{db: db, agentID: agentID}
	err = db.View(func(tx *badger.Txn) error {
		b, err := value(tx, "identity")
		if err != nil {
			return err
		}
		var id identity
		if json.Unmarshal(b, &id) != nil || id.AgentID != agentID || id.LedgerID != ledgerID {
			return ErrJournal
		}
		return nil
	})
	if err != nil {
		db.Close()
		return nil, ErrJournal
	}
	// Recovery is deliberately conservative. A started process may have changed
	// the device even when its final result never reached durable storage.
	err = db.Update(func(tx *badger.Txn) error {
		it := tx.NewIterator(badger.DefaultIteratorOptions)
		defer it.Close()
		for it.Seek([]byte("run/")); it.ValidForPrefix([]byte("run/")); it.Next() {
			var r Record
			if err := it.Item().Value(func(b []byte) error { return json.Unmarshal(b, &r) }); err != nil {
				return err
			}
			if err := validateRecord(r, agentID); err != nil {
				return err
			}
			if r.State == "executing" {
				result, err := terminal(r.Command, "uncertain", "agent_restarted_after_execution_started", nil)
				if err != nil {
					return err
				}
				r.State, r.Result, r.Delivered = "uncertain", &result, false
				if err := put(tx, r); err != nil {
					return err
				}
			}
		}
		return nil
	})
	if err != nil {
		db.Close()
		return nil, ErrJournal
	}
	return j, nil
}

func validateRecord(r Record, agentID string) error {
	if r.Command.AgentID != agentID || r.Command.Validate() != nil {
		return ErrJournal
	}
	switch r.State {
	case "prepared", "executing":
		if r.Result != nil || r.Delivered {
			return ErrJournal
		}
	case "succeeded", "failed", "uncertain":
		if r.Result == nil || r.Result.Status != r.State || r.Result.ValidateFor(r.Command) != nil {
			return ErrJournal
		}
	default:
		return ErrJournal
	}
	return nil
}

func value(tx *badger.Txn, key string) ([]byte, error) {
	item, err := tx.Get([]byte(key))
	if err != nil {
		return nil, err
	}
	return item.ValueCopy(nil)
}

func read(tx *badger.Txn, runID string) (Record, error) {
	b, err := value(tx, "run/"+runID)
	if err != nil {
		return Record{}, err
	}
	var r Record
	err = json.Unmarshal(b, &r)
	return r, err
}

func put(tx *badger.Txn, r Record) error {
	b, err := json.Marshal(r)
	if err != nil {
		return err
	}
	return tx.Set([]byte("run/"+r.Command.RunID), b)
}

func (j *Journal) Prepare(c protocol.Command, now time.Time) (Record, bool, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed || c.AgentID != j.agentID || c.Validate() != nil {
		return Record{}, false, ErrJournal
	}
	var record Record
	fresh := false
	err := j.db.Update(func(tx *badger.Txn) error {
		r, err := read(tx, c.RunID)
		if err == nil {
			if validateRecord(r, j.agentID) != nil {
				return ErrJournal
			}
			if r.Command.PayloadSHA256 != c.PayloadSHA256 {
				return ErrConflict
			}
			if r.Result != nil && r.Delivered {
				r.Delivered = false
				if err := put(tx, r); err != nil {
					return err
				}
			}
			record = r
			return nil
		}
		if !errors.Is(err, badger.ErrKeyNotFound) {
			return ErrJournal
		}
		// Both RailTime identifiers remain unique for the life of the journal.
		for _, key := range []string{"correlation/" + c.CorrelationID, "command/" + c.CommandID} {
			if _, err := tx.Get([]byte(key)); err == nil {
				return ErrConflict
			} else if !errors.Is(err, badger.ErrKeyNotFound) {
				return ErrJournal
			}
		}
		if now.Before(c.IssuedAt.Add(-5 * time.Minute)) {
			return ErrState
		}
		count := 0
		it := tx.NewIterator(badger.DefaultIteratorOptions)
		for it.Seek([]byte("run/")); it.ValidForPrefix([]byte("run/")); it.Next() {
			count++
		}
		it.Close()
		if count >= maxRecords {
			return ErrJournal
		} // Never evict replay tombstones.
		record = Record{Command: c, State: "prepared"}
		if err := put(tx, record); err != nil {
			return err
		}
		for _, key := range []string{"correlation/" + c.CorrelationID, "command/" + c.CommandID} {
			if err := tx.Set([]byte(key), []byte(c.RunID)); err != nil {
				return err
			}
		}
		fresh = true
		return nil
	})
	return record, fresh, err
}

func (j *Journal) Get(runID string) (Record, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return Record{}, ErrJournal
	}
	var r Record
	err := j.db.View(func(tx *badger.Txn) error { var err error; r, err = read(tx, runID); return err })
	if err == nil {
		err = validateRecord(r, j.agentID)
	}
	return r, err
}

func (j *Journal) Start(runID string, now time.Time) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return ErrJournal
	}
	return j.db.Update(func(tx *badger.Txn) error {
		r, err := read(tx, runID)
		if err != nil {
			return ErrJournal
		}
		if r.State != "prepared" || !now.Before(r.Command.ExpiresAt) {
			return ErrState
		}
		r.State = "executing"
		return put(tx, r)
	})
}

func terminal(c protocol.Command, status, message string, tasks []protocol.TaskResult) (protocol.Result, error) {
	event, err := protocol.NewID()
	if err != nil {
		return protocol.Result{}, err
	}
	r := protocol.Result{Version: protocol.Version, EventID: event, RunID: c.RunID,
		CorrelationID: c.CorrelationID, AgentID: c.AgentID, PayloadSHA256: c.PayloadSHA256,
		Status: status, FinishedAt: time.Now().UTC(), Tasks: tasks, Error: message}
	return r, r.ValidateFor(c)
}

// Finish stores an immutable result before any transport is attempted. Only a
// not-started command may be failed directly; uncertainty is never retried.
func (j *Journal) Finish(runID, status, message string, tasks []protocol.TaskResult) (protocol.Result, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return protocol.Result{}, ErrJournal
	}
	var result protocol.Result
	err := j.db.Update(func(tx *badger.Txn) error {
		r, err := read(tx, runID)
		if err != nil {
			return ErrJournal
		}
		if r.Result != nil {
			result = *r.Result
			return nil
		}
		if r.State != "executing" && !(r.State == "prepared" && status == "failed") {
			return ErrState
		}
		result, err = terminal(r.Command, status, message, tasks)
		if err != nil {
			return err
		}
		r.State, r.Result = status, &result
		return put(tx, r)
	})
	return result, err
}

func (j *Journal) Pending(limit int) ([]Record, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return nil, ErrJournal
	}
	if limit < 1 || limit > 128 {
		limit = 32
	}
	var records []Record
	err := j.db.View(func(tx *badger.Txn) error {
		it := tx.NewIterator(badger.DefaultIteratorOptions)
		defer it.Close()
		for it.Seek([]byte("run/")); it.ValidForPrefix([]byte("run/")); it.Next() {
			var r Record
			if err := it.Item().Value(func(b []byte) error { return json.Unmarshal(b, &r) }); err != nil {
				return err
			}
			if err := validateRecord(r, j.agentID); err != nil {
				return err
			}
			if r.State == "prepared" || (r.Result != nil && !r.Delivered) {
				records = append(records, r)
			}
			if len(records) == limit {
				break
			}
		}
		return nil
	})
	return records, err
}

func (j *Journal) Acknowledge(runID, eventID, payloadHash string) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return ErrJournal
	}
	return j.db.Update(func(tx *badger.Txn) error {
		r, err := read(tx, runID)
		if err != nil {
			return ErrJournal
		}
		if r.Result == nil || r.Result.EventID != eventID || r.Command.PayloadSHA256 != payloadHash {
			return ErrConflict
		}
		r.Delivered = true
		return put(tx, r)
	})
}

func (j *Journal) Close() error {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return nil
	}
	j.closed = true
	return j.db.Close()
}

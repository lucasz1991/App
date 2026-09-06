// Package protocol defines the opt-in RailTime execution protocol. Legacy OpenUEM
// messages are deliberately not compatible with these subjects or signed envelopes.
package protocol

import (
	"bytes"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"time"
)

const (
	Version            = "railtime.execution.v1"
	CommandContext     = Version + ".command"
	ResultContext      = Version + ".result"
	AckContext         = Version + ".ack"
	MaxWireBytes       = 768 * 1024
	MaxSnapshotBytes   = 256 * 1024
	MaxTasks           = 128
	MaxTaskOutputBytes = 8192
)

var identifier = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`)
var uuid = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
var digest = regexp.MustCompile(`^[0-9a-f]{64}$`)

// RunRequest is an administrator's reference to an EXISTING permitted profile.
// The server, never the API caller, materializes Snapshot and ExpectedTasks.
type RunRequest struct {
	CommandID     string `json:"command_id"`
	CorrelationID string `json:"correlation_id"`
	AgentID       string `json:"agent_id"`
	ProfileID     int    `json:"profile_id"`
}

func (r RunRequest) Validate() error {
	if !uuid.MatchString(r.CommandID) || !uuid.MatchString(r.CorrelationID) || !identifier.MatchString(r.AgentID) || r.ProfileID <= 0 {
		return errors.New("invalid command, correlation, agent or profile identifier")
	}
	return nil
}

type Command struct {
	Version       string    `json:"version"`
	RunID         string    `json:"run_id"`
	CommandID     string    `json:"command_id"`
	CorrelationID string    `json:"correlation_id"`
	AgentID       string    `json:"agent_id"`
	ProfileID     int       `json:"profile_id"`
	TenantID      int       `json:"tenant_id"`
	SiteID        int       `json:"site_id"`
	IssuedAt      time.Time `json:"issued_at"`
	ExpiresAt     time.Time `json:"expires_at"`
	Snapshot      []byte    `json:"snapshot"`
	Exclusions    []string  `json:"exclusions"`
	Deployments   []string  `json:"deployments"`
	ExpectedTasks []string  `json:"expected_tasks"`
	PayloadSHA256 string    `json:"payload_sha256"`
}

func (c Command) Fingerprint() string {
	c.PayloadSHA256 = ""
	b, _ := json.Marshal(c)
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// Validate does not reject expiration: a completed old command must still be
// deduplicated and its saved result replayed. Executors check time BEFORE launch.
func (c Command) Validate() error {
	if c.Version != Version || !uuid.MatchString(c.RunID) || c.TenantID <= 0 || c.SiteID <= 0 || c.IssuedAt.IsZero() || !c.ExpiresAt.After(c.IssuedAt) || c.ExpiresAt.Sub(c.IssuedAt) > 24*time.Hour {
		return errors.New("unsupported protocol or invalid run/expiry")
	}
	if err := (RunRequest{c.CommandID, c.CorrelationID, c.AgentID, c.ProfileID}).Validate(); err != nil {
		return err
	}
	if len(c.Snapshot) == 0 || len(c.Snapshot) > MaxSnapshotBytes || len(c.Exclusions) > MaxTasks || len(c.Deployments) > MaxTasks {
		return errors.New("invalid snapshot size")
	}
	if err := validTasks(c.ExpectedTasks); err != nil {
		return err
	}
	if !digest.MatchString(c.PayloadSHA256) || c.Fingerprint() != c.PayloadSHA256 {
		return errors.New("command fingerprint mismatch")
	}
	return nil
}

type TaskResult struct {
	Name      string `json:"name"`
	Succeeded bool   `json:"succeeded"`
	Error     string `json:"error,omitempty"`
	Output    string `json:"output,omitempty"`
}

type Result struct {
	Version       string       `json:"version"`
	EventID       string       `json:"event_id"`
	RunID         string       `json:"run_id"`
	CorrelationID string       `json:"correlation_id"`
	AgentID       string       `json:"agent_id"`
	PayloadSHA256 string       `json:"payload_sha256"`
	Status        string       `json:"status"` // succeeded, failed or uncertain; never accepted
	FinishedAt    time.Time    `json:"finished_at"`
	Tasks         []TaskResult `json:"tasks"`
	Error         string       `json:"error,omitempty"`
}

func (r Result) ValidateFor(c Command) error {
	if err := c.Validate(); err != nil {
		return err
	}
	if r.Version != Version || !uuid.MatchString(r.EventID) || r.RunID != c.RunID || r.CorrelationID != c.CorrelationID || r.AgentID != c.AgentID || r.PayloadSHA256 != c.PayloadSHA256 || r.FinishedAt.IsZero() {
		return errors.New("result identity mismatch")
	}
	if r.Status != "succeeded" && r.Status != "failed" && r.Status != "uncertain" {
		return errors.New("invalid terminal status")
	}
	if len(r.Tasks) > MaxTasks || len(r.Error) > MaxTaskOutputBytes {
		return errors.New("oversized result")
	}
	expected := map[string]bool{}
	seen := map[string]bool{}
	for _, name := range c.ExpectedTasks {
		expected[name] = true
	}
	for _, task := range r.Tasks {
		if !expected[task.Name] || seen[task.Name] || len(task.Error) > MaxTaskOutputBytes || len(task.Output) > MaxTaskOutputBytes {
			return errors.New("unexpected, duplicate or oversized task")
		}
		seen[task.Name] = true
		if r.Status == "succeeded" && (!task.Succeeded || task.Error != "") {
			return errors.New("failed task cannot prove success")
		}
	}
	if r.Status == "succeeded" && (len(seen) != len(expected) || r.Error != "") {
		return errors.New("incomplete tasks cannot prove success")
	}
	return nil
}

type Ack struct {
	Version       string `json:"version"`
	RunID         string `json:"run_id"`
	PayloadSHA256 string `json:"payload_sha256"`
	EventID       string `json:"event_id,omitempty"`
	Status        string `json:"status"` // accepted, busy, persisted, rejected
	Error         string `json:"error,omitempty"`
}

type RunView struct {
	RunID         string   `json:"run_id"`
	CommandID     string   `json:"command_id"`
	CorrelationID string   `json:"correlation_id"`
	AgentID       string   `json:"agent_id"`
	ProfileID     int      `json:"profile_id"`
	PayloadSHA256 string   `json:"payload_sha256"`
	ExpectedTasks []string `json:"expected_tasks"`
	Status        string   `json:"status"` // queued, accepted, succeeded, failed, uncertain
	Result        *Result  `json:"result,omitempty"`
}

type Envelope struct {
	KeyID   string          `json:"key_id"`
	Payload json.RawMessage `json:"payload"`
	MAC     string          `json:"mac"`
}

// Sign/Verify use distinct contexts and a separate >=256-bit secret per device.
// Transport TLS remains required; HMAC binds messages to the exact enrolled peer.
func Sign(context, keyID string, key []byte, value any) ([]byte, error) {
	if !validContext(context) || !identifier.MatchString(keyID) || len(key) < 32 {
		return nil, errors.New("invalid signing configuration")
	}
	payload, err := json.Marshal(value)
	if err != nil {
		return nil, err
	}
	m := hmac.New(sha256.New, key)
	m.Write([]byte(context))
	m.Write([]byte{0})
	m.Write(payload)
	b, err := json.Marshal(Envelope{keyID, payload, hex.EncodeToString(m.Sum(nil))})
	if len(b) > MaxWireBytes {
		return nil, errors.New("envelope too large")
	}
	return b, err
}

func Verify(context, keyID string, key, wire []byte, target any) error {
	if !validContext(context) || !identifier.MatchString(keyID) || len(key) < 32 {
		return errors.New("invalid verification configuration")
	}
	var envelope Envelope
	if err := Decode(wire, &envelope); err != nil {
		return err
	}
	if envelope.KeyID != keyID {
		return errors.New("wrong peer key")
	}
	mac, err := hex.DecodeString(envelope.MAC)
	if err != nil {
		return errors.New("invalid message authentication")
	}
	m := hmac.New(sha256.New, key)
	m.Write([]byte(context))
	m.Write([]byte{0})
	m.Write(envelope.Payload)
	if !hmac.Equal(mac, m.Sum(nil)) {
		return errors.New("invalid message authentication")
	}
	return Decode(envelope.Payload, target)
}

func Decode(b []byte, target any) error {
	if len(b) == 0 || len(b) > MaxWireBytes {
		return errors.New("invalid message size")
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.DisallowUnknownFields()
	if err := d.Decode(target); err != nil {
		return err
	}
	if d.Decode(new(any)) != io.EOF {
		return errors.New("trailing JSON data")
	}
	return nil
}

func CommandSubject(agentID string) (string, error) {
	if !identifier.MatchString(agentID) {
		return "", errors.New("invalid subject peer")
	}
	return "railtime.execution.v1.command." + agentID, nil
}

func ResultSubject(agentID string) (string, error) {
	if !identifier.MatchString(agentID) {
		return "", errors.New("invalid subject peer")
	}
	return "railtime.execution.v1.result." + agentID, nil
}

func NewID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", err
	}
	b[6] = (b[6] & 15) | 64
	b[8] = (b[8] & 63) | 128
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16]), nil
}

func validContext(s string) bool { return s == CommandContext || s == ResultContext || s == AckContext }
func validTasks(names []string) error {
	if len(names) == 0 || len(names) > MaxTasks {
		return errors.New("invalid expected tasks")
	}
	seen := map[string]bool{}
	for _, name := range names {
		if name == "" || len(name) > 256 || seen[name] {
			return errors.New("invalid or duplicate task name")
		}
		seen[name] = true
	}
	return nil
}

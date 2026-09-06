package protocol

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func sampleCommand() Command {
	c := Command{Version: Version, RunID: "11111111-1111-4111-8111-111111111111", CommandID: "22222222-2222-4222-8222-222222222222", CorrelationID: "33333333-3333-4333-8333-333333333333", AgentID: "win-pilot", ProfileID: 7, TenantID: 1, SiteID: 2, IssuedAt: time.Date(2026, 9, 6, 12, 0, 0, 0, time.UTC), Snapshot: []byte("tasks: native-existing-profile\n"), ExpectedTasks: []string{"first", "second"}}
	c.ExpiresAt = c.IssuedAt.Add(time.Hour)
	c.PayloadSHA256 = c.Fingerprint()
	return c
}

func sampleResult(c Command) Result {
	return Result{Version: Version, EventID: "44444444-4444-4444-8444-444444444444", RunID: c.RunID, CorrelationID: c.CorrelationID, AgentID: c.AgentID, PayloadSHA256: c.PayloadSHA256, Status: "succeeded", FinishedAt: c.IssuedAt.Add(time.Minute), Tasks: []TaskResult{{Name: "first", Succeeded: true}, {Name: "second", Succeeded: true}}}
}

func TestFingerprintBindsEveryExecutionField(t *testing.T) {
	base := sampleCommand()
	if err := base.Validate(); err != nil {
		t.Fatal(err)
	}
	changes := map[string]func(*Command){
		"run":         func(c *Command) { c.RunID = "55555555-5555-4555-8555-555555555555" },
		"command":     func(c *Command) { c.CommandID = c.RunID },
		"correlation": func(c *Command) { c.CorrelationID = c.RunID },
		"agent":       func(c *Command) { c.AgentID = "another" },
		"profile":     func(c *Command) { c.ProfileID++ },
		"tenant":      func(c *Command) { c.TenantID++ },
		"site":        func(c *Command) { c.SiteID++ },
		"issue":       func(c *Command) { c.IssuedAt = c.IssuedAt.Add(time.Second) },
		"expiry":      func(c *Command) { c.ExpiresAt = c.ExpiresAt.Add(time.Second) },
		"snapshot":    func(c *Command) { c.Snapshot = []byte("different") },
		"exclusions":  func(c *Command) { c.Exclusions = []string{"exclude"} },
		"deployments": func(c *Command) { c.Deployments = []string{"deploy"} },
		"tasks":       func(c *Command) { c.ExpectedTasks = []string{"substitute"} },
	}
	for name, change := range changes {
		t.Run(name, func(t *testing.T) {
			c := base
			change(&c)
			if c.Validate() == nil {
				t.Fatal("unbound execution field")
			}
		})
	}
}

func TestResultCannotConvertAcceptanceOrIncompleteWorkToSuccess(t *testing.T) {
	c := sampleCommand()
	good := sampleResult(c)
	if err := good.ValidateFor(c); err != nil {
		t.Fatal(err)
	}
	cases := map[string]func(*Result){
		"accepted":          func(r *Result) { r.Status = "accepted" },
		"other-agent":       func(r *Result) { r.AgentID = "other" },
		"other-run":         func(r *Result) { r.RunID = r.EventID },
		"other-correlation": func(r *Result) { r.CorrelationID = r.EventID },
		"other-payload":     func(r *Result) { r.PayloadSHA256 = strings.Repeat("0", 64) },
		"missing-task":      func(r *Result) { r.Tasks = r.Tasks[:1] },
		"duplicate-task":    func(r *Result) { r.Tasks = []TaskResult{r.Tasks[0], r.Tasks[0]} },
		"unknown-task":      func(r *Result) { r.Tasks = []TaskResult{{Name: "third", Succeeded: true}} },
		"failed-task":       func(r *Result) { r.Tasks = []TaskResult{{Name: "first"}, {Name: "second", Succeeded: true}} },
		"error":             func(r *Result) { r.Error = "failed" },
		"oversize":          func(r *Result) { r.Error = strings.Repeat("x", MaxTaskOutputBytes+1) },
	}
	for name, change := range cases {
		t.Run(name, func(t *testing.T) {
			r := good
			change(&r)
			if r.ValidateFor(c) == nil {
				t.Fatal("false success accepted")
			}
		})
	}
	for _, status := range []string{"failed", "uncertain"} {
		r := good
		r.Status = status
		r.Tasks = nil
		if err := r.ValidateFor(c); err != nil {
			t.Fatal(err)
		}
	}
}

func TestSignedMessagesBindDirectionPeerAndExactPayload(t *testing.T) {
	key := bytes.Repeat([]byte{17}, 32)
	c := sampleCommand()
	wire, err := Sign(CommandContext, "win-pilot-key", key, c)
	if err != nil {
		t.Fatal(err)
	}
	var decoded Command
	if err := Verify(CommandContext, "win-pilot-key", key, wire, &decoded); err != nil {
		t.Fatal(err)
	}
	if err := decoded.Validate(); err != nil {
		t.Fatal(err)
	}
	if Verify(ResultContext, "win-pilot-key", key, wire, &decoded) == nil {
		t.Fatal("cross-direction replay")
	}
	if Verify(CommandContext, "other-key", key, wire, &decoded) == nil {
		t.Fatal("cross-peer replay")
	}
	if Verify(CommandContext, "win-pilot-key", bytes.Repeat([]byte{18}, 32), wire, &decoded) == nil {
		t.Fatal("different secret")
	}
	corrupt := bytes.Replace(wire, []byte(`"profile_id":7`), []byte(`"profile_id":8`), 1)
	if bytes.Equal(wire, corrupt) {
		t.Fatal("test did not mutate wire")
	}
	if Verify(CommandContext, "win-pilot-key", key, corrupt, &decoded) == nil {
		t.Fatal("payload forgery")
	}
	if _, err := Sign(CommandContext, "key", []byte("short"), c); err == nil {
		t.Fatal("weak key")
	}
}

func TestStrictDecodeAndSubjects(t *testing.T) {
	for _, wire := range []string{`{"unknown":1}`, `{} {}`, `null garbage`, strings.Repeat("x", MaxWireBytes+1)} {
		var c Command
		if Decode([]byte(wire), &c) == nil {
			t.Fatalf("accepted malformed payload %.30s", wire)
		}
	}
	for _, id := range []string{"*", ">", "win.1", "win\n1", "", "space here"} {
		if _, err := CommandSubject(id); err == nil {
			t.Fatal("subject wildcard/injection")
		}
		if _, err := ResultSubject(id); err == nil {
			t.Fatal("subject wildcard/injection")
		}
	}
	cmd, _ := CommandSubject("win-pilot")
	res, _ := ResultSubject("win-pilot")
	if cmd == res || !strings.HasPrefix(cmd, "railtime.execution.v1.") {
		t.Fatal("legacy/ambiguous subject")
	}
}

func TestBoundsAndExpiredReplay(t *testing.T) {
	c := sampleCommand() // Already elapsed: valid for result dedup, not authorization to launch.
	if err := c.Validate(); err != nil {
		t.Fatal(err)
	}
	for name, change := range map[string]func(*Command){"too-long": func(c *Command) { c.ExpiresAt = c.IssuedAt.Add(25 * time.Hour) }, "empty-snapshot": func(c *Command) { c.Snapshot = nil }, "empty-tasks": func(c *Command) { c.ExpectedTasks = nil }, "duplicate-task": func(c *Command) { c.ExpectedTasks = []string{"x", "x"} }, "no-tenant": func(c *Command) { c.TenantID = 0 }} {
		t.Run(name, func(t *testing.T) {
			bad := c
			change(&bad)
			bad.PayloadSHA256 = bad.Fingerprint()
			if bad.Validate() == nil {
				t.Fatal("unsafe command bounds")
			}
		})
	}
}

func TestNewID(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 100; i++ {
		id, err := NewID()
		if err != nil || !uuid.MatchString(id) || seen[id] {
			t.Fatalf("invalid unique ID %q %v", id, err)
		}
		seen[id] = true
	}
}

func FuzzEnvelopeDoesNotPanic(f *testing.F) {
	b, _ := json.Marshal(sampleCommand())
	f.Add(b)
	f.Add([]byte(`null`))
	f.Fuzz(func(t *testing.T, wire []byte) {
		var c Command
		_ = Verify(CommandContext, "test", bytes.Repeat([]byte{1}, 32), wire, &c)
	})
}

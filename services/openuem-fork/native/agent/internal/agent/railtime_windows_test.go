//go:build windows

package agent

import (
	"strings"
	"sync"
	"testing"

	openuem_nats "github.com/open-uem/nats"
	"github.com/open-uem/wingetcfg/wingetcfg"
	"railtime.local/openuem-extension/protocol"
)

func snapshotCommand() protocol.Command {
	return protocol.Command{ExpectedTasks: []string{"task_1_1"}, Snapshot: []byte("properties:\n  resources:\n    - resource: " + wingetcfg.OpenUEMPowershell + "\n      id: task_1_1\n      settings:\n        ID: task_1_1\n        Name: Synthetic test\n        Script: Write-Output 'not executed by this test'\n        ScriptRun: once\n")}
}

func TestRailTimeSnapshotRequiresExactNativeManifest(t *testing.T) {
	c := snapshotCommand()
	if err := validateRailTimeSnapshot(c); err != nil {
		t.Fatal(err)
	}
	for name, change := range map[string]func(*protocol.Command){
		"missing": func(c *protocol.Command) { c.ExpectedTasks = []string{"missing"} },
		"extra":   func(c *protocol.Command) { c.ExpectedTasks = append(c.ExpectedTasks, "extra") },
		"different-report-name": func(c *protocol.Command) {
			c.Snapshot = []byte(strings.Replace(string(c.Snapshot), "ID: task_1_1", "ID: spoofed", 1))
		},
		"unsupported": func(c *protocol.Command) {
			c.Snapshot = []byte(strings.Replace(string(c.Snapshot), wingetcfg.OpenUEMPowershell, "Unsupported/Resource", 1))
		},
		"malformed-type": func(c *protocol.Command) {
			c.Snapshot = []byte(strings.Replace(string(c.Snapshot), "Name: Synthetic test", "Name: 123", 1))
		},
	} {
		t.Run(name, func(t *testing.T) {
			v := snapshotCommand()
			change(&v)
			if validateRailTimeSnapshot(v) == nil {
				t.Fatal("unsafe snapshot accepted")
			}
		})
	}
}

func TestRailTimeNativeBusyNeverBegins(t *testing.T) {
	a := Agent{executionMu: &sync.Mutex{}}
	a.executionMu.Lock()
	defer a.executionMu.Unlock()
	out := a.executeRailTime(snapshotCommand(), func() error { t.Fatal("busy execution started"); return nil })
	if out.Error != "native_busy" {
		t.Fatal(out)
	}
}

func TestRailTimeNativeDisabledNeverBegins(t *testing.T) {
	a := Agent{executionMu: &sync.Mutex{}}
	out := a.executeRailTime(snapshotCommand(), func() error { t.Fatal("disabled execution started"); return nil })
	if out.Status != "failed" {
		t.Fatal(out)
	}
}

func TestRailTimeReportsDoNotExposeRawOutputAndNeedCompletion(t *testing.T) {
	out := railTimeTaskOutcome([]openuem_nats.TaskReport{{Name: "task_1_1", StdOut: "synthetic-secret", EndTime: "2026-09-06T12:00:00Z"}})
	if out.Status != "succeeded" || len(out.Tasks) != 1 || out.Tasks[0].Output != "" {
		t.Fatal(out)
	}
	for _, r := range []openuem_nats.TaskReport{{Name: "task_1_1"}, {Name: "task_1_1", EndTime: "2026-09-06T12:00:00Z", StdErr: "synthetic-secret"}, {Name: "task_1_1", EndTime: "2026-09-06T12:00:00Z", Failed: true}} {
		out = railTimeTaskOutcome([]openuem_nats.TaskReport{r})
		if out.Status != "failed" || out.Tasks[0].Succeeded || strings.Contains(out.Tasks[0].Error, "synthetic-secret") {
			t.Fatal(out)
		}
	}
}

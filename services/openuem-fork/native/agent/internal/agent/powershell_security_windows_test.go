//go:build windows

package agent

import (
	"reflect"
	"testing"
)

func TestPowerShellScriptCommandIsProcessScopedAndNonInteractive(t *testing.T) {
	// Build the command only. No PowerShell process or script is executed.
	for _, path := range []string{`C:\private\probe.ps1`, `C:\private dir\$(not-executed);'probe.ps1`} {
		cmd := powerShellScriptCommand(path)
		want := []string{"PowerShell", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "RemoteSigned", "-File", path}
		if !reflect.DeepEqual(cmd.Args, want) {
			t.Fatalf("unexpected process arguments: %#v", cmd.Args)
		}
		if cmd.Process != nil || cmd.ProcessState != nil {
			t.Fatal("constructing a command started a process")
		}
	}
}

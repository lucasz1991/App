//go:build windows

package agent

import (
	"context"
	"errors"
	"log"
	"path/filepath"
	"strconv"
	"time"

	"github.com/nats-io/nats.go"
	openuem_nats "github.com/open-uem/nats"
	"github.com/open-uem/openuem-agent/internal/agent/dsc"
	openuem_utils "github.com/open-uem/utils"
	"github.com/open-uem/wingetcfg/wingetcfg"
	"gopkg.in/yaml.v3"
	"railtime.local/openuem-extension/agentexec"
	"railtime.local/openuem-extension/protocol"
)

type railTimeRuntime struct {
	config       agentexec.Config
	journal      *agentexec.Journal
	engine       *agentexec.Engine
	cancel       context.CancelFunc
	done         chan struct{}
	subscription *nats.Subscription
}

func (a *Agent) railTimeConfig() (agentexec.Config, string, error) {
	root, err := agentexec.ConfigRoot()
	if err != nil {
		return agentexec.Config{}, "", err
	}
	cfg, err := agentexec.LoadConfig(root)
	if err != nil {
		return cfg, "", err
	}
	tenant, e1 := strconv.Atoi(a.Config.TenantID)
	site, e2 := strconv.Atoi(a.Config.SiteID)
	if e1 != nil || e2 != nil || cfg.AgentID != a.Config.UUID || cfg.TenantID != tenant || cfg.SiteID != site {
		return cfg, "", agentexec.ErrState
	}
	return cfg, filepath.Join(root, "journal"), nil
}

// InitializeRailTimeLedger is an explicit local enrollment action; it does not
// generate keys, enroll devices, start services or overwrite an existing ledger.
func InitializeRailTimeLedger() error {
	a := Agent{}
	if a.ReadConfig() != nil {
		return agentexec.ErrJournal
	}
	cfg, path, err := a.railTimeConfig()
	if err != nil {
		return err
	}
	return agentexec.Initialize(path, cfg.AgentID, cfg.LedgerID)
}

func (a *Agent) subscribeRailTime() {
	connection := a.railTimeConnection()
	if connection == nil || !connection.IsConnected() {
		return
	}
	a.railTimeMu.Lock()
	defer a.railTimeMu.Unlock()
	if a.railTime == nil {
		cfg, path, err := a.railTimeConfig()
		if errors.Is(err, agentexec.ErrDisabled) {
			return
		}
		if err != nil {
			log.Print("[ERROR]: RailTime execution enrollment rejected")
			return
		}
		j, err := agentexec.Open(path, cfg.AgentID, cfg.LedgerID)
		if err != nil {
			log.Print("[ERROR]: RailTime journal unavailable; execution disabled")
			return
		}
		e, err := agentexec.NewEngine(cfg, j, a.executeRailTime, func(subject string, wire []byte) ([]byte, error) {
			connection := a.railTimeConnection()
			if connection == nil {
				return nil, agentexec.ErrState
			}
			msg, err := connection.Request(subject, wire, 10*time.Second)
			if err != nil {
				return nil, agentexec.ErrState
			}
			return msg.Data, nil
		})
		if err != nil {
			_ = j.Close()
			return
		}
		ctx, cancel := context.WithCancel(context.Background())
		a.railTime = &railTimeRuntime{config: cfg, journal: j, engine: e, cancel: cancel, done: make(chan struct{})}
		runtime := a.railTime
		go func() { defer close(runtime.done); defer runtime.journal.Close(); runtime.engine.Run(ctx) }()
	}
	if a.railTime.subscription != nil && a.railTime.subscription.IsValid() {
		return
	}
	subject, err := protocol.CommandSubject(a.Config.UUID)
	if err != nil {
		return
	}
	sub, err := connection.Subscribe(subject, func(msg *nats.Msg) {
		cfg, _, err := a.railTimeConfig()
		if err != nil || !cfg.SameEnrollment(a.railTime.config) {
			return
		}
		reply, err := a.railTime.engine.Accept(msg.Data)
		if err == nil {
			_ = msg.Respond(reply)
		}
	})
	if err != nil {
		log.Print("[ERROR]: RailTime execution subscription unavailable")
		return
	}
	a.railTime.subscription = sub
}

func (a *Agent) stopRailTime() {
	if a.railTimeMu == nil {
		return
	}
	a.railTimeMu.Lock()
	defer a.railTimeMu.Unlock()
	if a.railTime == nil {
		return
	}
	if a.railTime.subscription != nil {
		_ = a.railTime.subscription.Unsubscribe()
	}
	a.railTime.cancel()
	select {
	case <-a.railTime.done:
	case <-time.After(5 * time.Second):
		// Do not close storage under an active native callback or claim it stopped.
		// Forced process exit leaves executing for conservative recovery.
		log.Print("[WARN]: RailTime execution still active; journal retained")
	}
}

func validateRailTimeSnapshot(c protocol.Command) error {
	var cfg wingetcfg.WinGetCfg
	if yaml.Unmarshal(c.Snapshot, &cfg) != nil || len(cfg.Properties.Resources) != len(c.ExpectedTasks) {
		return agentexec.ErrState
	}
	expected := map[string]bool{}
	for _, id := range c.ExpectedTasks {
		expected[id] = true
	}
	for _, r := range cfg.Properties.Resources {
		if r == nil || !expected[r.ID] {
			return agentexec.ErrState
		}
		delete(expected, r.ID)
		switch r.Resource {
		case wingetcfg.OpenUEMPowershell:
			for _, name := range []string{"ID", "Name", "Script"} {
				v, ok := r.Settings[name].(string)
				if !ok || v == "" {
					return agentexec.ErrState
				}
			}
			if r.Settings["ID"] != r.ID {
				return agentexec.ErrState
			}
			if v, ok := r.Settings["ScriptRun"]; ok {
				if _, ok := v.(string); !ok {
					return agentexec.ErrState
				}
			}
		case wingetcfg.WinGetLocalGroupResource, wingetcfg.WinGetLocalUserResource, wingetcfg.WinGetMSIPackageResource, wingetcfg.WinGetPackageResource, wingetcfg.WinGetRegistryResource:
		default:
			return agentexec.ErrState
		}
	}
	if len(expected) != 0 {
		return agentexec.ErrState
	}
	return nil
}

func (a *Agent) executeRailTime(c protocol.Command, begin func() error) agentexec.Outcome {
	if !a.executionMu.TryLock() {
		return agentexec.Outcome{Error: "native_busy"}
	}
	defer a.executionMu.Unlock()
	if !a.Config.Enabled || validateRailTimeSnapshot(c) != nil {
		return agentexec.Outcome{Status: "failed", Error: "native_preflight_rejected"}
	}
	cfg, _, err := a.railTimeConfig()
	if err != nil || a.railTime == nil || !cfg.SameEnrollment(a.railTime.config) || cfg.AgentID != c.AgentID || cfg.TenantID != c.TenantID || cfg.SiteID != c.SiteID {
		return agentexec.Outcome{Status: "failed", Error: "enrollment_changed"}
	}
	cwd, err := openuem_utils.GetWd()
	if err != nil {
		return agentexec.Outcome{Status: "failed", Error: "native_task_store_unavailable"}
	}
	path := filepath.Join(cwd, "powershell", "tasks.json")
	control, err := dsc.ReadTaskControlFile(path)
	if err != nil {
		return agentexec.Outcome{Status: "failed", Error: "native_task_store_unavailable"}
	}
	if _, running := control.ProfilesRunning[strconv.Itoa(c.ProfileID)]; running {
		return agentexec.Outcome{Error: "native_busy"}
	}
	if begin() != nil {
		return agentexec.Outcome{Status: "failed", Error: "journal_start_rejected"}
	}
	reports, err := a.ApplyConfiguration(c.ProfileID, c.Snapshot, c.Exclusions, c.Deployments, control, path, true)
	if err != nil {
		return agentexec.Outcome{Status: "failed", Error: "native_configuration_failed"}
	}
	return railTimeTaskOutcome(reports)
}

func railTimeTaskOutcome(reports []openuem_nats.TaskReport) agentexec.Outcome {
	out := agentexec.Outcome{Status: "succeeded"}
	for _, r := range reports {
		// Raw output can contain credentials; return bounded status evidence, not
		// stdout/stderr. Native logs retain their existing upstream behavior.
		t := protocol.TaskResult{Name: r.Name, Succeeded: !r.Failed && r.StdErr == "" && r.EndTime != ""}
		if !t.Succeeded {
			t.Error = "native_task_failed"
			out.Status = "failed"
			out.Error = "native_task_failed"
		}
		out.Tasks = append(out.Tasks, t)
	}
	return out
}

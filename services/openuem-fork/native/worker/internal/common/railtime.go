package common

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"log"
	"net/http"
	"sync"
	"time"

	"github.com/nats-io/nats.go"
	"github.com/open-uem/ent"
	"github.com/open-uem/ent/agent"
	"github.com/open-uem/ent/profile"
	"github.com/open-uem/ent/site"
	"github.com/open-uem/ent/task"
	"github.com/open-uem/ent/tenant"
	"github.com/open-uem/openuem-worker/internal/models"
	"gopkg.in/yaml.v3"
	"railtime.local/openuem-extension/inventory"
	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/server"
	"railtime.local/openuem-extension/store"
)

type railTimeRuntime struct {
	api           *server.Runtime
	cancel        context.CancelFunc
	done          sync.WaitGroup
	subscriptions []*nats.Subscription
}
type nativeTransport struct{ connection *nats.Conn }

func (n nativeTransport) Connected() bool { return n.connection != nil && n.connection.IsConnected() }
func (n nativeTransport) Request(ctx context.Context, subject string, wire []byte) ([]byte, error) {
	m, err := n.connection.RequestWithContext(ctx, subject, wire)
	if err != nil {
		return nil, err
	}
	return m.Data, nil
}

func (w *Worker) startRailTimeExtension() error {
	if w.railTimeRuntime != nil {
		return nil
	}
	config := w.railTimeConfig
	var err error
	if config == nil {
		config, err = server.LoadConfig(w.RailTimeConfigPath)
		if err != nil {
			return err
		}
	}
	if config == nil {
		return nil
	}
	if w.Model == nil || w.Model.DB == nil || w.NATSConnection == nil {
		return errors.New("RailTime native prerequisites unavailable")
	}
	ctx, cancel := context.WithCancel(context.Background())
	ledger := store.New(w.Model.DB)
	setupCtx, setupCancel := context.WithTimeout(ctx, 30*time.Second)
	err = ledger.Migrate(setupCtx)
	inventoryStore := models.InventoryStore{DB: w.Model.DB}
	if err == nil {
		err = inventoryStore.Migrate(setupCtx)
	}
	if err == nil {
		err = ledger.Health(setupCtx)
	}
	setupCancel()
	if err != nil {
		cancel()
		return errors.New("RailTime run ledger unavailable")
	}
	service := &server.Service{Config: *config, Ledger: ledger, Profiles: nativeProfiles{worker: w}, Transport: nativeTransport{w.NATSConnection}}
	api, err := service.Start()
	if err != nil {
		cancel()
		return err
	}
	runtime := &railTimeRuntime{api: api, cancel: cancel}
	cleanup := func() {
		cancel()
		for _, sub := range runtime.subscriptions {
			_ = sub.Unsubscribe()
		}
		_ = api.Listener.Close()
	}
	for _, enrollment := range config.InventoryEnrollments {
		e := enrollment
		subject, subjectErr := inventory.Subject(e.AgentID)
		if subjectErr != nil {
			cleanup()
			return errors.New("RailTime inventory subject invalid")
		}
		sub, subscribeErr := w.NATSConnection.QueueSubscribe(subject, "railtime-inventory-v1", func(msg *nats.Msg) {
			if len(msg.Data) > inventory.MaxWireBytes || msg.Reply == "" {
				return
			}
			reportCtx, reportCancel := context.WithTimeout(ctx, 30*time.Second)
			defer reportCancel()
			ack, receiveErr := receiveInventory(reportCtx, inventoryStore, e, msg.Subject, msg.Data, time.Now().UTC())
			if receiveErr != nil {
				log.Println("[WARN]: RailTime inventory not acknowledged; validation or persistence failed")
				return
			}
			if msg.Respond(ack) != nil {
				log.Println("[WARN]: RailTime committed inventory receipt not delivered; retry remains safe")
			}
		})
		if subscribeErr != nil {
			cleanup()
			return errors.New("RailTime inventory subscription failed")
		}
		runtime.subscriptions = append(runtime.subscriptions, sub)
	}
	for _, device := range config.DeviceKeys {
		agentID := device.AgentID
		subject, err := protocol.ResultSubject(agentID)
		if err != nil {
			cleanup()
			return err
		}
		sub, err := w.NATSConnection.QueueSubscribe(subject, "railtime-execution-v1-results", func(msg *nats.Msg) {
			if len(msg.Data) > protocol.MaxWireBytes {
				return
			}
			resultCtx, resultCancel := context.WithTimeout(ctx, 15*time.Second)
			defer resultCancel()
			ack, err := service.ReceiveResult(resultCtx, agentID, msg.Data)
			if err != nil {
				log.Println("[WARN]: RailTime result not acknowledged; validation or persistence failed")
				return
			}
			if err = msg.Respond(ack); err != nil {
				log.Println("[WARN]: RailTime durable result receipt not delivered; agent retry remains safe")
			}
		})
		if err != nil {
			cleanup()
			return errors.New("RailTime result subscription failed")
		}
		runtime.subscriptions = append(runtime.subscriptions, sub)
	}
	if err = w.NATSConnection.FlushTimeout(5 * time.Second); err != nil {
		cleanup()
		return errors.New("RailTime result subscriptions not confirmed")
	}
	w.railTimeRuntime = runtime
	runtime.done.Add(1)
	go func() {
		defer runtime.done.Done()
		if err := api.Serve(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Println("[ERROR]: RailTime machine API stopped")
			cancel()
		}
	}()
	if !config.ProvisioningOnly {
		runtime.done.Add(1)
		go func() {
			defer runtime.done.Done()
			ticker := time.NewTicker(time.Second)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-ticker.C:
					dispatchCtx, dispatchCancel := context.WithTimeout(ctx, 20*time.Second)
					err := service.DispatchOne(dispatchCtx)
					dispatchCancel()
					if err != nil && !errors.Is(err, store.ErrNotFound) {
						log.Println("[WARN]: RailTime dispatch pending; no execution outcome inferred")
					}
				}
			}
		}()
	}
	log.Println("[INFO]: RailTime run API enabled on configured loopback listener; public TLS and device execution readiness are separate gates")
	return nil
}

func (w *Worker) stopRailTimeExtension() {
	r := w.railTimeRuntime
	if r == nil {
		return
	}
	r.cancel()
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	_ = r.api.Close(ctx)
	for _, sub := range r.subscriptions {
		_ = sub.Unsubscribe()
	}
	r.done.Wait()
	w.railTimeRuntime = nil
}

type nativeProfiles struct{ worker *Worker }

// ScopedProfile is a native-owner query. No RailTime process can use this
// database path, and legacy automatic/tag profile application is excluded.
func scopedProfile(ctx context.Context, client *ent.Client, p server.Principal, r protocol.RunRequest) (*ent.Profile, error) {
	if !p.Allows(r.AgentID, r.ProfileID) {
		return nil, server.ErrForbidden
	}
	a, err := client.Agent.Query().WithSite(func(q *ent.SiteQuery) { q.WithTenant() }).Where(agent.ID(r.AgentID), agent.AgentStatusEQ(agent.AgentStatusEnabled)).Only(ctx)
	if err != nil || len(a.Edges.Site) != 1 {
		return nil, server.ErrForbidden
	}
	s := a.Edges.Site[0]
	if s.ID != p.SiteID || s.Edges.Tenant == nil || s.Edges.Tenant.ID != p.TenantID || (a.Os != "windows" && a.Os != "Windows") {
		return nil, server.ErrForbidden
	}
	pf, err := client.Profile.Query().WithTasks().WithTags().Where(profile.ID(r.ProfileID), profile.DisabledEQ(false), profile.HasSiteWith(site.ID(p.SiteID), site.HasTenantWith(tenant.ID(p.TenantID)))).Only(ctx)
	if err != nil {
		return nil, server.ErrForbidden
	}
	if pf.ApplyToAll || len(pf.Edges.Tags) != 0 {
		return nil, server.ErrProfile
	}
	return pf, nil
}

func (n nativeProfiles) Authorize(ctx context.Context, p server.Principal, r protocol.RunRequest) error {
	_, err := scopedProfile(ctx, n.worker.Model.Client, p, r)
	return err
}

func (n nativeProfiles) Snapshot(ctx context.Context, p server.Principal, r protocol.RunRequest) (server.Snapshot, error) {
	var result server.Snapshot
	tx, err := n.worker.Model.Client.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelRepeatableRead, ReadOnly: true})
	if err != nil {
		return result, err
	}
	defer tx.Rollback()
	pf, err := scopedProfile(ctx, tx.Client(), p, r)
	if err != nil {
		return result, err
	}
	if len(pf.Edges.Tasks) == 0 || len(pf.Edges.Tasks) > protocol.MaxTasks {
		return result, server.ErrProfile
	}
	for _, t := range pf.Edges.Tasks {
		if t.Disabled {
			continue
		}
		// Mixed, NetBird and non-Windows tasks are not silently omitted. Every
		// accepted v1 task must produce a named native WinGet resource/result.
		if t.AgentType != task.AgentTypeWindows || !supportedWindowsTask(t.Type) {
			return result, server.ErrProfile
		}
		result.ExpectedTasks = append(result.ExpectedTasks, fmt.Sprintf("task_%d_%d", t.ID, t.Version))
	}
	if len(result.ExpectedTasks) == 0 {
		return result, server.ErrProfile
	}
	config, err := n.worker.GenerateWinGetConfig(pf)
	if err != nil || config == nil {
		return result, server.ErrProfile
	}
	result.YAML, err = yaml.Marshal(config)
	if err != nil {
		return result, err
	}
	if len(result.YAML) > protocol.MaxSnapshotBytes {
		return result, server.ErrProfile
	}
	// Dedicated manual runs do not inherit periodic deployment/exclusion state;
	// every immutable expected resource must run under the agent's v1 journal.
	result.Exclusions = []string{}
	result.Deployments = []string{}
	if err = tx.Commit(); err != nil {
		return result, err
	}
	return result, nil
}

func supportedWindowsTask(t task.Type) bool {
	switch t {
	case task.TypeWingetInstall, task.TypeWingetDelete, task.TypeAddRegistryKey, task.TypeRemoveRegistryKey, task.TypeUpdateRegistryKeyDefaultValue, task.TypeAddRegistryKeyValue, task.TypeRemoveRegistryKeyValue, task.TypeAddLocalUser, task.TypeRemoveLocalUser, task.TypeAddLocalGroup, task.TypeRemoveLocalGroup, task.TypeAddUsersToLocalGroup, task.TypeRemoveUsersFromLocalGroup, task.TypeMsiInstall, task.TypeMsiUninstall, task.TypePowershellScript:
		return true
	default:
		return false
	}
}

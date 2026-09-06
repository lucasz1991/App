package models

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"sync"
	"testing"
	"time"

	"entgo.io/ent/dialect"
	entsql "entgo.io/ent/dialect/sql"
	"github.com/open-uem/ent"
	"github.com/open-uem/ent/agent"
	native "github.com/open-uem/nats"
	"railtime.local/openuem-extension/inventory"
	"railtime.local/openuem-extension/protocol"
)

func inventoryTestID() string { id, _ := protocol.NewID(); return id }

func newInventoryPG(t *testing.T) (InventoryStore, *ent.Client, inventory.Enrollment) {
	t.Helper()
	db := newBootstrapPG(t)
	b, err := BootstrapEmptyDatabase(context.Background(), db, bootstrapTestMetadata())
	if err != nil {
		t.Fatal(err)
	}
	s := InventoryStore{DB: db}
	if err = s.Migrate(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err = s.Migrate(context.Background()); err != nil {
		t.Fatal("migration repeat", err)
	}
	c := ent.NewClient(ent.Driver(entsql.OpenDB(dialect.Postgres, db)))
	return s, c, inventory.Enrollment{AgentID: inventoryTestID(), TenantID: b.TenantID, SiteID: b.SiteID}
}

func inventoryTestReport(e inventory.Enrollment, at time.Time) (inventory.Report, *native.AgentReport, string) {
	d := &native.AgentReport{AgentID: e.AgentID, OS: "windows", Hostname: "SYNTHETIC-PC", Tenant: strconv.Itoa(e.TenantID), Site: strconv.Itoa(e.SiteID), Enabled: true, CertificateReady: true, IP: "192.0.2.10", WAN: "198.51.100.10", MACAddress: "02:00:00:00:00:01",
		Computer:        native.Computer{Manufacturer: "Synthetic", Model: "Fixture", Serial: "TEST-NOT-A-REAL-DEVICE", Memory: 1024, Processor: "SyntheticCPU", ProcessorArch: "amd64", ProcessorCores: 2},
		Antivirus:       native.Antivirus{Name: "SyntheticAV", IsActive: true, IsUpdated: true},
		OperatingSystem: native.OperatingSystem{Version: "test", Description: "SyntheticOS", Arch: "amd64", Username: "synthetic", Domain: "example.invalid", InstallDate: at.Add(-time.Hour), LastBootUpTime: at.Add(-time.Minute)},
		SystemUpdate:    native.SystemUpdate{Status: "test", LastInstall: at, LastSearch: at},
		Applications:    []native.Application{{Name: "SyntheticApp", Version: "1", Publisher: "Test", InstallDate: "test"}},
		Monitors:        []native.Monitor{{Model: "SyntheticMonitor"}}, MemorySlots: []native.MemorySlot{{Slot: "SyntheticSlot"}},
		LogicalDisks: []native.LogicalDisk{{Label: "C:", Filesystem: "NTFS"}}, PhysicalDisks: []native.PhysicalDisk{{DeviceID: "SyntheticDisk"}},
		Printers: []native.Printer{{Name: "SyntheticPrinter"}}, NetworkAdapters: []native.NetworkAdapter{{Name: "SyntheticNIC", DHCPLeaseExpired: at, DHCPLeaseObtained: at}},
		Shares: []native.Share{{Name: "SyntheticShare", Path: "C:\\Synthetic"}}, Updates: []native.Update{{Title: "SyntheticUpdate", Date: at}},
		LoggedOnUsers: []native.LoggedOnUser{{Name: "synthetic", LastLogon: at}}, Netbird: native.Netbird{Version: "synthetic", Profiles: []string{"test"}},
		Release: native.Release{Version: "synthetic-untrusted", FileURL: "https://example.invalid/must-not-download"},
	}
	raw, _ := json.Marshal(d)
	r := inventory.Report{Protocol: inventory.Version, ReportID: inventoryTestID(), AgentID: e.AgentID, TenantID: e.TenantID, SiteID: e.SiteID, CollectedAt: at, Report: raw}
	subject, _ := inventory.Subject(e.AgentID)
	return r, d, subject
}

func TestInventoryRejectsBindingBeforeDatabase(t *testing.T) {
	now := time.Now().UTC()
	e := inventory.Enrollment{AgentID: inventoryTestID(), TenantID: 1, SiteID: 1}
	for _, scenario := range []string{"subject", "envelope-agent", "envelope-tenant", "envelope-site", "native-agent", "native-tenant", "native-site", "native-os", "native-host", "nil", "old", "future"} {
		t.Run(scenario, func(t *testing.T) {
			r, d, subject := inventoryTestReport(e, now)
			switch scenario {
			case "subject":
				subject = "report"
			case "envelope-agent":
				r.AgentID = inventoryTestID()
			case "envelope-tenant":
				r.TenantID++
			case "envelope-site":
				r.SiteID++
			case "native-agent":
				d.AgentID = inventoryTestID()
			case "native-tenant":
				d.Tenant = "2"
			case "native-site":
				d.Site = "2"
			case "native-os":
				d.OS = "linux"
			case "native-host":
				d.Hostname = ""
			case "nil":
				d = nil
			case "old":
				r.CollectedAt = now.Add(-time.Hour)
			case "future":
				r.CollectedAt = now.Add(time.Hour)
			}
			// Nil DB panics if any invalid input crosses the validation boundary.
			if _, err := (InventoryStore{}).Save(context.Background(), e, subject, r, d, now); !errors.Is(err, ErrInventoryRejected) {
				t.Fatal("not rejected", err)
			}
		})
	}
}

func TestInventoryPostgresCreatesWaitingAtomicNativeInventory(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC()
	r, d, subject := inventoryTestReport(e, now)
	receipt, err := s.Save(ctx, e, subject, r, d, now)
	if err != nil || receipt.Status != inventory.StatusWaiting || receipt.Validate(r) != nil {
		t.Fatalf("first report: %#v %v", receipt, err)
	}
	a, err := c.Agent.Get(ctx, e.AgentID)
	if err != nil || a.AgentStatus != agent.AgentStatusWaitingForAdmission || a.SftpService || a.RemoteAssistance || a.CertificateReady {
		t.Fatal("device self-admitted/enabled remote services", a, err)
	}
	if a.Hostname != d.Hostname || a.IP != d.IP || a.Nickname != d.Hostname {
		t.Fatal("native observations missing")
	}
	for name, count := range map[string]func(context.Context) (int, error){"computer": c.Computer.Query().Count, "os": c.OperatingSystem.Query().Count, "antivirus": c.Antivirus.Query().Count, "systemupdate": c.SystemUpdate.Query().Count, "netbird": c.Netbird.Query().Count, "apps": c.App.Query().Count, "monitors": c.Monitor.Query().Count, "memoryslots": c.MemorySlot.Query().Count, "logicaldisks": c.LogicalDisk.Query().Count, "physicaldisks": c.PhysicalDisk.Query().Count, "printers": c.Printer.Query().Count, "networkadapters": c.NetworkAdapter.Query().Count, "shares": c.Share.Query().Count, "updates": c.Update.Query().Count} {
		if n, err := count(ctx); err != nil || n != 1 {
			t.Fatalf("%s = %d %v", name, n, err)
		}
	}
	if n, err := c.Release.Query().Count(ctx); err != nil || n != 0 {
		t.Fatal("client release claims mutated trusted release records", n, err)
	}
	var snapshot []byte
	if err = s.DB.QueryRowContext(ctx, `SELECT native_report FROM railtime_inventory_current WHERE agent_id=$1`, e.AgentID).Scan(&snapshot); err != nil {
		t.Fatal(err)
	}
	var saved native.AgentReport
	if json.Unmarshal(snapshot, &saved) != nil || len(saved.LoggedOnUsers) != 1 || saved.Release.FileURL != d.Release.FileURL {
		t.Fatal("full native snapshot not preserved")
	}
}

func TestInventoryPostgresDedupAndNanosecondStaleness(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Microsecond)
	r, d, subject := inventoryTestReport(e, now)
	first, err := s.Save(ctx, e, subject, r, d, now)
	if err != nil {
		t.Fatal(err)
	}
	again, err := s.Save(ctx, e, subject, r, d, now)
	if err != nil || first != again {
		t.Fatal("identical retry", again, err)
	}
	d.Hostname = "Conflict"
	if _, err = s.Save(ctx, e, subject, r, d, now); !errors.Is(err, ErrInventoryRejected) {
		t.Fatal("report ID reused for different contents", err)
	}
	d.Hostname = "SYNTHETIC-PC"
	r.ReportID = inventoryTestID()
	if _, err = s.Save(ctx, e, subject, r, d, now); !errors.Is(err, ErrInventoryRejected) {
		t.Fatal("equal timestamp accepted", err)
	}
	r.CollectedAt = now.Add(time.Nanosecond)
	if _, err = s.Save(ctx, e, subject, r, d, now); err != nil {
		t.Fatal("newer nanos rejected", err)
	}
	r.ReportID = inventoryTestID()
	r.CollectedAt = now
	if _, err = s.Save(ctx, e, subject, r, d, now); !errors.Is(err, ErrInventoryRejected) {
		t.Fatal("microsecond truncation allowed stale report", err)
	}
	if n, err := c.App.Query().Count(ctx); err != nil || n != 1 {
		t.Fatal("retry duplicated observations", n, err)
	}
}

func TestInventoryPostgresPreservesStateManualFieldsAndScope(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC()
	r, d, subject := inventoryTestReport(e, now)
	if _, err := s.Save(ctx, e, subject, r, d, now); err != nil {
		t.Fatal(err)
	}
	if err := c.Agent.UpdateOneID(e.AgentID).SetAgentStatus(agent.AgentStatusDisabled).SetNickname("Admin nickname").SetDescription("Admin description").SetNotes("Admin notes").SetEndpointType(agent.EndpointTypeLaptop).SetSftpService(true).SetRemoteAssistance(false).SetCertificateReady(false).Exec(ctx); err != nil {
		t.Fatal(err)
	}
	r.ReportID = inventoryTestID()
	r.CollectedAt = now.Add(time.Second)
	d.Hostname = "Changed observation"
	receipt, err := s.Save(ctx, e, subject, r, d, now)
	if err != nil || receipt.Status != inventory.StatusDisabled {
		t.Fatal(receipt, err)
	}
	a, _ := c.Agent.Get(ctx, e.AgentID)
	if a.AgentStatus != agent.AgentStatusDisabled || a.Nickname != "Admin nickname" || a.Notes != "Admin notes" || a.Description != "Admin description" || a.EndpointType != agent.EndpointTypeLaptop || !a.SftpService || a.RemoteAssistance || a.CertificateReady || a.Hostname != d.Hostname {
		t.Fatal("server-owned state overwritten", a)
	}
	other, err := c.Site.Create().SetDescription("Other synthetic site").SetTenantID(e.TenantID).Save(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if err = c.Agent.UpdateOneID(e.AgentID).ClearSite().AddSiteIDs(other.ID).Exec(ctx); err != nil {
		t.Fatal(err)
	}
	r.ReportID = inventoryTestID()
	r.CollectedAt = now.Add(2 * time.Second)
	if _, err = s.Save(ctx, e, subject, r, d, now); !errors.Is(err, ErrInventoryRejected) {
		t.Fatal("changed site was repaired", err)
	}
}

func TestInventoryPostgresObservationFailureRollsBackEverything(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC()
	r, d, subject := inventoryTestReport(e, now)
	// A deliberate fixture-only DB constraint makes a mid-report child insert
	// fail after the agent and singleton observations have already been written.
	if _, err := s.DB.ExecContext(ctx, `ALTER TABLE apps ADD CONSTRAINT synthetic_inventory_failure CHECK(name <> 'SyntheticApp')`); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Save(ctx, e, subject, r, d, now); err == nil {
		t.Fatal("failed write acknowledged")
	}
	for name, count := range map[string]func(context.Context) (int, error){"agents": c.Agent.Query().Count, "computer": c.Computer.Query().Count, "apps": c.App.Query().Count} {
		if n, err := count(ctx); err != nil || n != 0 {
			t.Fatalf("partial %s remained %d %v", name, n, err)
		}
	}
	var n int
	if err := s.DB.QueryRowContext(ctx, `SELECT (SELECT count(*) FROM railtime_inventory_reports)+(SELECT count(*) FROM railtime_inventory_current)`).Scan(&n); err != nil || n != 0 {
		t.Fatal("failed report persisted receipt", n, err)
	}
	if _, err := s.DB.ExecContext(ctx, `ALTER TABLE apps DROP CONSTRAINT synthetic_inventory_failure`); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Save(ctx, e, subject, r, d, now); err != nil {
		t.Fatal("safe retry failed", err)
	}
}

func TestInventoryPostgresConcurrentIdenticalReports(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC()
	r, d, subject := inventoryTestReport(e, now)
	var wg sync.WaitGroup
	errs := make(chan error, 6)
	for range 6 {
		wg.Add(1)
		go func() { defer wg.Done(); _, err := s.Save(ctx, e, subject, r, d, now); errs <- err }()
	}
	wg.Wait()
	close(errs)
	for err := range errs {
		if err != nil {
			t.Fatal(err)
		}
	}
	if n, err := c.Agent.Query().Count(ctx); err != nil || n != 1 {
		t.Fatal("concurrent duplicate agents", n, err)
	}
	var n int
	if err := s.DB.QueryRowContext(ctx, `SELECT count(*) FROM railtime_inventory_reports`).Scan(&n); err != nil || n != 1 {
		t.Fatal("concurrent duplicate receipts", n, err)
	}
}

func TestInventoryPostgresAdmissionRequiresRealBoundWaitingReport(t *testing.T) {
	s, c, e := newInventoryPG(t)
	ctx := context.Background()
	now := time.Now().UTC()
	request := InventoryAdmission{RequestID: inventoryTestID(), AgentID: e.AgentID, TenantID: e.TenantID, SiteID: e.SiteID, Reason: "Synthetic isolated test admission"}
	if _, err := s.Admit(ctx, e, request, "synthetic-operator"); err == nil {
		t.Fatal("absent agent admitted")
	}
	// A preexisting manually created native row has no certificate-bound report.
	if _, err := c.Agent.Create().SetID(e.AgentID).SetOs("windows").SetHostname("Synthetic").AddSiteIDs(e.SiteID).Save(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Admit(ctx, e, request, "synthetic-operator"); err == nil {
		t.Fatal("reportless row admitted")
	}
	r, d, subject := inventoryTestReport(e, now)
	if _, err := s.Save(ctx, e, subject, r, d, now); err != nil {
		t.Fatal(err)
	}
	wrong := request
	wrong.SiteID++
	if _, err := s.Admit(ctx, e, wrong, "synthetic-operator"); err == nil {
		t.Fatal("wrong scope admitted")
	}
	result, err := s.Admit(ctx, e, request, "synthetic-operator")
	if err != nil || result.Status != "enabled" {
		t.Fatal(result, err)
	}
	if repeated, err := s.Admit(ctx, e, request, "synthetic-operator"); err != nil || result != repeated {
		t.Fatal("admission retry", repeated, err)
	}
	again, err := s.Save(ctx, e, subject, r, d, now)
	if err != nil || again.Status != inventory.StatusWaiting {
		t.Fatal("old receipt was rewritten after admission", again, err)
	}
	r.ReportID = inventoryTestID()
	r.CollectedAt = now.Add(time.Second)
	d.Enabled = false
	if receipt, err := s.Save(ctx, e, subject, r, d, now); err != nil || receipt.Status != inventory.StatusEnabled {
		t.Fatal("device self-disabled or new receipt wrong", receipt, err)
	}
	if err = c.Agent.UpdateOneID(e.AgentID).SetAgentStatus(agent.AgentStatusDisabled).Exec(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err = s.Admit(ctx, e, request, "synthetic-operator"); err == nil {
		t.Fatal("admission replay reenabled disabled device")
	}
	var n int
	if err = s.DB.QueryRowContext(ctx, `SELECT count(*) FROM railtime_inventory_admissions`).Scan(&n); err != nil || n != 1 {
		t.Fatal("admission audit mismatch", n, err)
	}
}

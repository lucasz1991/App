package agent

import (
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"testing"
	"time"

	openuem_nats "github.com/open-uem/nats"
	"github.com/open-uem/openuem-agent/internal/commands/report"
	"railtime.local/openuem-extension/inventory"
)

const testDedicatedUUID = "707c3761-9562-4ceb-aa2a-fa90c852ab7a"

func syntheticDedicatedReport() (Config, *report.Report) {
	c := Config{Dedicated: true, UUID: testDedicatedUUID, TenantID: "7", SiteID: "9", Enabled: true}
	r := &report.Report{AgentReport: openuem_nats.AgentReport{AgentID: c.UUID, Tenant: c.TenantID, Site: c.SiteID, OS: "windows", Hostname: "synthetic-test-device", Enabled: true}}
	return c, r
}

func TestDedicatedReportAcceptsOnlyExactDurableReceipt(t *testing.T) {
	now := time.Now().UTC()
	c, native := syntheticDedicatedReport()
	before := c
	err := sendDedicatedReport(c, native, now, func(subject string, wire []byte, timeout time.Duration) ([]byte, error) {
		wantSubject, _ := inventory.Subject(c.UUID)
		if subject != wantSubject || timeout != dedicatedReportTimeout || len(wire) > dedicatedMaxReportBytes {
			t.Fatal("unexpected report transport")
		}
		var r inventory.Report
		if json.Unmarshal(wire, &r) != nil || r.Validate(now) != nil || r.AgentID != c.UUID || r.TenantID != 7 || r.SiteID != 9 || r.CollectedAt != now {
			t.Fatal("invalid report envelope")
		}
		var got report.Report
		if json.Unmarshal(r.Report, &got) != nil || got.Hostname != native.Hostname || got.AgentID != native.AgentID {
			t.Fatal("native report was replaced")
		}
		return json.Marshal(inventory.Receipt{Protocol: r.Protocol, ReportID: r.ReportID, AgentID: r.AgentID, TenantID: r.TenantID, SiteID: r.SiteID, Status: inventory.StatusWaiting})
	})
	if err != nil || !reflect.DeepEqual(c, before) {
		t.Fatal("valid receipt failed or config changed", err)
	}
}

func TestDedicatedAdmissionReceiptsNeverChangeLocalAdministrativeState(t *testing.T) {
	for _, status := range []string{inventory.StatusWaiting, inventory.StatusEnabled, inventory.StatusDisabled} {
		t.Run(status, func(t *testing.T) {
			c, native := syntheticDedicatedReport()
			before := c
			err := sendDedicatedReport(c, native, time.Now(), func(_ string, wire []byte, _ time.Duration) ([]byte, error) {
				var r inventory.Report
				_ = json.Unmarshal(wire, &r)
				return json.Marshal(inventory.Receipt{Protocol: r.Protocol, ReportID: r.ReportID, AgentID: r.AgentID, TenantID: r.TenantID, SiteID: r.SiteID, Status: status})
			})
			if err != nil || c != before {
				t.Fatal("server receipt changed local configuration", err)
			}
		})
	}
}

func TestDedicatedReportRejectsEveryReceiptMismatchAndTimeout(t *testing.T) {
	changes := map[string]func(*inventory.Receipt){
		"protocol": func(r *inventory.Receipt) { r.Protocol = "legacy" },
		"report":   func(r *inventory.Receipt) { r.ReportID = "0bc86847-5331-41fa-a250-e7d4a053d0ae" },
		"agent":    func(r *inventory.Receipt) { r.AgentID = "0bc86847-5331-41fa-a250-e7d4a053d0ae" },
		"tenant":   func(r *inventory.Receipt) { r.TenantID++ },
		"site":     func(r *inventory.Receipt) { r.SiteID++ },
		"status":   func(r *inventory.Receipt) { r.Status = "received" },
	}
	for name, change := range changes {
		t.Run(name, func(t *testing.T) {
			c, native := syntheticDedicatedReport()
			err := sendDedicatedReport(c, native, time.Now(), func(_ string, wire []byte, _ time.Duration) ([]byte, error) {
				var r inventory.Report
				_ = json.Unmarshal(wire, &r)
				receipt := inventory.Receipt{Protocol: r.Protocol, ReportID: r.ReportID, AgentID: r.AgentID, TenantID: r.TenantID, SiteID: r.SiteID, Status: inventory.StatusWaiting}
				change(&receipt)
				return json.Marshal(receipt)
			})
			if !errors.Is(err, errDedicatedReport) {
				t.Fatal("mismatched receipt accepted")
			}
		})
	}
	for name, reply := range map[string][]byte{"plain legacy ACK": []byte("Report received!"), "empty": nil, "large": []byte(strings.Repeat("x", 4097)), "trailing JSON": []byte(`{} {}`)} {
		t.Run(name, func(t *testing.T) {
			c, native := syntheticDedicatedReport()
			if sendDedicatedReport(c, native, time.Now(), func(string, []byte, time.Duration) ([]byte, error) { return reply, nil }) == nil {
				t.Fatal("invalid acknowledgement accepted")
			}
		})
	}
	t.Run("timeout", func(t *testing.T) {
		c, native := syntheticDedicatedReport()
		if sendDedicatedReport(c, native, time.Now(), func(string, []byte, time.Duration) ([]byte, error) {
			return nil, errors.New("synthetic timeout; no network used")
		}) == nil {
			t.Fatal("transport failure accepted")
		}
	})
}

func TestDedicatedReportRejectsSpoofedNativeIdentityBeforeTransport(t *testing.T) {
	for _, field := range []string{"agent", "tenant", "site", "size", "mode"} {
		t.Run(field, func(t *testing.T) {
			c, native := syntheticDedicatedReport()
			switch field {
			case "agent":
				native.AgentID = "other"
			case "tenant":
				native.Tenant = "8"
			case "site":
				native.Site = "10"
			case "size":
				native.Hostname = strings.Repeat("x", dedicatedMaxReportBytes)
			case "mode":
				c.Dedicated = false
			}
			if sendDedicatedReport(c, native, time.Now(), func(string, []byte, time.Duration) ([]byte, error) {
				t.Fatal("rejected report reached transport")
				return nil, nil
			}) == nil {
				t.Fatal("invalid report accepted")
			}
		})
	}
}

func TestDedicatedConfigurationCannotBePersistedByLegacyHelpers(t *testing.T) {
	a := Agent{Config: Config{Dedicated: true, UUID: testDedicatedUUID, Enabled: false}}
	before := a.Config
	if a.Config.WriteConfig() == nil || a.Config.SetRestartRequiredFlag() == nil || a.Config.ResetRestartRequiredFlag() == nil {
		t.Fatal("legacy write path reachable")
	}
	a.SetInitialConfig()
	if a.Config != before {
		t.Fatal("legacy helper changed enrollment identity")
	}
	// Deliberately nil scheduler/connection prove these guards precede legacy IO.
	if a.startReportJob() == nil || a.startPendingACKJob() == nil || a.GetRemoteConfig() == nil {
		t.Fatal("legacy scheduling/configuration path reachable")
	}
	a.CreateAgentJetStreamConsumer()
	a.PendingACKTask()
	a.SubscribeToNATSSubjects()
	a.ReportTask()
}

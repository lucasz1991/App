package common

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	"github.com/open-uem/openuem-worker/internal/models"
	"railtime.local/openuem-extension/inventory"
	"railtime.local/openuem-extension/server"
)

const testInventoryAgent = "be9f1234-7901-4567-abcd-123456789abc"
const testInventoryReport = "be9f1234-7901-4567-abcd-123456789abd"

func TestInventoryReceiverRejectsStrictJSONBeforeDatabase(t *testing.T) {
	now := time.Now().UTC()
	e := inventory.Enrollment{AgentID: testInventoryAgent, TenantID: 1, SiteID: 1}
	r := inventory.Report{Protocol: inventory.Version, ReportID: testInventoryReport, AgentID: e.AgentID, TenantID: 1, SiteID: 1, CollectedAt: now, Report: json.RawMessage(`{"id":"` + testInventoryAgent + `","tenant":"1","site":"1","os":"windows","hostname":"synthetic"}`)}
	subject, _ := inventory.Subject(e.AgentID)
	valid, _ := json.Marshal(r)
	for _, scenario := range []string{"unknown-envelope", "duplicate-envelope", "unknown-native", "duplicate-native", "wrong-native-id", "wrong-native-tenant", "wrong-native-site", "wrong-native-type", "wrong-subject", "trailing", "null-report", "too-large", "too-many-records"} {
		t.Run(scenario, func(t *testing.T) {
			wire := append([]byte(nil), valid...)
			receivedSubject := subject
			switch scenario {
			case "unknown-envelope":
				wire = []byte(strings.Replace(string(wire), `"protocol":`, `"unexpected":`, 1))
			case "duplicate-envelope":
				wire = []byte(strings.Replace(string(wire), `"tenant_id":1`, `"tenant_id":1,"tenant_id":1`, 1))
			case "unknown-native":
				wire = []byte(strings.Replace(string(wire), `"hostname":"synthetic"`, `"hostname":"synthetic","unknown":true`, 1))
			case "duplicate-native":
				wire = []byte(strings.Replace(string(wire), `"site":"1"`, `"site":"1","site":"1"`, 1))
			case "wrong-native-id":
				wire = []byte(strings.Replace(string(wire), `"id":"`+testInventoryAgent+`"`, `"id":"`+testInventoryReport+`"`, 1))
			case "wrong-native-tenant":
				wire = []byte(strings.Replace(string(wire), `"tenant":"1"`, `"tenant":"2"`, 1))
			case "wrong-native-site":
				wire = []byte(strings.Replace(string(wire), `"site":"1"`, `"site":"2"`, 1))
			case "wrong-native-type":
				wire = []byte(strings.Replace(string(wire), `"hostname":"synthetic"`, `"hostname":12`, 1))
			case "wrong-subject":
				receivedSubject = "report"
			case "trailing":
				wire = append(wire, []byte(` {}`)...)
			case "null-report":
				copy := r
				copy.Report = json.RawMessage(`null`)
				wire, _ = json.Marshal(copy)
			case "too-large":
				wire = []byte(strings.Repeat(" ", inventory.MaxWireBytes+1))
			case "too-many-records":
				wire = []byte(strings.Replace(string(wire), `"hostname":"synthetic"`, `"hostname":"synthetic","apps":[`+strings.Repeat(`{},`, 10000)+`{}]`, 1))
			}
			ack, err := receiveInventory(context.Background(), models.InventoryStore{}, e, receivedSubject, wire, now)
			if err == nil || len(ack) != 0 {
				t.Fatal("invalid input reached nil database or acknowledged")
			}
		})
	}
}

func TestExplicitExecutionConfigurationAlsoSkipsLegacySubscriptions(t *testing.T) {
	for _, w := range []*Worker{
		{railTimeConfig: &server.Config{Enabled: true, ProvisioningOnly: false}},
		{RailTimeConfigPath: filepath.Join(t.TempDir(), "missing-private-config.json")},
	} {
		// A legacy subscription would dereference the nil NATS connection.
		if err := w.SubscribeToAgentWorkerQueues(); err == nil {
			t.Fatal("invalid explicit config entered legacy path")
		}
	}
}

func TestInventoryAdmissionConfigStrictProtectedAndNoImplicitExecution(t *testing.T) {
	c := InventoryAdmissionConfig{WorkerConfig: filepath.Join(t.TempDir(), "worker.json"), InventoryAdmission: models.InventoryAdmission{RequestID: testInventoryReport, AgentID: testInventoryAgent, TenantID: 1, SiteID: 1, Reason: "Synthetic explicit approval"}}
	good, _ := json.Marshal(c)
	if _, err := decodeInventoryAdmissionConfig(good); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		`null`, `[]`, `{}`, string(good) + `{}`,
		strings.Replace(string(good), `"site_id":1`, `"site_id":1,"site_id":1`, 1),
		strings.Replace(string(good), `"site_id":1`, `"site_id":null`, 1),
		strings.Replace(string(good), `"site_id":1`, `"site_id":0`, 1),
		strings.Replace(string(good), `"reason":"Synthetic explicit approval"`, `"reason":""`, 1),
		strings.Replace(string(good), `"reason":"Synthetic explicit approval"`, `"reason":"line\nbreak"`, 1),
		strings.Replace(string(good), `"worker_config":`, `"unknown":`, 1),
		strings.Replace(string(good), testInventoryAgent, "NOT-A-UUID", 1),
	} {
		if _, err := decodeInventoryAdmissionConfig([]byte(bad)); err == nil {
			t.Fatal("unsafe admission config accepted")
		}
	}
	path := filepath.Join(t.TempDir(), "admission.json")
	if err := os.WriteFile(path, good, 0600); err != nil {
		t.Fatal(err)
	}
	loaded, err := LoadInventoryAdmissionConfig(path)
	if runtime.GOOS != "linux" {
		if err == nil {
			t.Fatal("protected native worker file admitted without Linux permission verification")
		}
		return
	}
	if err != nil || loaded != c {
		t.Fatal("protected admission file", loaded, err)
	}
	if _, err = AdmitInventoryDevice(context.Background(), loaded); err == nil {
		t.Fatal("absent worker config accepted")
	}
	// No logger/PID file, worker bootstrap, agent or credential is created by
	// parsing a request or refusing an absent referenced configuration.
	entries, err := os.ReadDir(filepath.Dir(path))
	if err != nil || len(entries) != 1 {
		t.Fatal("unexpected admission side effect", entries, err)
	}
}

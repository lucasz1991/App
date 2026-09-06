package agent

import (
	"encoding/json"
	"errors"
	"strconv"
	"time"

	"github.com/google/uuid"
	"github.com/nats-io/nats.go"
	"github.com/open-uem/openuem-agent/internal/commands/report"
	"railtime.local/openuem-extension/inventory"
)

var errDedicatedConfig = errors.New("dedicated enrollment/configuration rejected")
var errDedicatedReport = errors.New("dedicated inventory not durably acknowledged")

const dedicatedMaxReportBytes = 8 << 20
const dedicatedReportTimeout = 45 * time.Second

func (a *Agent) railTimeConnection() *nats.Conn {
	if !a.Config.Dedicated {
		return a.NATSConnection
	}
	if a.dedicatedConnectionMu == nil {
		return nil
	}
	a.dedicatedConnectionMu.RLock()
	defer a.dedicatedConnectionMu.RUnlock()
	if a.dedicatedStopping {
		return nil
	}
	return a.NATSConnection
}

// sendDedicatedReport only wraps a report collected by the native reporter. It
// cannot create an agent identity, admit a device, or accept a legacy plain ACK.
func sendDedicatedReport(c Config, r *report.Report, now time.Time, request func(string, []byte, time.Duration) ([]byte, error)) error {
	if !c.Dedicated || r == nil || request == nil || r.AgentID != c.UUID || r.Tenant != c.TenantID || r.Site != c.SiteID {
		return errDedicatedReport
	}
	tenant, err := strconv.Atoi(c.TenantID)
	if err != nil || tenant <= 0 {
		return errDedicatedReport
	}
	site, err := strconv.Atoi(c.SiteID)
	if err != nil || site <= 0 {
		return errDedicatedReport
	}
	payload, err := json.Marshal(r)
	if err != nil || len(payload) > dedicatedMaxReportBytes {
		return errDedicatedReport
	}
	envelope := inventory.Report{Protocol: inventory.Version, ReportID: uuid.NewString(), AgentID: c.UUID, TenantID: tenant, SiteID: site, CollectedAt: now.UTC(), Report: payload}
	if envelope.Validate(now) != nil {
		return errDedicatedReport
	}
	wire, err := json.Marshal(envelope)
	if err != nil || len(wire) > dedicatedMaxReportBytes {
		return errDedicatedReport
	}
	subject, err := inventory.Subject(c.UUID)
	if err != nil {
		return errDedicatedReport
	}
	reply, err := request(subject, wire, dedicatedReportTimeout)
	if err != nil || len(reply) == 0 || len(reply) > 4096 {
		return errDedicatedReport
	}
	// Shared decoder rejects duplicate/unknown keys, trailing JSON and every
	// mismatched receipt field. Server admission status never edits local INI.
	if _, err := inventory.DecodeReceipt(reply, envelope); err != nil {
		return errDedicatedReport
	}
	return nil
}

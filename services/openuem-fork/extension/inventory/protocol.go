// Package inventory defines the certificate-bound, non-command report channel.
// The broker must bind a client certificate identity to the exact Subject:
// envelope validation alone does not authenticate a device.
package inventory

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"time"
)

const (
	Version         = "railtime.inventory.v1"
	MaxWireBytes    = 8 * 1024 * 1024
	MaxReceiptBytes = 4096
	StatusWaiting   = "waiting_for_admission"
	StatusEnabled   = "enabled"
	StatusDisabled  = "disabled"
	MaxReportAge    = 15 * time.Minute
	MaxClockSkew    = 5 * time.Minute
)

var canonicalUUID = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

type Enrollment struct {
	AgentID  string `json:"agent_id"`
	TenantID int    `json:"tenant_id"`
	SiteID   int    `json:"site_id"`
}

func validID(s string) bool {
	return canonicalUUID.MatchString(s) && s != "00000000-0000-0000-0000-000000000000"
}

func (e Enrollment) Validate() error {
	if !validID(e.AgentID) || e.TenantID <= 0 || e.SiteID <= 0 {
		return errors.New("invalid explicit inventory enrollment")
	}
	return nil
}

func Subject(agentID string) (string, error) {
	if !validID(agentID) {
		return "", errors.New("inventory requires a canonical device UUID")
	}
	return Version + ".report." + agentID, nil
}

func ClientIdentity(agentID string) (string, error) {
	if !validID(agentID) {
		return "", errors.New("inventory requires a canonical device UUID")
	}
	return "railtime-device-" + agentID, nil
}

type Report struct {
	Protocol    string          `json:"protocol"`
	ReportID    string          `json:"report_id"`
	AgentID     string          `json:"agent_id"`
	TenantID    int             `json:"tenant_id"`
	SiteID      int             `json:"site_id"`
	CollectedAt time.Time       `json:"collected_at"`
	Report      json.RawMessage `json:"report"`
}

func (r Report) Validate(now time.Time) error {
	if r.Protocol != Version || !validID(r.ReportID) {
		return errors.New("invalid inventory protocol or report ID")
	}
	if err := (Enrollment{r.AgentID, r.TenantID, r.SiteID}).Validate(); err != nil {
		return err
	}
	if now.IsZero() || r.CollectedAt.IsZero() || r.CollectedAt.Before(now.Add(-MaxReportAge)) || r.CollectedAt.After(now.Add(MaxClockSkew)) {
		return errors.New("inventory collection time outside acceptance window")
	}
	if err := strictJSON(r.Report, MaxWireBytes); err != nil {
		return err
	}
	b, err := json.Marshal(r)
	if err != nil || len(b) > MaxWireBytes {
		return errors.New("inventory envelope too large")
	}
	return nil
}

func DecodeReport(wire []byte, now time.Time) (Report, error) {
	var report Report
	if err := decode(wire, MaxWireBytes, &report); err != nil {
		return Report{}, err
	}
	if err := report.Validate(now); err != nil {
		return Report{}, err
	}
	return report, nil
}

type Receipt struct {
	Protocol string `json:"protocol"`
	ReportID string `json:"report_id"`
	AgentID  string `json:"agent_id"`
	TenantID int    `json:"tenant_id"`
	SiteID   int    `json:"site_id"`
	Status   string `json:"status"`
}

func (r Receipt) Validate(expected Report) error {
	if r.Protocol != Version || expected.Protocol != Version || !validID(r.ReportID) || r.ReportID != expected.ReportID ||
		r.AgentID != expected.AgentID || r.TenantID != expected.TenantID || r.SiteID != expected.SiteID {
		return errors.New("inventory receipt does not match report")
	}
	if err := (Enrollment{r.AgentID, r.TenantID, r.SiteID}).Validate(); err != nil {
		return err
	}
	switch r.Status {
	case StatusWaiting, StatusEnabled, StatusDisabled:
		return nil
	}
	return errors.New("invalid inventory admission status")
}

func DecodeReceipt(wire []byte, expected Report) (Receipt, error) {
	var receipt Receipt
	if err := decode(wire, MaxReceiptBytes, &receipt); err != nil {
		return Receipt{}, err
	}
	if err := receipt.Validate(expected); err != nil {
		return Receipt{}, err
	}
	return receipt, nil
}

func decode(wire []byte, limit int, out any) error {
	if err := strictJSON(wire, limit); err != nil {
		return err
	}
	d := json.NewDecoder(bytes.NewReader(wire))
	d.DisallowUnknownFields()
	return d.Decode(out)
}

// Go's JSON decoder accepts duplicate keys; reject them before typed decoding,
// including inside the opaque upstream payload, to prevent parser ambiguity.
func strictJSON(wire []byte, limit int) error {
	trimmed := bytes.TrimSpace(wire)
	if len(wire) == 0 || len(wire) > limit || len(trimmed) == 0 || trimmed[0] != '{' {
		return errors.New("invalid inventory JSON object or size")
	}
	d := json.NewDecoder(bytes.NewReader(wire))
	d.UseNumber()
	if err := scanValue(d, 0); err != nil {
		return err
	}
	if _, err := d.Token(); err != io.EOF {
		return errors.New("trailing inventory JSON")
	}
	return nil
}

func scanValue(d *json.Decoder, depth int) error {
	if depth > 64 {
		return errors.New("inventory JSON nesting exceeds limit")
	}
	t, err := d.Token()
	if err != nil {
		return errors.New("invalid inventory JSON")
	}
	delim, container := t.(json.Delim)
	if !container {
		return nil
	}
	switch delim {
	case '{':
		keys := make(map[string]bool)
		for d.More() {
			keyToken, err := d.Token()
			if err != nil {
				return errors.New("invalid inventory JSON key")
			}
			key, ok := keyToken.(string)
			if !ok || keys[key] {
				return errors.New("duplicate inventory JSON key")
			}
			keys[key] = true
			if err := scanValue(d, depth+1); err != nil {
				return err
			}
		}
		end, err := d.Token()
		if err != nil || end != json.Delim('}') {
			return errors.New("invalid inventory JSON object")
		}
	case '[':
		for d.More() {
			if err := scanValue(d, depth+1); err != nil {
				return err
			}
		}
		end, err := d.Token()
		if err != nil || end != json.Delim(']') {
			return errors.New("invalid inventory JSON array")
		}
	default:
		return errors.New("unexpected inventory JSON delimiter")
	}
	return nil
}

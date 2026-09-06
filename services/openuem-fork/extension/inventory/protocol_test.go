package inventory

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

const deviceID = "2867529f-0b4e-441c-83d7-707a0c745cbd"
const reportID = "dc72d7ae-637b-492c-8697-cc4242cbcb01"

func testReport() Report {
	return Report{Version, reportID, deviceID, 1, 2, time.Date(2026, 9, 6, 18, 0, 0, 123, time.UTC), json.RawMessage(`{"AgentID":"` + deviceID + `","TenantID":1,"SiteID":2,"Items":[]}`)}
}

func TestIdentityRejectsAlternateOrWildcardForms(t *testing.T) {
	subject, err := Subject(deviceID)
	if err != nil || subject != Version+".report."+deviceID {
		t.Fatal(subject, err)
	}
	identity, err := ClientIdentity(deviceID)
	if err != nil || identity != "railtime-device-"+deviceID {
		t.Fatal(identity, err)
	}
	for _, id := range []string{"", "*", ">", "legacy-host", strings.ToUpper(deviceID), " " + deviceID, deviceID + ".other", "00000000-0000-0000-0000-000000000000"} {
		if _, err := Subject(id); err == nil {
			t.Fatalf("accepted subject identity %q", id)
		}
		if _, err := ClientIdentity(id); err == nil {
			t.Fatalf("accepted cert identity %q", id)
		}
	}
}

func TestReportRoundTripAndFreshness(t *testing.T) {
	r := testReport()
	wire, err := json.Marshal(r)
	if err != nil {
		t.Fatal(err)
	}
	got, err := DecodeReport(wire, r.CollectedAt)
	if err != nil || got.CollectedAt != r.CollectedAt || got.AgentID != r.AgentID {
		t.Fatal(got, err)
	}
	for name, mutate := range map[string]func(*Report){
		"version":           func(r *Report) { r.Protocol = "other" },
		"report id":         func(r *Report) { r.ReportID = "test" },
		"agent id":          func(r *Report) { r.AgentID = "test" },
		"tenant":            func(r *Report) { r.TenantID = 0 },
		"site":              func(r *Report) { r.SiteID = -1 },
		"old":               func(r *Report) { r.CollectedAt = r.CollectedAt.Add(-MaxReportAge - time.Nanosecond) },
		"future":            func(r *Report) { r.CollectedAt = r.CollectedAt.Add(MaxClockSkew + time.Nanosecond) },
		"zero":              func(r *Report) { r.CollectedAt = time.Time{} },
		"null":              func(r *Report) { r.Report = json.RawMessage(`null`) },
		"array":             func(r *Report) { r.Report = json.RawMessage(`[]`) },
		"nested duplicate":  func(r *Report) { r.Report = json.RawMessage(`{"a":[{"id":1,"id":2}]}`) },
		"unicode duplicate": func(r *Report) { r.Report = json.RawMessage(`{"id":1,"\u0069d":2}`) },
		"oversize":          func(r *Report) { r.Report = json.RawMessage(`{"a":"` + strings.Repeat("x", MaxWireBytes) + `"}`) },
		"deep": func(r *Report) {
			r.Report = json.RawMessage(`{"a":` + strings.Repeat("[", 65) + `0` + strings.Repeat("]", 65) + `}`)
		},
	} {
		t.Run(name, func(t *testing.T) {
			bad := testReport()
			mutate(&bad)
			if bad.Validate(r.CollectedAt) == nil {
				t.Fatal("unsafe report accepted")
			}
		})
	}
	for _, wire := range []string{`{}`, `null`, string(wire) + `{}`, strings.Replace(string(wire), `"protocol":`, `"unknown":true,"protocol":`, 1), strings.Replace(string(wire), `"protocol":`, `"protocol":"other","protocol":`, 1)} {
		if _, err := DecodeReport([]byte(wire), r.CollectedAt); err == nil {
			t.Fatal("invalid envelope accepted")
		}
	}
}

func TestReceiptStrictlyCorrelatesIdentityAndReport(t *testing.T) {
	r := testReport()
	for _, status := range []string{StatusWaiting, StatusEnabled, StatusDisabled} {
		receipt := Receipt{Version, r.ReportID, r.AgentID, r.TenantID, r.SiteID, status}
		wire, _ := json.Marshal(receipt)
		if _, err := DecodeReceipt(wire, r); err != nil {
			t.Fatal(err)
		}
	}
	base := Receipt{Version, r.ReportID, r.AgentID, r.TenantID, r.SiteID, StatusWaiting}
	for name, mutate := range map[string]func(*Receipt){
		"agent": func(x *Receipt) { x.AgentID = reportID }, "report": func(x *Receipt) { x.ReportID = deviceID },
		"tenant": func(x *Receipt) { x.TenantID++ }, "site": func(x *Receipt) { x.SiteID++ },
		"protocol": func(x *Receipt) { x.Protocol = "other" }, "status": func(x *Receipt) { x.Status = "received" },
	} {
		t.Run(name, func(t *testing.T) {
			bad := base
			mutate(&bad)
			if bad.Validate(r) == nil {
				t.Fatal("mismatched receipt accepted")
			}
		})
	}
	for _, wire := range []string{"Report received!", `{}`, `null`, `{"status":"enabled","status":"disabled"}`, strings.Repeat(" ", MaxReceiptBytes+1)} {
		if _, err := DecodeReceipt([]byte(wire), r); err == nil {
			t.Fatal("legacy/malformed receipt accepted")
		}
	}
}

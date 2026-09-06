package server

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/store"
)

func provisioningConfig() Config {
	c := testConfig()
	c.ProvisioningOnly = true
	c.DeviceKeys = nil
	c.Principals[0].AgentIDs = nil
	c.Principals[0].ProfileIDs = nil
	return c
}

func TestProvisioningConfigRequiresAuthenticationAndNoExecutionScope(t *testing.T) {
	if err := provisioningConfig().Validate(); err != nil {
		t.Fatal(err)
	}
	for name, mutate := range map[string]func(*Config){
		"implicit empty production": func(c *Config) { c.ProvisioningOnly = false },
		"no principal":              func(c *Config) { c.Principals = nil },
		"missing tenant":            func(c *Config) { c.Principals[0].TenantID = 0 },
		"missing site":              func(c *Config) { c.Principals[0].SiteID = 0 },
		"invalid bearer":            func(c *Config) { c.Principals[0].BearerSHA256 = "" },
		"device key":                func(c *Config) { c.DeviceKeys = testConfig().DeviceKeys },
		"device scope":              func(c *Config) { c.Principals[0].AgentIDs = []string{"device-a"} },
		"profile scope":             func(c *Config) { c.Principals[0].ProfileIDs = []int{3} },
	} {
		t.Run(name, func(t *testing.T) {
			c := provisioningConfig()
			mutate(&c)
			if c.Validate() == nil {
				t.Fatal("unsafe provisioning config accepted")
			}
		})
	}
}

type healthOnlyLedger struct {
	store.Ledger
	err error
}

func (l healthOnlyLedger) Health(context.Context) error { return l.err }

type healthOnlyTransport struct{ connected bool }

func (t healthOnlyTransport) Connected() bool { return t.connected }
func (t healthOnlyTransport) Request(context.Context, string, []byte) ([]byte, error) {
	panic("infrastructure-only mode must never send a device command")
}

func TestProvisioningHealthCannotClaimExecutionReady(t *testing.T) {
	for _, failed := range []string{"", "database", "broker"} {
		t.Run("unavailable_"+failed, func(t *testing.T) {
			ledger := healthOnlyLedger{}
			if failed == "database" {
				ledger.err = errors.New("synthetic failure")
			}
			s := Service{Config: provisioningConfig(), Ledger: ledger, Transport: healthOnlyTransport{failed != "broker"}}
			r := httptest.NewRequest(http.MethodGet, "/railtime/v1/health", nil)
			r.Header.Set("Authorization", "Bearer a synthetic service credential for tests only")
			w := httptest.NewRecorder()
			s.Handler().ServeHTTP(w, r)
			var h Health
			if err := json.Unmarshal(w.Body.Bytes(), &h); err != nil {
				t.Fatal(err)
			}
			if w.Code != 503 || h.Ready || !h.ProvisioningOnly || len(h.Capabilities) != 0 || h.Capabilities == nil {
				t.Fatalf("provisioning falsely ready: %d %s", w.Code, w.Body.String())
			}
			if h.StorageReady != (failed != "database") || h.BrokerReady != (failed != "broker") {
				t.Fatal("infrastructure status is not actual health result")
			}
			if w.Header().Get("Cache-Control") != "no-store" {
				t.Fatal("health cached")
			}
		})
	}
}

func TestProvisioningHasNoAmbientHealthOrExecutionAccess(t *testing.T) {
	// Deliberately nil dependencies: any accidental storage/transport call fails
	// the test instead of letting a mock claim that it is harmless.
	s := Service{Config: provisioningConfig()}
	for _, token := range []string{"", "Bearer wrong-synthetic-credential-long-enough", "Bearer a synthetic service credential for tests only"} {
		r := httptest.NewRequest(http.MethodPost, "/railtime/v1/runs", strings.NewReader("{}"))
		r.Header.Set("Cookie", "console=synthetic")
		if token != "" {
			r.Header.Set("Authorization", token)
		}
		w := httptest.NewRecorder()
		s.Handler().ServeHTTP(w, r)
		want := 401
		if token == "Bearer a synthetic service credential for tests only" {
			want = 503
		}
		if w.Code != want {
			t.Fatalf("got %d want %d", w.Code, want)
		}
	}
	r := httptest.NewRequest(http.MethodGet, "/railtime/v1/health", nil)
	w := httptest.NewRecorder()
	s.Handler().ServeHTTP(w, r)
	if w.Code != 401 {
		t.Fatal("unauthenticated health accepted")
	}
	ctx := context.Background()
	if _, status, err := s.Accept(ctx, Principal{}, protocol.RunRequest{}); status != 503 || !errors.Is(err, ErrForbidden) {
		t.Fatal("direct accept not blocked")
	}
	if _, err := s.Get(ctx, Principal{}, "anything"); !errors.Is(err, ErrForbidden) {
		t.Fatal("direct lookup not blocked")
	}
	if err := s.DispatchOne(ctx); !errors.Is(err, ErrForbidden) {
		t.Fatal("dispatcher not blocked")
	}
	if _, err := s.ReceiveResult(ctx, "anything", nil); !errors.Is(err, ErrForbidden) {
		t.Fatal("result ingestion not blocked")
	}
}

package common

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"railtime.local/openuem-extension/server"
)

func testBootstrapConfig() BootstrapConfig {
	return BootstrapConfig{DatabaseURL: "postgres://fixture:synthetic@127.0.0.1:55479/fixture?sslmode=disable", TenantName: "Synthetic tenant", SiteName: "Synthetic site", SiteDomain: "example.invalid"}
}

func TestBootstrapConfigStrictAndSecretSafe(t *testing.T) {
	good, _ := json.Marshal(testBootstrapConfig())
	if _, err := decodeBootstrapConfig(good); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		`null`, `[]`, `{}`, string(good) + `{}`,
		strings.Replace(string(good), `"site_name":`, `"unexpected":`, 1),
		strings.Replace(string(good), `"site_name":"Synthetic site"`, `"site_name":null`, 1),
		strings.Replace(string(good), `"site_name":"Synthetic site"`, `"site_name":"first","site_name":"second"`, 1),
	} {
		if _, err := decodeBootstrapConfig([]byte(bad)); err == nil {
			t.Fatal("unsafe JSON accepted")
		}
	}
	for _, change := range []func(*BootstrapConfig){
		func(c *BootstrapConfig) {
			c.DatabaseURL = "postgres://fixture:DO-NOT-LOG@db.invalid:5432/fixture?sslmode=disable"
		},
		func(c *BootstrapConfig) {
			c.DatabaseURL = "postgres://fixture:DO-NOT-LOG@127.0.0.1:55479/fixture?sslmode=disable&search_path=foreign"
		},
		func(c *BootstrapConfig) {
			c.DatabaseURL = "postgres://fixture:DO-NOT-LOG@127.0.0.1:55479/fixture?sslmode=disable&sslmode=verify-full"
		},
		func(c *BootstrapConfig) { c.DatabaseURL = "postgres://fixture@127.0.0.1:55479/fixture?sslmode=disable" },
		func(c *BootstrapConfig) {
			c.DatabaseURL = "postgres://fixture:DO-NOT-LOG@127.0.0.1/fixture?sslmode=disable"
		},
		func(c *BootstrapConfig) {
			c.DatabaseURL = "postgres://fixture:DO-NOT-LOG@127.0.0.1:55479/?sslmode=disable"
		},
		func(c *BootstrapConfig) { c.TenantName = " name " },
		func(c *BootstrapConfig) { c.SiteName = "site\nunsafe" },
		func(c *BootstrapConfig) { c.SiteDomain = "https://example.invalid" },
		func(c *BootstrapConfig) { c.SiteDomain = "*.example.invalid" },
	} {
		c := testBootstrapConfig()
		change(&c)
		err := validateBootstrapConfig(c)
		if err == nil || strings.Contains(err.Error(), "DO-NOT-LOG") {
			t.Fatal("unsafe config accepted or secret disclosed")
		}
	}
	remote := testBootstrapConfig()
	remote.DatabaseURL = "postgres://fixture:synthetic@db.invalid:5432/fixture?sslmode=verify-full"
	if err := validateBootstrapConfig(remote); err != nil {
		t.Fatal("verified remote config rejected", err)
	}
}

func TestBootstrapRejectsEnvironmentBeforeConnection(t *testing.T) {
	t.Setenv("PGSERVICE", "synthetic-not-a-service")
	if _, err := BootstrapNativeDatabase(context.Background(), testBootstrapConfig()); err == nil || !strings.Contains(err.Error(), "environment") {
		t.Fatal("PostgreSQL environment was silently combined with protected config")
	}
}

func TestProvisioningOnlySkipsAllLegacySubscriptions(t *testing.T) {
	// A provisioning worker reaches the extension prerequisite check without
	// touching the deliberately nil NATS connection. The legacy branch would
	// attempt QueueSubscribe immediately and panic in this test.
	w := &Worker{railTimeConfig: &server.Config{Enabled: true, ProvisioningOnly: true}}
	if err := w.SubscribeToAgentWorkerQueues(); err == nil || err.Error() != "RailTime native prerequisites unavailable" {
		t.Fatalf("provisioning mode entered unexpected native startup path: %v", err)
	}
}

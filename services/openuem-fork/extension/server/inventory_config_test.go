package server

import (
	"railtime.local/openuem-extension/inventory"
	"testing"
)

func TestInventoryEnrollmentDoesNotGrantCommandAuthority(t *testing.T) {
	c := provisioningConfig()
	e := inventory.Enrollment{AgentID: "2867529f-0b4e-441c-83d7-707a0c745cbd", TenantID: c.Principals[0].TenantID, SiteID: c.Principals[0].SiteID}
	c.InventoryEnrollments = []inventory.Enrollment{e}
	if err := c.Validate(); err != nil {
		t.Fatal(err)
	}
	if c.Principals[0].Allows(e.AgentID, 1) {
		t.Fatal("inventory granted execution")
	}
	for name, mutate := range map[string]func(*Config){
		"duplicate":        func(c *Config) { c.InventoryEnrollments = append(c.InventoryEnrollments, e) },
		"unbound tenant":   func(c *Config) { c.InventoryEnrollments[0].TenantID++ },
		"unbound site":     func(c *Config) { c.InventoryEnrollments[0].SiteID++ },
		"invalid identity": func(c *Config) { c.InventoryEnrollments[0].AgentID = "device-a" },
	} {
		t.Run(name, func(t *testing.T) {
			bad := c
			bad.InventoryEnrollments = []inventory.Enrollment{e}
			mutate(&bad)
			if bad.Validate() == nil {
				t.Fatal("unsafe enrollment accepted")
			}
		})
	}
}

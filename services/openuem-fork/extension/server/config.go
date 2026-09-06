package server

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"

	"railtime.local/openuem-extension/inventory"
	"railtime.local/openuem-extension/protocol"
)

type Principal struct {
	ID           string   `json:"id"`
	BearerSHA256 string   `json:"bearer_sha256"`
	TenantID     int      `json:"tenant_id"`
	SiteID       int      `json:"site_id"`
	AgentIDs     []string `json:"agent_ids"`
	ProfileIDs   []int    `json:"profile_ids"`
}

type DeviceKey struct {
	AgentID   string `json:"agent_id"`
	KeyID     string `json:"key_id"`
	KeyBase64 string `json:"key_base64"`
}

func (k DeviceKey) Bytes() ([]byte, error) {
	return base64.StdEncoding.Strict().DecodeString(k.KeyBase64)
}

type Config struct {
	Native               *NativeConfig          `json:"native,omitempty"`
	Enabled              bool                   `json:"enabled"`
	ProvisioningOnly     bool                   `json:"provisioning_only"`
	Listen               string                 `json:"listen"`
	TLSCertificate       string                 `json:"tls_certificate"`
	TLSPrivateKey        string                 `json:"tls_private_key"`
	CommandTTLSeconds    int                    `json:"command_ttl_seconds"`
	Principals           []Principal            `json:"principals"`
	DeviceKeys           []DeviceKey            `json:"device_keys"`
	InventoryEnrollments []inventory.Enrollment `json:"inventory_enrollments,omitempty"`
}

// NativeConfig carries upstream service connection material in the same
// protected file, never in process arguments or newly introduced ENV values.
type NativeConfig struct {
	DatabaseURL       string `json:"database_url"`
	NATSServers       string `json:"nats_servers"`
	CACertificate     string `json:"ca_certificate"`
	ClientCertificate string `json:"client_certificate"`
	ClientPrivateKey  string `json:"client_private_key"`
}

// Empty path is the ONLY disabled-by-absence case. An explicitly selected but
// unreadable/insecure config never falls back to enabled defaults.
func LoadConfig(path string) (*Config, error) {
	if path == "" {
		return nil, nil
	}
	if err := ProtectedFile(path); err != nil {
		return nil, err
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, errors.New("run API config unavailable")
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || info.Size() > 128*1024 {
		return nil, errors.New("invalid run API config size")
	}
	current, statErr := os.Lstat(path)
	if statErr != nil || !os.SameFile(info, current) || current.Mode()&os.ModeSymlink != 0 {
		return nil, errors.New("run API config changed during open")
	}
	if err = protectedOwnership(info, true); err != nil {
		return nil, err
	}
	b, err := io.ReadAll(io.LimitReader(f, 128*1024+1))
	if err != nil {
		return nil, errors.New("run API config unavailable")
	}
	if len(b) > 128*1024 {
		return nil, errors.New("invalid run API config size")
	}
	var c Config
	if err = protocol.Decode(b, &c); err != nil {
		return nil, errors.New("invalid run API config JSON")
	}
	if !c.Enabled {
		return nil, nil
	}
	if c.Listen == "" {
		c.Listen = "127.0.0.1:9441"
	}
	if c.CommandTTLSeconds == 0 {
		c.CommandTTLSeconds = 900
	}
	if err = c.Validate(); err != nil {
		return nil, err
	}
	return &c, nil
}

func ProtectedFile(path string) error {
	if !filepath.IsAbs(path) {
		return errors.New("protected file path must be absolute")
	}
	for current := filepath.Clean(path); ; current = filepath.Dir(current) {
		info, err := os.Lstat(current)
		if err != nil {
			return errors.New("protected file unavailable")
		}
		if info.Mode()&os.ModeSymlink != 0 {
			return errors.New("protected file must not traverse symlinks")
		}
		if err := protectedOwnership(info, current == filepath.Clean(path)); err != nil {
			return err
		}
		if current == filepath.Clean(path) {
			if !info.Mode().IsRegular() {
				return errors.New("protected file must be regular")
			}
		}
		if parent := filepath.Dir(current); parent == current {
			break
		}
	}
	return nil
}

func (c Config) Validate() error {
	host, port, err := net.SplitHostPort(c.Listen)
	if err != nil || port == "" || port == "0" {
		return errors.New("invalid run API listener")
	}
	ip := net.ParseIP(host)
	if ip == nil || !ip.IsLoopback() {
		return errors.New("run API must bind an exact loopback IP")
	}
	if (c.TLSCertificate == "") != (c.TLSPrivateKey == "") {
		return errors.New("run API TLS certificate and key must be configured together")
	}
	if c.TLSCertificate != "" && (!filepath.IsAbs(c.TLSCertificate) || !filepath.IsAbs(c.TLSPrivateKey)) {
		return errors.New("run API TLS file paths must be absolute")
	}
	if c.CommandTTLSeconds < 30 || time.Duration(c.CommandTTLSeconds)*time.Second > 24*time.Hour {
		return errors.New("invalid command lifetime")
	}
	if len(c.Principals) == 0 || len(c.Principals) > 32 || len(c.DeviceKeys) > 10000 || len(c.InventoryEnrollments) > 10000 {
		return errors.New("explicit principals and device keys are required")
	}
	// A fresh native installation has no enrolled devices. Its authenticated
	// infrastructure check must not require invented device identities or keys.
	// This explicit mode cannot retain any execution authority.
	if c.ProvisioningOnly {
		if len(c.DeviceKeys) != 0 {
			return errors.New("provisioning-only mode forbids device keys")
		}
	} else if len(c.DeviceKeys) == 0 {
		return errors.New("execution requires explicit device keys")
	}
	agents := map[string]bool{}
	keyHashes := map[[32]byte]bool{}
	keyIDs := map[string]bool{}
	for _, key := range c.DeviceKeys {
		if _, err := protocol.CommandSubject(key.AgentID); err != nil {
			return errors.New("invalid configured agent")
		}
		b, err := key.Bytes()
		if err != nil || len(b) != 32 {
			return errors.New("device keys must have exactly 256 bits")
		}
		if _, err = protocol.Sign(protocol.AckContext, key.KeyID, b, protocol.Ack{}); err != nil {
			return errors.New("invalid device key identifier")
		}
		digest := sha256.Sum256(b)
		if agents[key.AgentID] || keyHashes[digest] || keyIDs[key.KeyID] {
			return errors.New("device IDs, key IDs and key material must be unique")
		}
		agents[key.AgentID] = true
		keyHashes[digest] = true
		keyIDs[key.KeyID] = true
	}
	ids := map[string]bool{}
	bearers := map[string]bool{}
	for _, p := range c.Principals {
		if _, err := protocol.CommandSubject(p.ID); err != nil {
			return errors.New("invalid principal identifier")
		}
		hash, err := hex.DecodeString(p.BearerSHA256)
		if err != nil || len(hash) != 32 || p.BearerSHA256 != strings.ToLower(p.BearerSHA256) || ids[p.ID] || bearers[p.BearerSHA256] {
			return errors.New("invalid or duplicate principal credential")
		}
		ids[p.ID] = true
		bearers[p.BearerSHA256] = true
		if p.TenantID <= 0 || p.SiteID <= 0 || len(p.AgentIDs) > 10000 || len(p.ProfileIDs) > 10000 {
			return errors.New("principal requires explicit tenant, site, agents and profiles")
		}
		if c.ProvisioningOnly {
			if len(p.AgentIDs) != 0 || len(p.ProfileIDs) != 0 {
				return errors.New("provisioning-only principal must have empty execution scopes")
			}
		} else if len(p.AgentIDs) == 0 || len(p.ProfileIDs) == 0 {
			return errors.New("execution requires explicit agents and profiles")
		}
		seenAgents := map[string]bool{}
		for _, id := range p.AgentIDs {
			if !agents[id] || seenAgents[id] {
				return errors.New("principal agent missing key or duplicated")
			}
			seenAgents[id] = true
		}
		seenProfiles := map[int]bool{}
		for _, id := range p.ProfileIDs {
			if id <= 0 || seenProfiles[id] {
				return errors.New("invalid or duplicate allowed profile")
			}
			seenProfiles[id] = true
		}
	}
	// Inventory is explicitly enrolled independently of command authority. It
	// may operate while provisioning-only still refuses every execution request.
	seenEnrollments := map[string]bool{}
	for _, enrollment := range c.InventoryEnrollments {
		if err := enrollment.Validate(); err != nil {
			return err
		}
		if seenEnrollments[enrollment.AgentID] {
			return errors.New("duplicate inventory enrollment")
		}
		seenEnrollments[enrollment.AgentID] = true
		allowed := false
		for _, principal := range c.Principals {
			if principal.TenantID == enrollment.TenantID && principal.SiteID == enrollment.SiteID {
				allowed = true
				break
			}
		}
		if !allowed {
			return errors.New("inventory enrollment lacks an explicit principal tenant/site")
		}
	}
	return nil
}

func (p Principal) Allows(agentID string, profileID int) bool {
	agentAllowed := false
	profileAllowed := false
	for _, id := range p.AgentIDs {
		if id == agentID {
			agentAllowed = true
		}
	}
	for _, id := range p.ProfileIDs {
		if id == profileID {
			profileAllowed = true
		}
	}
	return agentAllowed && profileAllowed
}

func (c Config) DeviceKey(agentID string) (DeviceKey, bool) {
	for _, k := range c.DeviceKeys {
		if k.AgentID == agentID {
			return k, true
		}
	}
	return DeviceKey{}, false
}

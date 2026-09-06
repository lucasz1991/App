package common

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/user"
	"strings"
	"unicode"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/open-uem/openuem-worker/internal/models"
	"railtime.local/openuem-extension/inventory"
	"railtime.local/openuem-extension/server"
)

type InventoryAdmissionConfig struct {
	WorkerConfig string `json:"worker_config"`
	models.InventoryAdmission
}

func LoadInventoryAdmissionConfig(path string) (InventoryAdmissionConfig, error) {
	var empty InventoryAdmissionConfig
	if err := server.ProtectedFile(path); err != nil {
		return empty, err
	}
	f, err := os.Open(path)
	if err != nil {
		return empty, errors.New("protected admission configuration unavailable")
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || info.Size() > 16*1024 {
		return empty, errors.New("invalid admission configuration size")
	}
	current, err := os.Lstat(path)
	if err != nil || !os.SameFile(info, current) || server.ProtectedFile(path) != nil {
		return empty, errors.New("admission configuration changed during open")
	}
	b, err := io.ReadAll(io.LimitReader(f, 16*1024+1))
	if err != nil {
		return empty, errors.New("protected admission configuration unavailable")
	}
	return decodeInventoryAdmissionConfig(b)
}

func decodeInventoryAdmissionConfig(b []byte) (InventoryAdmissionConfig, error) {
	var c InventoryAdmissionConfig
	if len(b) == 0 || len(b) > 16*1024 {
		return c, models.ErrInventoryRejected
	}
	d := json.NewDecoder(bytes.NewReader(b))
	start, err := d.Token()
	if err != nil || start != json.Delim('{') {
		return c, models.ErrInventoryRejected
	}
	seen := map[string]bool{}
	allowed := map[string]bool{"worker_config": true, "request_id": true, "agent_id": true, "tenant_id": true, "site_id": true, "reason": true}
	for d.More() {
		token, err := d.Token()
		key, ok := token.(string)
		if err != nil || !ok || !allowed[key] || seen[key] {
			return c, models.ErrInventoryRejected
		}
		var raw json.RawMessage
		if d.Decode(&raw) != nil || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
			return c, models.ErrInventoryRejected
		}
		seen[key] = true
	}
	end, err := d.Token()
	if err != nil || end != json.Delim('}') || len(seen) != len(allowed) || d.Decode(new(any)) != io.EOF {
		return c, models.ErrInventoryRejected
	}
	d = json.NewDecoder(bytes.NewReader(b))
	d.DisallowUnknownFields()
	if d.Decode(&c) != nil || c.WorkerConfig == "" || strings.TrimSpace(c.Reason) == "" || len(c.Reason) > 1000 || strings.IndexFunc(c.Reason, unicode.IsControl) >= 0 {
		return InventoryAdmissionConfig{}, models.ErrInventoryRejected
	}
	if (inventory.Enrollment{AgentID: c.AgentID, TenantID: c.TenantID, SiteID: c.SiteID}).Validate() != nil {
		return InventoryAdmissionConfig{}, models.ErrInventoryRejected
	}
	if _, err = inventory.Subject(c.RequestID); err != nil {
		return InventoryAdmissionConfig{}, models.ErrInventoryRejected
	}
	return c, nil
}

// AdmitInventoryDevice opens only the protected configuration's PostgreSQL
// connection. It does not migrate, connect to NATS, start workers, create keys,
// issue certificates, download software or execute anything on a device.
func AdmitInventoryDevice(ctx context.Context, c InventoryAdmissionConfig) (models.InventoryAdmissionResult, error) {
	var empty models.InventoryAdmissionResult
	config, err := server.LoadConfig(c.WorkerConfig)
	if err != nil || config == nil || config.Native == nil {
		return empty, errors.New("admission requires valid protected native worker configuration")
	}
	var matched *inventory.Enrollment
	for _, e := range config.InventoryEnrollments {
		if e.AgentID == c.AgentID && e.TenantID == c.TenantID && e.SiteID == c.SiteID {
			copy := e
			matched = &copy
		}
	}
	if matched == nil {
		return empty, models.ErrInventoryRejected
	}
	// Use the same strict explicit database policy as initial provisioning;
	// metadata here validates only strings and is never inserted or bootstrapped.
	if validateBootstrapConfig(BootstrapConfig{DatabaseURL: config.Native.DatabaseURL, TenantName: "Admission", SiteName: "Admission", SiteDomain: "invalid"}) != nil {
		return empty, errors.New("admission requires explicit protected database connection")
	}
	for _, entry := range os.Environ() {
		name, value, _ := strings.Cut(entry, "=")
		if strings.HasPrefix(name, "PG") && value != "" {
			return empty, errors.New("admission cannot combine protected configuration with PostgreSQL environment options")
		}
	}
	pg, err := pgx.ParseConfig(config.Native.DatabaseURL)
	if err != nil {
		return empty, errors.New("invalid protected admission database configuration")
	}
	pg.RuntimeParams = map[string]string{"search_path": "public"}
	db := stdlib.OpenDB(*pg)
	defer db.Close()
	operator, err := user.Current()
	if err != nil || operator.Uid == "" {
		return empty, errors.New("admission operator identity unavailable")
	}
	result, err := (models.InventoryStore{DB: db}).Admit(ctx, *matched, c.InventoryAdmission, operator.Uid)
	if err != nil {
		return empty, errors.New("admission refused; no state change committed")
	}
	return result, nil
}

package common

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"unicode"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/open-uem/openuem-worker/internal/models"
	"railtime.local/openuem-extension/server"
)

// BootstrapConfig has no executable content, default password or enrollment
// identity. It is separate from worker startup and is consumed only explicitly.
type BootstrapConfig struct {
	DatabaseURL string `json:"database_url"`
	TenantName  string `json:"tenant_name"`
	SiteName    string `json:"site_name"`
	SiteDomain  string `json:"site_domain"`
}

var bootstrapDatabaseName = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_-]{0,62}$`)
var bootstrapDomainLabel = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)

func LoadBootstrapConfig(path string) (BootstrapConfig, error) {
	var empty BootstrapConfig
	if err := server.ProtectedFile(path); err != nil {
		return empty, err
	}
	f, err := os.Open(path)
	if err != nil {
		return empty, errors.New("bootstrap configuration unavailable")
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || info.Size() > 16*1024 {
		return empty, errors.New("invalid bootstrap configuration size")
	}
	current, err := os.Lstat(path)
	if err != nil || !os.SameFile(info, current) || server.ProtectedFile(path) != nil {
		return empty, errors.New("bootstrap configuration changed during open")
	}
	b, err := io.ReadAll(io.LimitReader(f, 16*1024+1))
	if err != nil {
		return empty, errors.New("bootstrap configuration unavailable")
	}
	return decodeBootstrapConfig(b)
}

func decodeBootstrapConfig(b []byte) (BootstrapConfig, error) {
	var c BootstrapConfig
	if len(b) == 0 || len(b) > 16*1024 {
		return c, errors.New("invalid bootstrap configuration size")
	}
	// Decode the four string fields individually to reject duplicate keys as
	// well as unknown fields, null values, trailing data and nested content.
	d := json.NewDecoder(bytes.NewReader(b))
	start, err := d.Token()
	if err != nil || start != json.Delim('{') {
		return c, errors.New("invalid bootstrap configuration JSON")
	}
	fields := map[string]*string{"database_url": &c.DatabaseURL, "tenant_name": &c.TenantName, "site_name": &c.SiteName, "site_domain": &c.SiteDomain}
	seen := map[string]bool{}
	for d.More() {
		token, err := d.Token()
		key, ok := token.(string)
		field, known := fields[key]
		if err != nil || !ok || !known || seen[key] {
			return BootstrapConfig{}, errors.New("invalid or duplicate bootstrap configuration field")
		}
		value, err := d.Token()
		text, ok := value.(string)
		if err != nil || !ok {
			return BootstrapConfig{}, errors.New("bootstrap fields must be strings")
		}
		*field = text
		seen[key] = true
	}
	end, err := d.Token()
	if err != nil || end != json.Delim('}') || len(seen) != len(fields) || d.Decode(new(any)) != io.EOF {
		return BootstrapConfig{}, errors.New("invalid bootstrap configuration JSON")
	}
	if err = validateBootstrapConfig(c); err != nil {
		return BootstrapConfig{}, err
	}
	return c, nil
}

func validateBootstrapConfig(c BootstrapConfig) error {
	for _, name := range []string{c.TenantName, c.SiteName} {
		if name == "" || len(name) > 200 || strings.TrimSpace(name) != name || strings.IndexFunc(name, unicode.IsControl) >= 0 {
			return errors.New("explicit valid tenant and site names are required")
		}
	}
	if c.SiteDomain == "" || len(c.SiteDomain) > 253 || strings.ToLower(c.SiteDomain) != c.SiteDomain {
		return errors.New("explicit lowercase site domain is required")
	}
	for _, label := range strings.Split(c.SiteDomain, ".") {
		if !bootstrapDomainLabel.MatchString(label) {
			return errors.New("invalid site domain")
		}
	}
	u, err := url.Parse(c.DatabaseURL)
	if err != nil || u == nil || (u.Scheme != "postgres" && u.Scheme != "postgresql") || u.Hostname() == "" || strings.ContainsAny(u.Hostname(), ", \t\r\n") || u.Port() == "" || u.User == nil || u.User.Username() == "" || u.Fragment != "" {
		return errors.New("invalid explicit bootstrap database connection")
	}
	if password, exists := u.User.Password(); !exists || password == "" {
		return errors.New("bootstrap requires a protected database credential")
	}
	if !bootstrapDatabaseName.MatchString(strings.TrimPrefix(u.Path, "/")) || strings.Count(u.Path, "/") != 1 {
		return errors.New("bootstrap requires an explicit dedicated database name")
	}
	query, err := url.ParseQuery(u.RawQuery)
	if err != nil {
		return errors.New("invalid bootstrap database options")
	}
	for key, values := range query {
		if len(values) != 1 || (key != "sslmode" && key != "sslrootcert" && key != "connect_timeout") {
			return errors.New("unsupported or duplicate bootstrap database option")
		}
	}
	ip := net.ParseIP(u.Hostname())
	local := u.Hostname() == "localhost" || (ip != nil && ip.IsLoopback())
	if query.Get("sslmode") != "verify-full" && !(local && query.Get("sslmode") == "disable") {
		return errors.New("bootstrap requires verified database TLS except on explicit loopback")
	}
	if ca := query.Get("sslrootcert"); ca != "" && !filepath.IsAbs(ca) {
		return errors.New("database CA path must be absolute")
	}
	return nil
}

// BootstrapNativeDatabase never starts the worker, broker, scheduler or API.
// The only successful writes are atomic empty-schema initialization. After any
// conflict, existing objects and records remain untouched.
func BootstrapNativeDatabase(ctx context.Context, c BootstrapConfig) (models.BootstrapResult, error) {
	var empty models.BootstrapResult
	if err := validateBootstrapConfig(c); err != nil {
		return empty, err
	}
	// pgx normally consults PG* environment variables, including connection
	// service/password files. Do not silently combine them with this snapshot.
	for _, entry := range os.Environ() {
		name, value, _ := strings.Cut(entry, "=")
		if strings.HasPrefix(name, "PG") && value != "" {
			return empty, errors.New("bootstrap file cannot be mixed with PostgreSQL environment configuration")
		}
	}
	config, err := pgx.ParseConfig(c.DatabaseURL)
	if err != nil {
		return empty, errors.New("invalid protected bootstrap database connection")
	}
	config.RuntimeParams = map[string]string{"search_path": "public"}
	db := stdlib.OpenDB(*config)
	defer db.Close()
	result, err := models.BootstrapEmptyDatabase(ctx, db, models.BootstrapMetadata{TenantName: c.TenantName, SiteName: c.SiteName, SiteDomain: c.SiteDomain})
	if err != nil {
		// Database errors can contain role names, statement values or host detail.
		// Export only stable operator-safe categories, never a DSN or raw SQL.
		if errors.Is(err, models.ErrBootstrapConflict) {
			return empty, models.ErrBootstrapConflict
		}
		return empty, errors.New("native bootstrap failed; transaction rolled back or database unavailable")
	}
	return result, nil
}

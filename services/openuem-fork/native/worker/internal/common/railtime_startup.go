package common

import (
	"errors"
	"net"
	"net/url"
	"path/filepath"
	"strings"

	"github.com/jackc/pgx/v5"
	"github.com/open-uem/utils"
	"github.com/urfave/cli/v2"
	"railtime.local/openuem-extension/server"
)

func (w *Worker) CheckAgentStartupRequisites(c *cli.Context) error {
	if w.RailTimeConfigPath == "" {
		if c.String("dburl") == "" || c.String("nats-servers") == "" {
			return errors.New("native database and broker configuration are required")
		}
		return w.CheckCLICommonRequisites(c)
	}
	config, err := server.LoadConfig(w.RailTimeConfigPath)
	if err != nil {
		return err
	}
	if config == nil || config.Native == nil {
		return errors.New("explicit RailTime startup file must enable extension and include native configuration")
	}
	// Never silently combine a protected file with stale environment/CLI secrets.
	if c.IsSet("dburl") || c.IsSet("nats-servers") || c.IsSet("cacert") || c.IsSet("cert") || c.IsSet("key") {
		return errors.New("protected startup file cannot be combined with native credential flags or environment overrides")
	}
	if err = validateNativeStartup(*config.Native); err != nil {
		return err
	}
	n := config.Native
	ca, err := utils.ReadPEMCertificate(n.CACertificate)
	if err != nil {
		return errors.New("native CA certificate unavailable")
	}
	if _, err = utils.ReadPEMCertificate(n.ClientCertificate); err != nil {
		return errors.New("native worker certificate unavailable")
	}
	if err = server.ProtectedFile(n.ClientPrivateKey); err != nil {
		return err
	}
	if _, err = utils.ReadPEMPrivateKey(n.ClientPrivateKey); err != nil {
		return errors.New("native worker private key unavailable")
	}
	w.DBUrl = n.DatabaseURL
	w.NATSServers = n.NATSServers
	w.CACertPath = n.CACertificate
	w.CACert = ca
	w.ClientCertPath = n.ClientCertificate
	w.ClientKeyPath = n.ClientPrivateKey
	// Freeze the validated startup snapshot. Re-reading a changed JSON after
	// database connection would mix credentials/scopes from different revisions.
	w.railTimeConfig = config
	return nil
}

func validateNativeStartup(n server.NativeConfig) error {
	if !filepath.IsAbs(n.CACertificate) || !filepath.IsAbs(n.ClientCertificate) || !filepath.IsAbs(n.ClientPrivateKey) {
		return errors.New("native certificate paths must be absolute")
	}
	u, err := url.Parse(n.DatabaseURL)
	if err != nil || u == nil || (u.Scheme != "postgres" && u.Scheme != "postgresql") || u.Hostname() == "" || u.User == nil {
		return errors.New("invalid protected native database configuration")
	}
	if _, err = pgx.ParseConfig(n.DatabaseURL); err != nil {
		return errors.New("invalid protected native database configuration")
	}
	ip := net.ParseIP(u.Hostname())
	local := u.Hostname() == "localhost" || (ip != nil && ip.IsLoopback())
	if !local && u.Query().Get("sslmode") != "verify-full" {
		return errors.New("remote native database requires verified TLS")
	}
	if strings.TrimSpace(n.NATSServers) == "" {
		return errors.New("native broker configuration required")
	}
	for _, value := range strings.Split(n.NATSServers, ",") {
		broker, err := url.Parse(strings.TrimSpace(value))
		if err != nil || broker == nil || (broker.Scheme != "tls" && broker.Scheme != "wss") || broker.Hostname() == "" || broker.User != nil || broker.RawQuery != "" || broker.Fragment != "" || broker.Path != "" {
			return errors.New("native broker requires explicit TLS URLs and certificate authentication")
		}
	}
	return nil
}

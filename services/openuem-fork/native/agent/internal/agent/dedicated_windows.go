//go:build windows

package agent

import (
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"io"
	"log"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"time"

	"github.com/go-co-op/gocron/v2"
	"github.com/google/uuid"
	"github.com/nats-io/nats.go"
	"gopkg.in/ini.v1"
	"railtime.local/openuem-extension/agentexec"
	"railtime.local/openuem-extension/inventory"
)

func dedicatedPrivateRead(path string, limit int64) ([]byte, error) {
	if !filepath.IsAbs(path) || agentexec.CheckPrivatePath(filepath.Dir(path), true) != nil || agentexec.CheckPrivatePath(path, false) != nil {
		return nil, errDedicatedConfig
	}
	before, err := os.Lstat(path)
	if err != nil || before.Size() > limit {
		return nil, errDedicatedConfig
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, errDedicatedConfig
	}
	defer f.Close()
	opened, err := f.Stat()
	if err != nil || !os.SameFile(before, opened) || opened.Size() > limit {
		return nil, errDedicatedConfig
	}
	data, err := io.ReadAll(io.LimitReader(f, limit+1))
	after, statErr := os.Lstat(path)
	if err != nil || statErr != nil || len(data) > int(limit) || !os.SameFile(opened, after) || agentexec.CheckPrivatePath(path, false) != nil || agentexec.CheckPrivatePath(filepath.Dir(path), true) != nil {
		return nil, errDedicatedConfig
	}
	return data, nil
}

func readDedicatedConfig(path string) (Config, error) {
	data, err := dedicatedPrivateRead(path, 64<<10)
	if err != nil {
		return Config{}, err
	}
	c, err := parseDedicatedConfig(data)
	if err != nil {
		return Config{}, err
	}
	if _, err = dedicatedTLS(c, time.Now()); err != nil {
		return Config{}, err
	}
	return c, nil
}

func parseDedicatedConfig(data []byte) (Config, error) {
	f, err := ini.Load(data)
	if err != nil {
		return Config{}, errDedicatedConfig
	}
	c := Config{Dedicated: true}
	value := func(section, key string) string { return f.Section(section).Key(key).String() }
	for _, flag := range [][3]string{{"RailTime", "Dedicated", "true"}, {"Agent", "SFTPDisabled", "true"}, {"Agent", "RemoteAssistanceDisabled", "true"}, {"Agent", "Debug", "false"}, {"Agent", "RestartRequired", "false"}} {
		if value(flag[0], flag[1]) != flag[2] {
			return Config{}, errDedicatedConfig
		}
	}
	c.SFTPDisabled, c.RemoteAssistanceDisabled = true, true
	c.UUID, c.TenantID, c.SiteID = value("Agent", "UUID"), value("Agent", "TenantID"), value("Agent", "SiteID")
	c.SFTPPort, c.VNCProxyPort = value("Agent", "SFTPPort"), value("Agent", "VNCProxyPort")
	c.NATSServers, c.WebSocketPort = value("NATS", "NATSServers"), value("NATS", "WebSocketPort")
	c.AgentCert, c.AgentKey, c.CACert = value("Certificates", "AgentCert"), value("Certificates", "AgentKey"), value("Certificates", "CACert")
	for key, target := range map[string]*int{"DefaultFrequency": &c.DefaultFrequency, "ExecuteTaskEveryXMinutes": &c.ExecuteTaskEveryXMinutes} {
		*target, err = strconv.Atoi(value("Agent", key))
		if err != nil {
			return Config{}, errDedicatedConfig
		}
	}
	if enabled := value("Agent", "Enabled"); enabled != "true" && enabled != "false" {
		return Config{}, errDedicatedConfig
	} else {
		c.Enabled = enabled == "true"
	}
	if validateDedicatedConfig(c) != nil {
		return Config{}, errDedicatedConfig
	}
	return c, nil
}

func validateDedicatedConfig(c Config) error {
	id, err := uuid.Parse(c.UUID)
	if !c.Dedicated || err != nil || id == uuid.Nil || id.String() != c.UUID || !c.SFTPDisabled || !c.RemoteAssistanceDisabled || c.Debug || c.SFTPPort != "0" || c.VNCProxyPort != "0" || c.WebSocketPort != "" || c.DefaultFrequency < 1 || c.DefaultFrequency > 1440 || c.ExecuteTaskEveryXMinutes < 1 || c.ExecuteTaskEveryXMinutes > 1440 {
		return errDedicatedConfig
	}
	for _, value := range []string{c.TenantID, c.SiteID} {
		id, err := strconv.Atoi(value)
		if err != nil || id <= 0 || strconv.Itoa(id) != value {
			return errDedicatedConfig
		}
	}
	for _, path := range []string{c.AgentCert, c.AgentKey, c.CACert} {
		if !filepath.IsAbs(path) || filepath.Clean(path) != path {
			return errDedicatedConfig
		}
	}
	_, err = dedicatedEndpoint(c.NATSServers)
	return err
}

func dedicatedEndpoint(endpoint string) (*url.URL, error) {
	u, err := url.Parse(endpoint)
	if err != nil || u.Scheme != "tls" || u.User != nil || u.Host == "" || u.Path != "" || u.RawPath != "" || u.RawQuery != "" || u.ForceQuery || u.Fragment != "" || u.Opaque != "" {
		return nil, errDedicatedConfig
	}
	host, port, err := net.SplitHostPort(u.Host)
	p, intErr := strconv.Atoi(port)
	if err != nil || intErr != nil || host == "" || p < 1 || p > 65535 || strconv.Itoa(p) != port {
		return nil, errDedicatedConfig
	}
	return u, nil
}

func dedicatedTLS(c Config, now time.Time) (*tls.Config, error) {
	if validateDedicatedConfig(c) != nil {
		return nil, errDedicatedConfig
	}
	ca, err := dedicatedPrivateRead(c.CACert, 64<<10)
	if err != nil {
		return nil, err
	}
	certificate, err := dedicatedPrivateRead(c.AgentCert, 64<<10)
	if err != nil {
		return nil, err
	}
	key, err := dedicatedPrivateRead(c.AgentKey, 64<<10)
	if err != nil {
		return nil, err
	}
	return dedicatedTLSMaterial(c, ca, certificate, key, now)
}

func dedicatedTLSMaterial(c Config, ca, certificate, key []byte, now time.Time) (*tls.Config, error) {
	u, err := dedicatedEndpoint(c.NATSServers)
	if err != nil {
		return nil, err
	}
	identity, err := inventory.ClientIdentity(c.UUID)
	if err != nil {
		return nil, errDedicatedConfig
	}
	block, rest := pem.Decode(key)
	if block == nil || block.Type != "RSA PRIVATE KEY" || len(rest) != 0 {
		return nil, errDedicatedConfig
	}
	privateKey, err := x509.ParsePKCS1PrivateKey(block.Bytes)
	if err != nil || privateKey.N.BitLen() < 2048 {
		return nil, errDedicatedConfig
	}
	pair, err := tls.X509KeyPair(certificate, key)
	if err != nil || len(pair.Certificate) == 0 {
		return nil, errDedicatedConfig
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil || leaf.IsCA || len(leaf.DNSNames) != 1 || leaf.DNSNames[0] != identity || len(leaf.IPAddresses) != 0 || len(leaf.EmailAddresses) != 0 || len(leaf.URIs) != 0 {
		return nil, errDedicatedConfig
	}
	publicKey, ok := leaf.PublicKey.(*rsa.PublicKey)
	if !ok || publicKey.N.BitLen() < 2048 {
		return nil, errDedicatedConfig
	}
	roots, intermediates := x509.NewCertPool(), x509.NewCertPool()
	if !roots.AppendCertsFromPEM(ca) {
		return nil, errDedicatedConfig
	}
	for _, raw := range pair.Certificate[1:] {
		cert, err := x509.ParseCertificate(raw)
		if err != nil {
			return nil, errDedicatedConfig
		}
		intermediates.AddCert(cert)
	}
	if _, err = leaf.Verify(x509.VerifyOptions{Roots: roots, Intermediates: intermediates, CurrentTime: now, DNSName: identity, KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}); err != nil {
		return nil, errDedicatedConfig
	}
	return &tls.Config{MinVersion: tls.VersionTLS12, ServerName: u.Hostname(), RootCAs: roots, Certificates: []tls.Certificate{pair}}, nil
}

func dedicatedJob(s gocron.Scheduler, minutes int, tick func()) (gocron.Job, error) {
	if s == nil || tick == nil || minutes < 1 || minutes > 1440 {
		return nil, errDedicatedConfig
	}
	return s.NewJob(gocron.DurationJob(time.Duration(minutes)*time.Minute), gocron.NewTask(tick), gocron.WithName("railtime-dedicated-inventory"), gocron.WithSingletonMode(gocron.LimitModeReschedule), gocron.WithStartAt(gocron.WithStartImmediately()))
}

func dedicatedNATSOptions(c Config, material *tls.Config) []nats.Option {
	return []nats.Option{nats.Secure(material), nats.IgnoreDiscoveredServers(), nats.MaxReconnects(-1), nats.Timeout(10 * time.Second), nats.Name("railtime-dedicated-agent"), nats.CustomInboxPrefix("_INBOX.railtime." + c.UUID)}
}

func (a *Agent) startDedicated() error {
	if validateDedicatedConfig(a.Config) != nil || a.TaskScheduler == nil {
		return errDedicatedConfig
	}
	a.dedicatedMu = &sync.Mutex{}
	a.dedicatedConnectionMu = &sync.RWMutex{}
	job, err := dedicatedJob(a.TaskScheduler, a.Config.DefaultFrequency, a.dedicatedTick)
	if err != nil {
		return errDedicatedConfig
	}
	a.ReportJob = job
	a.TaskScheduler.Start()
	return nil
}

// One serialized scheduler job owns initial connection, retry and inventory.
// There are no upstream subscriptions, remote config requests or task schedules.
func (a *Agent) dedicatedTick() {
	if !a.Config.Dedicated || !a.Config.Enabled || a.dedicatedMu == nil || !a.dedicatedMu.TryLock() {
		return
	}
	defer a.dedicatedMu.Unlock()
	a.dedicatedConnectionMu.RLock()
	stopping := a.dedicatedStopping
	a.dedicatedConnectionMu.RUnlock()
	if stopping {
		return
	}
	connection := a.railTimeConnection()
	if connection == nil || connection.IsClosed() {
		material, err := dedicatedTLS(a.Config, time.Now())
		if err != nil {
			log.Print("[ERROR]: dedicated TLS identity rejected; no legacy fallback")
			return
		}
		connection, err = nats.Connect(a.Config.NATSServers, dedicatedNATSOptions(a.Config, material)...)
		if err != nil {
			log.Print("[WARN]: dedicated broker unavailable; inventory remains pending")
			return
		}
		a.dedicatedConnectionMu.Lock()
		if a.dedicatedStopping {
			a.dedicatedConnectionMu.Unlock()
			connection.Close()
			return
		}
		a.NATSConnection = connection
		a.dedicatedConnectionMu.Unlock()
	}
	if !connection.IsConnected() || a.railTimeConnection() == nil {
		return
	}
	// Missing execution.json is a supported inventory-only state. The existing
	// v1 loader separately requires protected enrollment, HMAC and journal.
	a.subscribeRailTime()
	r := a.RunReport()
	if r == nil || a.SendReport(r) != nil {
		log.Print("[WARN]: dedicated inventory not durably acknowledged")
		return
	}
	log.Print("[INFO]: dedicated inventory durably acknowledged; admission is a separate server decision")
}

func (a *Agent) stopDedicated() {
	if a.dedicatedConnectionMu != nil {
		a.dedicatedConnectionMu.Lock()
		a.dedicatedStopping = true
		connection := a.NATSConnection
		a.dedicatedConnectionMu.Unlock()
		if connection != nil {
			connection.Close()
		}
	}
	if a.TaskScheduler != nil {
		if err := a.TaskScheduler.Shutdown(); err != nil {
			log.Print("[WARN]: dedicated inventory collector is still stopping; transport is disabled")
		}
	}
	a.stopRailTime()
}

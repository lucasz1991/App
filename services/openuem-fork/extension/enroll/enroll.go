// Package enroll implements a new-only, explicitly staged Windows enrollment.
// The Platform boundary permits tests without creating accounts or services.
package enroll

import (
	"bytes"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/asn1"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"railtime.local/openuem-extension/inventory"
)

const ServiceName = "openuem-agent"
const maxPublic = 128 * 1024
const maxBinary = 128 * 1024 * 1024

var ErrRefused = errors.New("enrollment refused; no existing data is overwritten; inspect protected state")
var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
var hashPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// Roots are supplied only by the platform's Windows KnownFolder API, never CLI,
// environment variables, bundle fields, current directory or the registry.
type Roots struct{ ProgramData, ProgramFiles string }

func (r Roots) Private() string { return filepath.Join(r.ProgramData, "OpenUEM", "RailTimeEnrollment") }
func (r Roots) Agent() string   { return filepath.Join(r.ProgramFiles, "OpenUEM", "Agent") }
func (r Roots) Binary() string  { return filepath.Join(r.Agent(), "openuem-agent.exe") }
func (r Roots) INI() string     { return filepath.Join(r.Agent(), "config", "openuem.ini") }

type ServiceState struct {
	Exists      bool   `json:"exists"`
	Running     bool   `json:"running"`
	Disabled    bool   `json:"disabled"`
	Automatic   bool   `json:"automatic"`
	BinaryPath  string `json:"binary_path,omitempty"`
	LocalSystem bool   `json:"local_system"`
	OwnProcess  bool   `json:"own_process"`
}

type Platform interface {
	Roots() (Roots, error)
	RequireAdmin() error
	// Hold pins every existing directory component against rename/deletion and
	// checks links. Private descendants require SYSTEM/Administrators-only ACLs.
	Hold(Roots) (func(), error)
	Exists(string) (bool, error)
	EnsurePrivateParent(string) error
	MkdirNew(string) error
	WriteNew(string, []byte) error
	Read(string, int64, bool) ([]byte, error)
	Service() (ServiceState, error)
	CreateDisabled(string) error
	StartAutomatic(string) error
	DisableStop(string) error
}

type Request struct {
	Version         int    `json:"version"`
	AgentUUID       string `json:"agent_uuid"`
	ClientIdentity  string `json:"client_identity"`
	CSRPEM          string `json:"csr_pem"`
	PublicKeySHA256 string `json:"public_key_sha256"`
}

// Bundle contains PUBLIC data only. Its exact-byte SHA256 and CA fingerprint
// must be obtained through an authenticated administrator review channel.
// Certificate signature authenticates the client key; the external bundle pin
// also authenticates endpoint, tenant/site and the unsigned binary's hash.
type Bundle struct {
	Version                 int    `json:"version"`
	AgentUUID               string `json:"agent_uuid"`
	ClientIdentity          string `json:"client_identity"`
	TenantID                int    `json:"tenant_id"`
	SiteID                  int    `json:"site_id"`
	NATSURL                 string `json:"nats_url"`
	CAPEM                   string `json:"ca_pem"`
	ClientCertificatePEM    string `json:"client_certificate_pem"`
	ClientCertificateSHA256 string `json:"client_certificate_sha256"`
	AgentSHA256             string `json:"agent_sha256"`
}

type receipt struct {
	Version      int               `json:"version"`
	UUID         string            `json:"uuid"`
	BundleSHA256 string            `json:"bundle_sha256"`
	CASHA256     string            `json:"ca_sha256"`
	Files        map[string]string `json:"files"`
}

func digest(b []byte) string { sum := sha256.Sum256(b); return hex.EncodeToString(sum[:]) }
func identity(uuid string) string {
	id, _ := inventory.ClientIdentity(uuid) // UUIDv4 is checked before trust/use.
	return id
}
func encode(v any) []byte { b, _ := json.MarshalIndent(v, "", "  "); return append(b, '\n') }

// decode additionally rejects duplicate keys and trailing JSON, to make the
// reviewed public bytes have one unambiguous meaning to signer and installer.
func decode(b []byte, v any) error {
	if len(b) == 0 || len(b) > maxPublic {
		return ErrRefused
	}
	d := json.NewDecoder(bytes.NewReader(b))
	var walk func(int) error
	walk = func(depth int) error {
		if depth > 8 {
			return ErrRefused
		}
		t, err := d.Token()
		if err != nil {
			return ErrRefused
		}
		if delim, ok := t.(json.Delim); ok {
			switch delim {
			case '{':
				seen := map[string]bool{}
				for d.More() {
					k, e := d.Token()
					s, ok := k.(string)
					if e != nil || !ok || seen[s] {
						return ErrRefused
					}
					seen[s] = true
					if e = walk(depth + 1); e != nil {
						return e
					}
				}
			case '[':
				for d.More() {
					if err := walk(depth + 1); err != nil {
						return err
					}
				}
			default:
				return ErrRefused
			}
			if _, err = d.Token(); err != nil {
				return ErrRefused
			}
		}
		return nil
	}
	if walk(0) != nil {
		return ErrRefused
	}
	if _, err := d.Token(); err != io.EOF {
		return ErrRefused
	}
	d = json.NewDecoder(bytes.NewReader(b))
	d.DisallowUnknownFields()
	if d.Decode(v) != nil {
		return ErrRefused
	}
	return nil
}

func generateRequest() (Request, []byte, error) {
	var id [16]byte
	if _, err := rand.Read(id[:]); err != nil {
		return Request{}, nil, err
	}
	id[6] = (id[6] & 15) | 64
	id[8] = (id[8] & 63) | 128
	s := hex.EncodeToString(id[:])
	uuid := s[:8] + "-" + s[8:12] + "-" + s[12:16] + "-" + s[16:20] + "-" + s[20:]
	key, err := rsa.GenerateKey(rand.Reader, 3072)
	if err != nil {
		return Request{}, nil, err
	}
	// Explicitly request clientAuth; signing policy remains server-controlled.
	eku, err := asn1.Marshal([]asn1.ObjectIdentifier{{1, 3, 6, 1, 5, 5, 7, 3, 2}})
	if err != nil {
		return Request{}, nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject: pkix.Name{CommonName: identity(uuid)}, DNSNames: []string{identity(uuid)},
		SignatureAlgorithm: x509.SHA256WithRSA,
		ExtraExtensions:    []pkix.Extension{{Id: asn1.ObjectIdentifier{2, 5, 29, 37}, Value: eku}},
	}, key)
	if err != nil {
		return Request{}, nil, err
	}
	pub, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return Request{}, nil, err
	}
	return Request{1, uuid, identity(uuid), string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: csr})), digest(pub)},
		pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)}), nil
}

func absent(p Platform, paths ...string) error {
	for _, path := range paths {
		exists, err := p.Exists(path)
		if err != nil || exists {
			return ErrRefused
		}
	}
	return nil
}

// Prepare performs no service creation/start or networking. Failure preserves
// partial private state for explicit investigation, never a retry/reset.
func Prepare(p Platform) (Request, error) {
	if err := p.RequireAdmin(); err != nil {
		return Request{}, err
	}
	r, err := p.Roots()
	if err != nil {
		return Request{}, err
	}
	release, err := p.Hold(r)
	if err != nil {
		return Request{}, err
	}
	defer release()
	svc, err := p.Service()
	if err != nil || svc.Exists {
		return Request{}, ErrRefused
	}
	if err = absent(p, r.Private(), r.Agent()); err != nil {
		return Request{}, err
	}
	req, key, err := generateRequest()
	if err != nil {
		return Request{}, err
	}
	if err = p.EnsurePrivateParent(filepath.Dir(r.Private())); err != nil {
		return Request{}, err
	}
	if err = p.MkdirNew(r.Private()); err != nil {
		return Request{}, err
	}
	if err = p.WriteNew(filepath.Join(r.Private(), "agent-key.pem"), key); err != nil {
		return Request{}, err
	}
	if err = p.WriteNew(filepath.Join(r.Private(), "request.json"), encode(req)); err != nil {
		return Request{}, err
	}
	return req, nil
}

func parseKey(b []byte) (*rsa.PrivateKey, error) {
	block, rest := pem.Decode(b)
	if block == nil || block.Type != "RSA PRIVATE KEY" || len(block.Headers) != 0 || len(bytes.TrimSpace(rest)) != 0 {
		return nil, ErrRefused
	}
	k, err := x509.ParsePKCS1PrivateKey(block.Bytes)
	if err != nil || k.N.BitLen() != 3072 || k.Validate() != nil {
		return nil, ErrRefused
	}
	return k, nil
}

func certificate(s string) (*x509.Certificate, error) {
	b, rest := pem.Decode([]byte(s))
	if b == nil || b.Type != "CERTIFICATE" || len(b.Headers) != 0 || len(bytes.TrimSpace(rest)) != 0 {
		return nil, ErrRefused
	}
	c, err := x509.ParseCertificate(b.Bytes)
	if err != nil {
		return nil, ErrRefused
	}
	return c, nil
}

func validate(req Request, keyPEM []byte, b Bundle, caPin string, now time.Time) error {
	if req.Version != 1 || !uuidPattern.MatchString(req.AgentUUID) || req.ClientIdentity != identity(req.AgentUUID) ||
		b.Version != 1 || b.AgentUUID != req.AgentUUID || b.ClientIdentity != req.ClientIdentity || b.TenantID < 1 || b.SiteID < 1 ||
		!hashPattern.MatchString(b.AgentSHA256) || !hashPattern.MatchString(caPin) || !hashPattern.MatchString(b.ClientCertificateSHA256) {
		return ErrRefused
	}
	u, err := url.Parse(b.NATSURL)
	if err != nil || u.Scheme != "tls" || u.Hostname() == "" || u.User != nil || u.Path != "" || u.RawQuery != "" || u.Fragment != "" || u.Opaque != "" || u.ForceQuery || strings.ContainsAny(b.NATSURL, "\r\n\x00, \t;#") {
		return ErrRefused
	}
	host, port, err := net.SplitHostPort(u.Host)
	n, pe := strconv.Atoi(port)
	if err != nil || pe != nil || n < 1 || n > 65535 || host != u.Hostname() || strconv.Itoa(n) != port {
		return ErrRefused
	}
	key, err := parseKey(keyPEM)
	if err != nil {
		return err
	}
	pub, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil || digest(pub) != req.PublicKeySHA256 {
		return ErrRefused
	}
	csrBlock, rest := pem.Decode([]byte(req.CSRPEM))
	if csrBlock == nil || csrBlock.Type != "CERTIFICATE REQUEST" || len(bytes.TrimSpace(rest)) != 0 {
		return ErrRefused
	}
	csr, err := x509.ParseCertificateRequest(csrBlock.Bytes)
	if err != nil || csr.CheckSignature() != nil || csr.Subject.CommonName != req.ClientIdentity || len(csr.DNSNames) != 1 || csr.DNSNames[0] != req.ClientIdentity || !bytes.Equal(csr.RawSubjectPublicKeyInfo, pub) {
		return ErrRefused
	}
	ca, err := certificate(b.CAPEM)
	if err != nil || !ca.IsCA || ca.KeyUsage&x509.KeyUsageCertSign == 0 || digest(ca.Raw) != caPin || ca.CheckSignatureFrom(ca) != nil {
		return ErrRefused
	}
	cert, err := certificate(b.ClientCertificatePEM)
	if err != nil || cert.IsCA || digest(cert.Raw) != b.ClientCertificateSHA256 || cert.Subject.CommonName != req.ClientIdentity || len(cert.DNSNames) != 1 || cert.DNSNames[0] != req.ClientIdentity ||
		len(cert.IPAddresses)+len(cert.EmailAddresses)+len(cert.URIs) != 0 || len(cert.ExtKeyUsage) != 1 || cert.ExtKeyUsage[0] != x509.ExtKeyUsageClientAuth || len(cert.UnknownExtKeyUsage) != 0 ||
		cert.KeyUsage&x509.KeyUsageDigitalSignature == 0 || !bytes.Equal(cert.RawSubjectPublicKeyInfo, pub) {
		return ErrRefused
	}
	roots := x509.NewCertPool()
	roots.AddCert(ca)
	if _, err = cert.Verify(x509.VerifyOptions{Roots: roots, CurrentTime: now, KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, DNSName: req.ClientIdentity}); err != nil {
		return ErrRefused
	}
	return nil
}

func makeINI(r Roots, b Bundle) ([]byte, error) {
	for _, path := range []string{r.Private(), r.Agent()} {
		if strings.ContainsAny(path, "\r\n\x00\";#") {
			return nil, ErrRefused
		}
	}
	return []byte(fmt.Sprintf("[Agent]\r\nUUID=%s\r\nEnabled=true\r\nExecuteTaskEveryXMinutes=15\r\nDefaultFrequency=15\r\nDebug=false\r\nRestartRequired=false\r\nSFTPPort=0\r\nVNCProxyPort=0\r\nSFTPDisabled=true\r\nRemoteAssistanceDisabled=true\r\nTenantID=%d\r\nSiteID=%d\r\n\r\n[RailTime]\r\nDedicated=true\r\n\r\n[NATS]\r\nNATSServers=%s\r\n\r\n[Certificates]\r\nAgentCert=%s\r\nAgentKey=%s\r\nCACert=%s\r\n", b.AgentUUID, b.TenantID, b.SiteID, b.NATSURL, filepath.Join(r.Private(), "agent-cert.pem"), filepath.Join(r.Private(), "agent-key.pem"), filepath.Join(r.Private(), "ca.pem"))), nil
}

func filePaths(r Roots) map[string]string {
	return map[string]string{
		"request": filepath.Join(r.Private(), "request.json"), "key": filepath.Join(r.Private(), "agent-key.pem"),
		"bundle": filepath.Join(r.Private(), "bundle.json"), "cert": filepath.Join(r.Private(), "agent-cert.pem"),
		"ca": filepath.Join(r.Private(), "ca.pem"), "binary": r.Binary(), "ini": r.INI(),
	}
}

// Install reads untrusted input once, verifies all bytes before writes, then
// installs a disabled service. A second invocation refuses even partial state.
func Install(p Platform, bundlePath, bundlePin, caPin, binaryPath string, now time.Time) (err error) {
	if err = p.RequireAdmin(); err != nil {
		return err
	}
	if !hashPattern.MatchString(bundlePin) || !hashPattern.MatchString(caPin) {
		return ErrRefused
	}
	r, err := p.Roots()
	if err != nil {
		return err
	}
	release, err := p.Hold(r)
	if err != nil {
		return err
	}
	defer release()
	s, err := p.Service()
	if err != nil || s.Exists {
		return ErrRefused
	}
	paths := filePaths(r)
	if err = absent(p, r.Agent(), paths["bundle"], paths["cert"], paths["ca"], filepath.Join(r.Private(), "installed.json")); err != nil {
		return err
	}
	requestBytes, err := p.Read(paths["request"], maxPublic, true)
	if err != nil {
		return err
	}
	var req Request
	if decode(requestBytes, &req) != nil {
		return ErrRefused
	}
	key, err := p.Read(paths["key"], maxPublic, true)
	if err != nil {
		return err
	}
	public, err := p.Read(bundlePath, maxPublic, false)
	if err != nil || digest(public) != bundlePin {
		return ErrRefused
	}
	var b Bundle
	if decode(public, &b) != nil {
		return ErrRefused
	}
	if err = validate(req, key, b, caPin, now); err != nil {
		return err
	}
	binary, err := p.Read(binaryPath, maxBinary, false)
	if err != nil || digest(binary) != b.AgentSHA256 || len(binary) < 2 || string(binary[:2]) != "MZ" {
		return ErrRefused
	}
	ini, err := makeINI(r, b)
	if err != nil {
		return err
	}
	if err = p.EnsurePrivateParent(filepath.Dir(r.Agent())); err != nil {
		return err
	}
	if err = p.MkdirNew(r.Agent()); err != nil {
		return err
	}
	if err = p.MkdirNew(filepath.Dir(r.INI())); err != nil {
		return err
	}
	values := map[string][]byte{"bundle": public, "cert": []byte(b.ClientCertificatePEM), "ca": []byte(b.CAPEM), "binary": binary, "ini": ini, "request": requestBytes, "key": key}
	for _, name := range []string{"bundle", "cert", "ca", "binary", "ini"} {
		if err = p.WriteNew(paths[name], values[name]); err != nil {
			return err
		}
	}
	if err = p.CreateDisabled(r.Binary()); err != nil {
		return err
	}
	defer func() {
		if err != nil {
			err = errors.Join(err, p.DisableStop(r.Binary()))
		}
	}()
	manifest := receipt{1, req.AgentUUID, bundlePin, caPin, map[string]string{}}
	for name, v := range values {
		manifest.Files[name] = digest(v)
	}
	if err = p.WriteNew(filepath.Join(r.Private(), "installed.json"), encode(manifest)); err != nil {
		return err
	}
	s, err = p.Service()
	if err != nil || !serviceMatches(s, r) || !s.Disabled || s.Running {
		return ErrRefused
	}
	return nil
}

func serviceMatches(s ServiceState, r Roots) bool {
	return s.Exists && s.BinaryPath == `"`+r.Binary()+`"` && s.LocalSystem && s.OwnProcess
}

// Start is deliberately separate. Local running status does not prove server
// admission, inventory delivery, remote control or device command readiness.
func Start(p Platform, now time.Time) (err error) {
	if err = p.RequireAdmin(); err != nil {
		return err
	}
	r, err := p.Roots()
	if err != nil {
		return err
	}
	release, err := p.Hold(r)
	if err != nil {
		return err
	}
	defer release()
	s, err := p.Service()
	if err != nil || !serviceMatches(s, r) || s.Running || !s.Disabled {
		return ErrRefused
	}
	defer func() {
		if err != nil {
			err = errors.Join(err, p.DisableStop(r.Binary()))
		}
	}()
	raw, err := p.Read(filepath.Join(r.Private(), "installed.json"), maxPublic, true)
	if err != nil {
		return err
	}
	var receipt receipt
	if decode(raw, &receipt) != nil || receipt.Version != 1 {
		return ErrRefused
	}
	paths := filePaths(r)
	if len(receipt.Files) != len(paths) {
		return ErrRefused
	}
	values := map[string][]byte{}
	for name, path := range paths {
		limit := int64(maxPublic)
		if name == "binary" {
			limit = maxBinary
		}
		data, e := p.Read(path, limit, true)
		if e != nil || !hashPattern.MatchString(receipt.Files[name]) || digest(data) != receipt.Files[name] {
			return ErrRefused
		}
		values[name] = data
	}
	var req Request
	var b Bundle
	if decode(values["request"], &req) != nil || decode(values["bundle"], &b) != nil || req.AgentUUID != receipt.UUID || digest(values["bundle"]) != receipt.BundleSHA256 {
		return ErrRefused
	}
	if err = validate(req, values["key"], b, receipt.CASHA256, now); err != nil {
		return err
	}
	ini, err := makeINI(r, b)
	if err != nil || !bytes.Equal(ini, values["ini"]) || !bytes.Equal(values["cert"], []byte(b.ClientCertificatePEM)) || !bytes.Equal(values["ca"], []byte(b.CAPEM)) || digest(values["binary"]) != b.AgentSHA256 {
		return ErrRefused
	}
	return p.StartAutomatic(r.Binary())
}

type Status struct {
	PrivatePath    string       `json:"private_path"`
	AgentPath      string       `json:"agent_path"`
	Prepared       bool         `json:"prepared_files_present"`
	Installed      bool         `json:"installation_receipt_present"`
	Service        ServiceState `json:"service"`
	ExecutionReady bool         `json:"execution_ready"`
	Note           string       `json:"note"`
}

// Inspect requests no elevation, writes nothing and does not contact a server.
func Inspect(p Platform) (Status, error) {
	r, err := p.Roots()
	if err != nil {
		return Status{}, err
	}
	s, err := p.Service()
	if err != nil {
		return Status{}, err
	}
	prepared, err := p.Exists(filepath.Join(r.Private(), "request.json"))
	if err != nil {
		return Status{}, err
	}
	installed, err := p.Exists(filepath.Join(r.Private(), "installed.json"))
	if err != nil {
		return Status{}, err
	}
	return Status{r.Private(), r.Agent(), prepared, installed, s, false, "Presence and SCM status only; no cryptographic acceptance or inventory/remote-control readiness claim."}, nil
}

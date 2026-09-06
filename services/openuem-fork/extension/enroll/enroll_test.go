package enroll

import (
	"bytes"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// Every test uses in-memory filesystem/SCM doubles. No actual KnownFolder,
// service, elevation, persistent private key or machine ACL is touched.
type fakePlatform struct {
	r                      Roots
	admin                  bool
	files                  map[string][]byte
	dirs                   map[string]bool
	unsafe                 map[string]bool
	s                      ServiceState
	mutations              int
	creates, starts, stops int
	failWrite              string
	failStart              bool
	holdErr                bool
}

func fake(t *testing.T) *fakePlatform {
	base := t.TempDir()
	return &fakePlatform{r: Roots{filepath.Join(base, "ProgramData"), filepath.Join(base, "Program Files")}, admin: true, files: map[string][]byte{}, dirs: map[string]bool{}, unsafe: map[string]bool{}}
}
func (f *fakePlatform) Roots() (Roots, error) { return f.r, nil }
func (f *fakePlatform) RequireAdmin() error {
	if !f.admin {
		return ErrRefused
	}
	return nil
}
func (f *fakePlatform) Hold(Roots) (func(), error) {
	if f.holdErr {
		return nil, ErrRefused
	}
	return func() {}, nil
}
func (f *fakePlatform) Exists(p string) (bool, error) {
	if f.unsafe[p] {
		return false, ErrRefused
	}
	_, ok := f.files[p]
	return ok || f.dirs[p], nil
}
func (f *fakePlatform) EnsurePrivateParent(p string) error {
	if f.unsafe[p] {
		return ErrRefused
	}
	if !f.dirs[p] {
		f.dirs[p] = true
		f.mutations++
	}
	return nil
}
func (f *fakePlatform) MkdirNew(p string) error {
	if exists, _ := f.Exists(p); exists || f.unsafe[p] {
		return ErrRefused
	}
	f.dirs[p] = true
	f.mutations++
	return nil
}
func (f *fakePlatform) WriteNew(p string, b []byte) error {
	if exists, _ := f.Exists(p); exists || f.unsafe[p] || p == f.failWrite {
		return ErrRefused
	}
	f.files[p] = bytes.Clone(b)
	f.mutations++
	return nil
}
func (f *fakePlatform) Read(p string, n int64, _ bool) ([]byte, error) {
	b, ok := f.files[p]
	if !ok || f.unsafe[p] || int64(len(b)) > n {
		return nil, ErrRefused
	}
	return bytes.Clone(b), nil
}
func (f *fakePlatform) Service() (ServiceState, error) { return f.s, nil }
func (f *fakePlatform) CreateDisabled(p string) error {
	if f.s.Exists {
		return ErrRefused
	}
	f.creates++
	f.mutations++
	f.s = ServiceState{Exists: true, Disabled: true, BinaryPath: `"` + p + `"`, LocalSystem: true, OwnProcess: true}
	return nil
}
func (f *fakePlatform) StartAutomatic(string) error {
	f.starts++
	f.mutations++
	f.s.Disabled = false
	f.s.Automatic = true
	f.s.Running = true
	if f.failStart {
		return ErrRefused
	}
	return nil
}
func (f *fakePlatform) DisableStop(string) error {
	f.stops++
	f.mutations++
	f.s.Disabled = true
	f.s.Automatic = false
	f.s.Running = false
	return nil
}

var once sync.Once
var fixtureReq Request
var fixtureKey []byte
var fixtureCA *x509.Certificate
var fixtureCAKey *rsa.PrivateKey
var fixtureBundle Bundle
var fixtureFailure error
var fixtureNow = time.Date(2026, 9, 6, 19, 0, 0, 0, time.UTC)

func fixtures(t *testing.T) (Request, []byte, Bundle) {
	t.Helper()
	once.Do(func() {
		fixtureReq, fixtureKey, fixtureFailure = generateRequest()
		if fixtureFailure != nil {
			return
		}
		fixtureCAKey, fixtureFailure = rsa.GenerateKey(rand.Reader, 2048)
		if fixtureFailure != nil {
			return
		}
		tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Synthetic test CA"}, NotBefore: fixtureNow.Add(-time.Hour), NotAfter: fixtureNow.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign}
		der, e := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &fixtureCAKey.PublicKey, fixtureCAKey)
		if e != nil {
			fixtureFailure = e
			return
		}
		fixtureCA, fixtureFailure = x509.ParseCertificate(der)
		if fixtureFailure != nil {
			return
		}
		fixtureBundle = Bundle{Version: 1, AgentUUID: fixtureReq.AgentUUID, ClientIdentity: fixtureReq.ClientIdentity, TenantID: 1, SiteID: 1, NATSURL: "tls://devices.example.test:4443", CAPEM: string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})), AgentSHA256: digest([]byte("MZsynthetic native agent bytes; not an executable"))}
	})
	if fixtureFailure != nil {
		t.Fatal(fixtureFailure)
	}
	b := fixtureBundle
	b = signTestCertificate(t, b, nil)
	return fixtureReq, bytes.Clone(fixtureKey), b
}

func signTestCertificate(t *testing.T, b Bundle, change func(*x509.Certificate)) Bundle {
	t.Helper()
	key, err := parseKey(fixtureKey)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: fixtureReq.ClientIdentity}, DNSNames: []string{fixtureReq.ClientIdentity}, NotBefore: fixtureNow.Add(-time.Minute), NotAfter: fixtureNow.Add(30 * time.Minute), ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, KeyUsage: x509.KeyUsageDigitalSignature, BasicConstraintsValid: true}
	if change != nil {
		change(tmpl)
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, fixtureCA, &key.PublicKey, fixtureCAKey)
	if err != nil {
		t.Fatal(err)
	}
	b.ClientCertificatePEM = string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
	b.ClientCertificateSHA256 = digest(der)
	return b
}

func staged(t *testing.T) (*fakePlatform, Bundle, string, string) {
	t.Helper()
	f := fake(t)
	req, key, b := fixtures(t)
	f.dirs[f.r.Private()] = true
	f.files[filepath.Join(f.r.Private(), "request.json")] = encode(req)
	f.files[filepath.Join(f.r.Private(), "agent-key.pem")] = key
	bundlePath := filepath.Join(f.r.ProgramData, "reviewed-bundle.json")
	binaryPath := filepath.Join(f.r.ProgramData, "reviewed-agent.exe")
	f.files[bundlePath] = encode(b)
	f.files[binaryPath] = []byte("MZsynthetic native agent bytes; not an executable")
	return f, b, bundlePath, binaryPath
}
func installFake(t *testing.T, f *fakePlatform, bundlePath, binaryPath string) {
	t.Helper()
	if err := Install(f, bundlePath, digest(f.files[bundlePath]), digest(fixtureCA.Raw), binaryPath, fixtureNow); err != nil {
		t.Fatal(err)
	}
}

func TestGenerateRequestUsesLocal3072PKCS1UUIDAndClientAuth(t *testing.T) {
	req, key, _ := fixtures(t)
	if !uuidPattern.MatchString(req.AgentUUID) || req.ClientIdentity != identity(req.AgentUUID) {
		t.Fatal("identity")
	}
	k, err := parseKey(key)
	if err != nil || k.N.BitLen() != 3072 {
		t.Fatal("key")
	}
	b, _ := pem.Decode([]byte(req.CSRPEM))
	csr, err := x509.ParseCertificateRequest(b.Bytes)
	if err != nil || csr.CheckSignature() != nil {
		t.Fatal("CSR")
	}
	if len(csr.DNSNames) != 1 || csr.DNSNames[0] != req.ClientIdentity {
		t.Fatal("SAN")
	}
	eku := false
	for _, e := range csr.Extensions {
		if e.Id.String() == "2.5.29.37" {
			eku = true
		}
	}
	if !eku {
		t.Fatal("clientAuth intention missing")
	}
	if bytes.Contains(encode(req), []byte("PRIVATE KEY")) {
		t.Fatal("public request leaked private key")
	}
}

func TestPrepareRequiresElevationAndRefusesExistingBeforeWrites(t *testing.T) {
	for _, kind := range []string{"admin", "service", "agent", "enrollment", "link"} {
		t.Run(kind, func(t *testing.T) {
			f := fake(t)
			switch kind {
			case "admin":
				f.admin = false
			case "service":
				f.s.Exists = true
			case "agent":
				f.dirs[f.r.Agent()] = true
			case "enrollment":
				f.dirs[f.r.Private()] = true
			case "link":
				f.holdErr = true
			}
			if _, err := Prepare(f); err == nil || f.mutations != 0 {
				t.Fatal("unsafe preparation")
			}
		})
	}
}

func TestPrepareDoesNotCreateOrStartService(t *testing.T) {
	f := fake(t)
	req, err := Prepare(f)
	if err != nil {
		t.Fatal(err)
	}
	if f.creates != 0 || f.starts != 0 || f.s.Exists || len(f.files) != 2 {
		t.Fatal("unexpected effects")
	}
	if !bytes.Equal(f.files[filepath.Join(f.r.Private(), "request.json")], encode(req)) {
		t.Fatal("request")
	}
	before := f.mutations
	if _, err = Prepare(f); err == nil || f.mutations != before {
		t.Fatal("repeat overwrote state")
	}
}

func TestCertificateAndBundleBoundary(t *testing.T) {
	req, key, b := fixtures(t)
	pin := digest(fixtureCA.Raw)
	if validate(req, key, b, pin, fixtureNow) != nil {
		t.Fatal("valid bundle rejected")
	}
	tests := map[string]func(*Bundle){
		"wrong uuid":                  func(v *Bundle) { v.AgentUUID = "00000000-0000-4000-8000-000000000000" },
		"wrong identity":              func(v *Bundle) { v.ClientIdentity = "other" },
		"zero site":                   func(v *Bundle) { v.SiteID = 0 },
		"zero tenant":                 func(v *Bundle) { v.TenantID = 0 },
		"bad binary hash":             func(v *Bundle) { v.AgentSHA256 = "wrong" },
		"bad certificate fingerprint": func(v *Bundle) { v.ClientCertificateSHA256 = strings.Repeat("0", 64) },
		"server only": func(v *Bundle) {
			*v = signTestCertificate(t, *v, func(c *x509.Certificate) { c.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth} })
		},
		"multiple SAN": func(v *Bundle) {
			*v = signTestCertificate(t, *v, func(c *x509.Certificate) { c.DNSNames = append(c.DNSNames, "unexpected.example.test") })
		},
		"expired": func(v *Bundle) {
			*v = signTestCertificate(t, *v, func(c *x509.Certificate) { c.NotAfter = fixtureNow.Add(-time.Second) })
		},
		"leaf is CA": func(v *Bundle) {
			*v = signTestCertificate(t, *v, func(c *x509.Certificate) { c.IsCA = true; c.KeyUsage |= x509.KeyUsageCertSign })
		},
		"extra PEM": func(v *Bundle) { v.ClientCertificatePEM += v.CAPEM },
	}
	for name, change := range tests {
		t.Run(name, func(t *testing.T) {
			v := b
			change(&v)
			if validate(req, key, v, pin, fixtureNow) == nil {
				t.Fatal("unsafe bundle accepted")
			}
		})
	}
	if validate(req, key, b, strings.Repeat("0", 64), fixtureNow) == nil {
		t.Fatal("wrong CA accepted")
	}
	wrongKey := bytes.Replace(key, []byte("RSA PRIVATE KEY"), []byte("PRIVATE KEY"), 1)
	if validate(req, wrongKey, b, pin, fixtureNow) == nil {
		t.Fatal("key format accepted")
	}
}

func TestEndpointRejectsCredentialsPlaintextMultipleAndINIInjection(t *testing.T) {
	req, key, b := fixtures(t)
	for _, u := range []string{"nats://example.test:4222", "tls://user:password@example.test:4222", "tls://example.test:4222?x=1", "tls://example.test:4222#x", "tls://example.test:4222/path", "tls://example.test", "tls://example.test:0001", "tls://example.test:65536", "tls://example.test:4222,tls://other:4222", "tls://example.test:4222\r\n[Other]", "tls://example.test:4222?"} {
		t.Run(u, func(t *testing.T) {
			v := b
			v.NATSURL = u
			if validate(req, key, v, digest(fixtureCA.Raw), fixtureNow) == nil {
				t.Fatal("unsafe endpoint accepted")
			}
		})
	}
}

func TestStrictJSONRejectsUnknownDuplicateTrailing(t *testing.T) {
	for _, raw := range []string{`{"version":1,"version":2}`, `{"version":1,"unknown":true}`, `{"version":1} {}`, `{"version":1,"agent_uuid":{"a":1,"a":2}}`} {
		var req Request
		if decode([]byte(raw), &req) == nil {
			t.Fatal("ambiguous JSON accepted")
		}
	}
}

func TestInstallSeparatesConfigurationAndStart(t *testing.T) {
	f, b, bp, ap := staged(t)
	installFake(t, f, bp, ap)
	if f.creates != 1 || f.starts != 0 || !f.s.Disabled || f.s.Running {
		t.Fatal("started during install")
	}
	ini := string(f.files[f.r.INI()])
	for _, want := range []string{"UUID=" + b.AgentUUID, "RestartRequired=false", "[RailTime]\r\nDedicated=true", "SFTPDisabled=true", "RemoteAssistanceDisabled=true", "SFTPPort=0", "VNCProxyPort=0", "AgentKey=" + filepath.Join(f.r.Private(), "agent-key.pem")} {
		if !strings.Contains(ini, want) {
			t.Fatal("missing INI safety field", want)
		}
	}
	if strings.Contains(ini, "Password") || strings.Contains(ini, "HMAC") {
		t.Fatal("invented credentials")
	}
	if err := Start(f, fixtureNow); err != nil {
		t.Fatal(err)
	}
	if f.starts != 1 || !f.s.Running || !f.s.Automatic || f.s.Disabled {
		t.Fatal("start state")
	}
	if status, err := Inspect(f); err != nil || status.ExecutionReady {
		t.Fatal("readiness overclaim")
	}
}

func TestInstallRejectsAllBadInputsBeforeMutation(t *testing.T) {
	for _, kind := range []string{"not admin", "service", "existing agent", "bundle hash", "binary hash", "binary link", "key link", "certificate", "unsafe parent"} {
		t.Run(kind, func(t *testing.T) {
			f, _, bp, ap := staged(t)
			pin := digest(f.files[bp])
			switch kind {
			case "not admin":
				f.admin = false
			case "service":
				f.s.Exists = true
			case "existing agent":
				f.dirs[f.r.Agent()] = true
			case "bundle hash":
				pin = strings.Repeat("0", 64)
			case "binary hash":
				f.files[ap] = []byte("MZdifferent")
			case "binary link":
				f.unsafe[ap] = true
			case "key link":
				f.unsafe[filepath.Join(f.r.Private(), "agent-key.pem")] = true
			case "certificate":
				var b Bundle
				_ = decode(f.files[bp], &b)
				b.ClientCertificatePEM = "bad"
				f.files[bp] = encode(b)
				pin = digest(f.files[bp])
			case "unsafe parent":
				f.holdErr = true
			}
			if err := Install(f, bp, pin, digest(fixtureCA.Raw), ap, fixtureNow); err == nil || f.mutations != 0 {
				t.Fatal("unsafe install mutated state")
			}
		})
	}
}

func TestInstallFailurePreservesPartialDataAndNeverStarts(t *testing.T) {
	for _, phase := range []string{"ini", "receipt"} {
		t.Run(phase, func(t *testing.T) {
			f, _, bp, ap := staged(t)
			f.failWrite = f.r.INI()
			if phase == "receipt" {
				f.failWrite = filepath.Join(f.r.Private(), "installed.json")
			}
			err := Install(f, bp, digest(f.files[bp]), digest(fixtureCA.Raw), ap, fixtureNow)
			if err == nil || f.starts != 0 || len(f.files) < 4 {
				t.Fatal("partial failure not preserved")
			}
			if phase == "receipt" && (!f.s.Disabled || f.s.Running || f.stops != 1) {
				t.Fatal("service unsafe on receipt failure")
			}
			f.failWrite = ""
			before := f.mutations
			if err := Install(f, bp, digest(f.files[bp]), digest(fixtureCA.Raw), ap, fixtureNow); err == nil || f.mutations != before {
				t.Fatal("partial retry accepted")
			}
		})
	}
}

func TestStartTamperOrFailureDisablesAndStopsOnlyOwnService(t *testing.T) {
	for _, kind := range []string{"ini", "cert", "key", "binary", "start failure", "expired"} {
		t.Run(kind, func(t *testing.T) {
			f, _, bp, ap := staged(t)
			installFake(t, f, bp, ap)
			now := fixtureNow
			if kind == "start failure" {
				f.failStart = true
			} else if kind == "expired" {
				now = now.Add(24 * time.Hour)
			} else {
				path := filePaths(f.r)[kind]
				f.files[path] = append(f.files[path], byte('x'))
			}
			if err := Start(f, now); err == nil || !f.s.Disabled || f.s.Running || f.stops != 1 {
				t.Fatal("failed start left unsafe service")
			}
		})
	}
	f, _, bp, ap := staged(t)
	installFake(t, f, bp, ap)
	f.s.BinaryPath = `"C:\another-service.exe"`
	before := f.mutations
	if err := Start(f, fixtureNow); err == nil || f.mutations != before || f.stops != 0 {
		t.Fatal("foreign service touched")
	}
}

func TestInspectNeverMutatesOrElevates(t *testing.T) {
	f := fake(t)
	f.admin = false
	s, err := Inspect(f)
	if err != nil || s.ExecutionReady || f.mutations != 0 {
		t.Fatal("status mutation")
	}
	if !errors.Is(ErrRefused, ErrRefused) {
		t.Fatal("sentinel")
	}
}

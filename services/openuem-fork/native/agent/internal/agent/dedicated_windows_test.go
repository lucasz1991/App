//go:build windows

package agent

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"go/ast"
	"go/parser"
	"go/token"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/go-co-op/gocron/v2"
	"github.com/nats-io/nats.go"
	"golang.org/x/sys/windows"
	"railtime.local/openuem-extension/inventory"
)

func dedicatedINI() string {
	return `[RailTime]
Dedicated=true
[Agent]
UUID=` + testDedicatedUUID + `
TenantID=7
SiteID=9
Enabled=true
Debug=false
RestartRequired=false
ExecuteTaskEveryXMinutes=15
DefaultFrequency=15
SFTPPort=0
VNCProxyPort=0
SFTPDisabled=true
RemoteAssistanceDisabled=true
[NATS]
NATSServers=tls://inventory.example.test:14443
[Certificates]
AgentCert=C:\Synthetic\agent.cer
AgentKey=C:\Synthetic\agent.key
CACert=C:\Synthetic\ca.cer
`
}

func TestDedicatedINIRequiresExplicitSafeEnrollment(t *testing.T) {
	if _, err := parseDedicatedConfig([]byte(dedicatedINI())); err != nil {
		t.Fatal(err)
	}
	for name, pair := range map[string][2]string{
		"opt-in": {"Dedicated=true", "Dedicated=false"}, "empty uuid": {testDedicatedUUID, ""}, "noncanonical uuid": {testDedicatedUUID, strings.ToUpper(testDedicatedUUID)}, "zero tenant": {"TenantID=7", "TenantID=0"}, "ambiguous site": {"SiteID=9", "SiteID=09"}, "sftp": {"SFTPDisabled=true", "SFTPDisabled=false"}, "remote": {"RemoteAssistanceDisabled=true", "RemoteAssistanceDisabled=false"}, "debug": {"Debug=false", "Debug=true"}, "restart": {"RestartRequired=false", "RestartRequired=true"}, "tight schedule": {"DefaultFrequency=15", "DefaultFrequency=0"}, "legacy port": {"SFTPPort=0", "SFTPPort=2022"}, "relative key": {`C:\Synthetic\agent.key`, "agent.key"}, "websocket fallback": {"[NATS]", "[NATS]\nWebSocketPort=443"},
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := parseDedicatedConfig([]byte(strings.ReplaceAll(dedicatedINI(), pair[0], pair[1]))); err == nil {
				t.Fatal("unsafe dedicated INI accepted")
			}
		})
	}
	for _, endpoint := range []string{"nats://example.test:4222", "wss://example.test:443", "tls://user:password@example.test:14443", "tls://example.test", "tls://example.test:0", "tls://example.test:65536", "tls://example.test:14443/path", "tls://example.test:14443?token=x", "tls://example.test:14443#fragment", "tls://example.test:14443,tls://other.test:14443"} {
		if _, err := dedicatedEndpoint(endpoint); err == nil {
			t.Fatalf("unsafe endpoint accepted: %s", endpoint)
		}
	}
}

func TestDedicatedProtectedReadRejectsPublicFilesAndDirectories(t *testing.T) {
	root := t.TempDir()
	u, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		t.Fatal(err)
	}
	private := "D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FA;;;" + u.User.Sid.String() + ")"
	setACL := func(path, sddl string) {
		t.Helper()
		sd, err := windows.SecurityDescriptorFromString(sddl)
		if err != nil {
			t.Fatal(err)
		}
		acl, _, err := sd.DACL()
		if err != nil {
			t.Fatal(err)
		}
		if err := windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, acl, nil); err != nil {
			t.Fatal(err)
		}
	}
	setACL(root, private)
	path := filepath.Join(root, "synthetic-only.txt")
	if err := os.WriteFile(path, []byte("synthetic test data, not a device credential"), 0600); err != nil {
		t.Fatal(err)
	}
	setACL(path, private)
	if _, err := dedicatedPrivateRead(path, 1024); err != nil {
		t.Fatal("protected synthetic file refused", err)
	}
	if _, err := dedicatedPrivateRead(path, 1); err == nil {
		t.Fatal("oversized protected file accepted")
	}
	setACL(path, private+"(A;;FR;;;WD)")
	if _, err := dedicatedPrivateRead(path, 1024); err == nil {
		t.Fatal("public-readable credential file accepted")
	}
	setACL(path, private)
	setACL(root, private+"(A;OICI;FR;;;WD)")
	if _, err := dedicatedPrivateRead(path, 1024); err == nil {
		t.Fatal("public-readable containing directory accepted")
	}
}

// All keys below are ephemeral IN-MEMORY test fixtures. No local credential
// files, certificate stores, services or network connections are created.
func testDedicatedTLSMaterial(t *testing.T, alter func(*x509.Certificate)) (Config, []byte, []byte, []byte, time.Time) {
	t.Helper()
	c, err := parseDedicatedConfig([]byte(dedicatedINI()))
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now().UTC()
	caKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Synthetic test CA"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	caDER, err := x509.CreateCertificate(rand.Reader, ca, ca, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	identity, _ := inventory.ClientIdentity(c.UUID)
	leaf := &x509.Certificate{SerialNumber: big.NewInt(2), NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), DNSNames: []string{identity}, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}
	if alter != nil {
		alter(leaf)
	}
	certDER, err := x509.CreateCertificate(rand.Reader, leaf, ca, &key.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	return c, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER}), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certDER}), pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)}), now
}

func TestDedicatedTLSRequiresIdentityChainAndServerVerification(t *testing.T) {
	c, ca, cert, key, now := testDedicatedTLSMaterial(t, nil)
	cfg, err := dedicatedTLSMaterial(c, ca, cert, key, now)
	if err != nil || cfg.InsecureSkipVerify || cfg.ServerName != "inventory.example.test" || cfg.MinVersion < tls.VersionTLS12 || cfg.RootCAs == nil || len(cfg.Certificates) != 1 {
		t.Fatal("strict TLS configuration unavailable", err)
	}
	for name, change := range map[string]func(*x509.Certificate){
		"wrong identity":     func(c *x509.Certificate) { c.DNSNames = []string{"other-device"} },
		"ambiguous identity": func(c *x509.Certificate) { c.DNSNames = append(c.DNSNames, "other-device") },
		"server only":        func(c *x509.Certificate) { c.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth} },
		"expired":            func(c *x509.Certificate) { c.NotBefore = now.Add(-2 * time.Hour); c.NotAfter = now.Add(-time.Hour) },
		"CA leaf":            func(c *x509.Certificate) { c.IsCA = true; c.BasicConstraintsValid = true },
	} {
		t.Run(name, func(t *testing.T) {
			c, ca, cert, key, at := testDedicatedTLSMaterial(t, change)
			if _, err := dedicatedTLSMaterial(c, ca, cert, key, at); err == nil {
				t.Fatal("unsafe TLS identity accepted")
			}
		})
	}
	if _, err := dedicatedTLSMaterial(c, []byte("not a CA"), cert, key, now); err == nil {
		t.Fatal("missing CA accepted")
	}
	if _, err := dedicatedTLSMaterial(c, ca, cert, []byte("not a private key"), now); err == nil {
		t.Fatal("malformed private key accepted")
	}
	block, _ := pem.Decode(key)
	rsaKey, _ := x509.ParsePKCS1PrivateKey(block.Bytes)
	pkcs8, _ := x509.MarshalPKCS8PrivateKey(rsaKey)
	if _, err := dedicatedTLSMaterial(c, ca, cert, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: pkcs8}), now); err == nil {
		t.Fatal("incompatible PKCS8 key accepted")
	}
}

func TestDedicatedSchedulerRegistersOnlyInventoryWithoutExecuting(t *testing.T) {
	s, err := gocron.NewScheduler()
	if err != nil {
		t.Fatal(err)
	}
	defer s.Shutdown()
	if _, err = dedicatedJob(s, 15, func() { t.Error("unstarted test scheduler executed") }); err != nil {
		t.Fatal(err)
	}
	if jobs := s.Jobs(); len(jobs) != 1 || jobs[0].Name() != "railtime-dedicated-inventory" {
		t.Fatal("unexpected scheduled jobs")
	}
	a := Agent{Config: Config{Dedicated: true}}
	if a.startNATSConnectJob() == nil || a.startCheckForWinGetProfilesJob() == nil {
		t.Fatal("legacy Windows scheduling reachable")
	}
	// Invalid enrollment and nil dependencies must stop before service/config
	// mutations, network access, collection, or any legacy startup fallback.
	a.Start()
	a.Stop()
}

func TestDedicatedNATSInboxIsPerDeviceWithoutConnecting(t *testing.T) {
	opts := nats.GetDefaultOptions()
	material := &tls.Config{MinVersion: tls.VersionTLS12, ServerName: "synthetic.test"}
	for _, option := range dedicatedNATSOptions(Config{UUID: testDedicatedUUID}, material) {
		if err := option(&opts); err != nil {
			t.Fatal(err)
		}
	}
	if opts.InboxPrefix != "_INBOX.railtime."+testDedicatedUUID || !opts.Secure || opts.TLSConfig != material || !opts.IgnoreDiscoveredServers || opts.Timeout != 10*time.Second {
		t.Fatal("dedicated inbox/TLS options do not match scoped broker permissions")
	}
}

func TestDedicatedStartupGuardsPrecedeEveryLegacyStartupPath(t *testing.T) {
	for filename, names := range map[string][]string{"agent_windows.go": {"Start", "startNATSConnectJob", "startCheckForWinGetProfilesJob"}, "agent.go": {"SendReport", "ReportTask", "SubscribeToNATSSubjects", "CreateAgentJetStreamConsumer", "GetRemoteConfig", "startPendingACKJob", "PendingACKTask"}} {
		file, err := parser.ParseFile(token.NewFileSet(), filename, nil, 0)
		if err != nil {
			t.Fatal(err)
		}
		for _, name := range names {
			found := false
			for _, declaration := range file.Decls {
				fn, ok := declaration.(*ast.FuncDecl)
				if !ok || fn.Name.Name != name {
					continue
				}
				guard, ok := fn.Body.List[0].(*ast.IfStmt)
				if !ok {
					t.Fatalf("%s lacks first-statement dedicated guard", name)
				}
				condition, ok := guard.Cond.(*ast.SelectorExpr)
				if !ok || condition.Sel.Name != "Dedicated" {
					t.Fatalf("%s has wrong mode guard", name)
				}
				if _, ok := guard.Body.List[len(guard.Body.List)-1].(*ast.ReturnStmt); !ok {
					t.Fatalf("%s can fall through to legacy code", name)
				}
				found = true
			}
			if !found {
				t.Fatalf("startup method %s not checked", name)
			}
		}
	}
	file, err := parser.ParseFile(token.NewFileSet(), "dedicated_windows.go", nil, 0)
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"SubscribeToNATSSubjects", "CreateAgentJetStreamConsumer", "GetRemoteConfig", "startPendingACKJob", "startCheckForWinGetProfilesJob", "WriteConfig", "SetRestartRequiredFlag", "ResetRestartRequiredFlag", "StartRemoteDesktopSubscribe", "ConnectWithNATS", "Request"} {
		ast.Inspect(file, func(node ast.Node) bool {
			call, ok := node.(*ast.CallExpr)
			if ok {
				if fn, ok := call.Fun.(*ast.SelectorExpr); ok && fn.Sel.Name == name {
					t.Errorf("dedicated runtime calls forbidden legacy operation %s", name)
				}
			}
			return true
		})
	}
}

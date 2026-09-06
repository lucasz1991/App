package server

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"net"
	"runtime"
	"strconv"
	"testing"
)

func testConfig() Config {
	token := sha256.Sum256([]byte("a synthetic service credential for tests only"))
	return Config{Enabled:true,Listen:"127.0.0.1:9441",CommandTTLSeconds:900,
		Principals:[]Principal{{ID:"railtime-test",BearerSHA256:hex.EncodeToString(token[:]),TenantID:1,SiteID:2,AgentIDs:[]string{"device-a"},ProfileIDs:[]int{3}}},
		DeviceKeys:[]DeviceKey{{AgentID:"device-a",KeyID:"key-a",KeyBase64:base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{7},32))}},
	}
}

func TestConfigRejectsPublicOrPartialTLS(t *testing.T){
	for _,address:=range []string{"0.0.0.0:9441","[::]:9441","localhost:9441","127.0.0.1:0"}{c:=testConfig();c.Listen=address;if c.Validate()==nil{t.Errorf("accepted %q",address)}}
	c:=testConfig();if err:=c.Validate();err!=nil{t.Fatal(err)};c.TLSCertificate="/private/test.crt";if c.Validate()==nil{t.Fatal("partial TLS accepted")}
}

func TestConfigRequiresPerDeviceUniqueKeys(t *testing.T){
	c:=testConfig();other:=c.DeviceKeys[0];other.AgentID="device-b";other.KeyID="key-b";c.DeviceKeys=append(c.DeviceKeys,other);if c.Validate()==nil{t.Fatal("shared fleetwide HMAC key accepted")}
	c=testConfig();c.Principals[0].AgentIDs=[]string{"unbound-agent"};if c.Validate()==nil{t.Fatal("agent without key accepted")}
	c=testConfig();c.Principals[0].ProfileIDs=nil;if c.Validate()==nil{t.Fatal("unscoped profile access accepted")}
	c=testConfig();duplicate:=c.Principals[0];duplicate.ID="another-service";c.Principals=append(c.Principals,duplicate);if c.Validate()==nil{t.Fatal("shared bearer credential accepted")}
}

func TestBearerIsHashedAndNotAmbient(t *testing.T){
	s:=Service{Config:testConfig()};if _,ok:=s.Authenticate("a synthetic service credential for tests only");!ok{t.Fatal("valid token rejected")};if _,ok:=s.Authenticate(s.Config.Principals[0].BearerSHA256);ok{t.Fatal("hash accepted as bearer")};if _,ok:=s.Authenticate("short");ok{t.Fatal("short token accepted")}
}

func TestNoConfigNoListenerAndWindowsDoesNotPretendPOSIXChecks(t *testing.T){
	if c,err:=LoadConfig("");err!=nil||c!=nil{t.Fatal("omitted config must disable extension")}
	if runtime.GOOS=="windows"{if err:=protectedOwnership(nil,true);err==nil{t.Fatal("Windows ACL safety was not verified")}}
}

func TestPlainListenerRemainsLoopback(t *testing.T){
	// Allocate and release an ephemeral candidate. Start still binds exactly the
	// loopback IP and fails if another process takes it before us.
	l,err:=net.Listen("tcp","127.0.0.1:0");if err!=nil{t.Fatal(err)};port:=l.Addr().(*net.TCPAddr).Port;_ = l.Close()
	c:=testConfig();c.Listen=net.JoinHostPort("127.0.0.1",strconv.Itoa(port));s:=Service{Config:c}
	r,err:=s.Start();if err!=nil{t.Fatal(err)};defer r.Listener.Close()
	if !r.Listener.Addr().(*net.TCPAddr).IP.IsLoopback(){t.Fatal("listener escaped loopback")}
}

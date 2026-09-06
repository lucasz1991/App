package common

import (
	"flag"
	"path/filepath"
	"testing"

	"github.com/urfave/cli/v2"
	"railtime.local/openuem-extension/server"
)

func TestRailTimeNativeStartupRequiresProtectedFileOrCompleteLegacyInputs(t *testing.T) {
	c := cli.NewContext(nil, flag.NewFlagSet("synthetic", flag.ContinueOnError), nil)
	w := Worker{}
	if err := w.CheckAgentStartupRequisites(c); err == nil {
		t.Fatal("empty native configuration accepted")
	}
}

func TestRailTimeNativeStartupTLSAndPaths(t *testing.T) {
	base := server.NativeConfig{DatabaseURL: "postgres://synthetic:fixture@127.0.0.1:55479/synthetic?sslmode=disable", NATSServers: "tls://127.0.0.1:4443", CACertificate: filepath.Join(t.TempDir(), "ca.crt"), ClientCertificate: filepath.Join(t.TempDir(), "worker.crt"), ClientPrivateKey: filepath.Join(t.TempDir(), "worker.key")}
	if err := validateNativeStartup(base); err != nil {
		t.Fatal(err)
	}
	for _, change := range []func(*server.NativeConfig){
		func(c *server.NativeConfig) {
			c.DatabaseURL = "postgres://synthetic:fixture@db.invalid/synthetic?sslmode=disable"
		},
		func(c *server.NativeConfig) { c.NATSServers = "nats://127.0.0.1:4222" },
		func(c *server.NativeConfig) { c.NATSServers = "tls://user:secret@broker.invalid:4433" },
		func(c *server.NativeConfig) { c.ClientPrivateKey = "relative.key" },
		func(c *server.NativeConfig) { c.DatabaseURL = "invalid" },
	} {
		c := base
		change(&c)
		if validateNativeStartup(c) == nil {
			t.Fatal("unsafe native config accepted")
		}
	}
	c := base
	c.DatabaseURL = "postgres://synthetic:fixture@db.invalid/synthetic?sslmode=verify-full"
	if err := validateNativeStartup(c); err != nil {
		t.Fatal("verified remote PostgreSQL config rejected", err)
	}
}

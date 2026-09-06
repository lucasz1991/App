package main

import (
	"bytes"
	"strings"
	"testing"
)

func TestHelpAndRejectedCLIFlagsNeverAccessPlatform(t *testing.T) {
	for _, args := range [][]string{nil, {"--help"}, {"-h"}, {"help"}} {
		var out bytes.Buffer
		if err := run(args, nil, &out); err != nil || !strings.Contains(out.String(), "No environment variables") {
			t.Fatal("help")
		}
	}
	for _, args := range [][]string{{"unknown"}, {"prepare", "--force"}, {"start", "--force"}, {"status", "--repair"}, {"install", "--bundle", "file"}, {"install", "--env", "secret"}} {
		var out bytes.Buffer
		if err := run(args, nil, &out); err == nil {
			t.Fatal("unsupported flag accepted")
		}
	}
}

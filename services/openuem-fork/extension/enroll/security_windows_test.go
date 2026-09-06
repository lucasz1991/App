//go:build windows

package enroll

import (
	"os"
	"path/filepath"
	"testing"

	"golang.org/x/sys/windows"
)

func TestPrivateACLPolicyWithSyntheticDescriptors(t *testing.T) {
	// In-memory security descriptors only: no SetSecurityInfo or file writes.
	for _, tc := range []struct {
		sddl string
		good bool
	}{
		{"O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)", true},
		{"O:SYG:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)", true},
		{"O:BAG:BAD:(A;;FA;;;SY)(A;;FA;;;BA)", false},
		{"O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;FR;;;BU)", false},
		{"O:BAG:BAD:P(A;;FA;;;SY)(A;;FR;;;BA)", false},
		{"O:BAG:BAD:P(A;IO;FA;;;SY)(A;;FA;;;BA)", false},
		{"O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;SY)", false},
		{"O:BAG:BAD:P", false},
	} {
		sd, err := windows.SecurityDescriptorFromString(tc.sddl)
		if err != nil {
			t.Fatal(err)
		}
		if (inspectACL(sd) == nil) != tc.good {
			t.Fatal("ACL policy", tc.sddl)
		}
	}
}

func TestWindowsPathRejectsRemoteRelativeADSAndTraversal(t *testing.T) {
	for _, path := range []string{`relative\file`, `\\server\share\file`, `C:\safe\..\other`, `C:\safe\file:stream`, `C:\safe\bad` + "\n", `C:\safe\trailing.`, `C:\safe\NUL`, `C:\safe\COM1.txt`} {
		if _, err := pathParts(path); err == nil {
			t.Fatal("unsafe path", path)
		}
	}
	if _, err := pathParts(`C:\ProgramData\OpenUEM`); err != nil {
		t.Fatal(err)
	}
}

func TestActualWindowsReadRejectsHardLinksWithoutElevatedActions(t *testing.T) {
	dir := t.TempDir()
	original := filepath.Join(dir, "public-bundle.json")
	if err := os.WriteFile(original, []byte("synthetic public test input"), 0600); err != nil {
		t.Fatal(err)
	}
	p := windowsPlatform{}
	if _, err := p.Read(original, 128, false); err != nil {
		t.Fatal("ordinary public file", err)
	}
	if err := os.Link(original, filepath.Join(dir, "linked.json")); err != nil {
		t.Fatal(err)
	}
	if _, err := p.Read(original, 128, false); err == nil {
		t.Fatal("hardlinked source accepted")
	}
}

func TestAdministratorCheckUsesEffectiveTokenWithoutRequestingElevation(t *testing.T) {
	admin, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		t.Fatal(err)
	}
	member, err := windows.Token(0).IsMember(admin)
	if err != nil {
		t.Fatal(err)
	}
	expected := member && windows.GetCurrentProcessToken().IsElevated()
	if (windowsPlatform{}.RequireAdmin() == nil) != expected {
		t.Fatal("elevation check does not match effective token")
	}
}

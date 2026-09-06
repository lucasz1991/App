//go:build windows

package agentexec

import (
	"golang.org/x/sys/windows"
	"os"
	"path/filepath"
	"testing"
)

func TestPermissiveACLRejected(t *testing.T) {
	root := filepath.Join(t.TempDir(), "private")
	if err := makePrivateDirectory(root); err != nil {
		t.Fatal(err)
	}
	sd, err := windows.SecurityDescriptorFromString("D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FA;;;WD)")
	if err != nil {
		t.Fatal(err)
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		t.Fatal(err)
	}
	if err = windows.SetNamedSecurityInfo(root, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, dacl, nil); err != nil {
		t.Fatal(err)
	}
	if CheckPrivatePath(root, true) == nil {
		t.Fatal("Everyone full access accepted")
	}
}

func TestSymlinkConfigurationPathRejected(t *testing.T) {
	root := filepath.Join(t.TempDir(), "private")
	if err := makePrivateDirectory(root); err != nil {
		t.Fatal(err)
	}
	target := filepath.Join(root, "target")
	if err := os.WriteFile(target, []byte("not a real key"), 0600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(root, "link")
	if err := os.Symlink(target, link); err != nil {
		t.Skip("OS does not permit synthetic symlink creation")
	}
	if CheckPrivatePath(link, false) == nil {
		t.Fatal("symlink accepted")
	}
}

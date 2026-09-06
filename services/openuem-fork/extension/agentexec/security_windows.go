//go:build windows

package agentexec

import (
	"os"
	"path/filepath"
	"unsafe"

	"golang.org/x/sys/windows"
)

func ConfigRoot() (string, error) {
	p, err := windows.KnownFolderPath(windows.FOLDERID_ProgramData, 0)
	if err != nil {
		return "", ErrJournal
	}
	return filepath.Join(p, "OpenUEM", "RailTimeExecution"), nil
}

func allowedSIDs() (map[string]bool, error) {
	u, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return nil, ErrJournal
	}
	return map[string]bool{"S-1-5-18": true, "S-1-5-32-544": true, u.User.Sid.String(): true}, nil
}

// CheckPrivatePath rejects links, permissive ownership and every grant to a
// principal other than SYSTEM, Administrators or the current service identity.
func CheckPrivatePath(path string, directory bool) error {
	if !filepath.IsAbs(path) {
		return ErrJournal
	}
	for p := filepath.Clean(path); ; p = filepath.Dir(p) {
		name, err := windows.UTF16PtrFromString(p)
		if err != nil {
			return ErrJournal
		}
		attr, err := windows.GetFileAttributes(name)
		if err != nil || attr&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
			return ErrJournal
		}
		if filepath.Dir(p) == p {
			break
		}
	}
	info, err := os.Lstat(path)
	if err != nil || info.IsDir() != directory || (!directory && !info.Mode().IsRegular()) {
		return ErrJournal
	}
	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return ErrJournal
	}
	allowed, err := allowedSIDs()
	if err != nil {
		return err
	}
	owner, _, err := sd.Owner()
	if err != nil || owner == nil || !allowed[owner.String()] {
		return ErrJournal
	}
	acl, _, err := sd.DACL()
	if err != nil || acl == nil || acl.AceCount == 0 {
		return ErrJournal
	}
	for i := uint32(0); i < uint32(acl.AceCount); i++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if windows.GetAce(acl, i, &ace) != nil || ace == nil || ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE {
			return ErrJournal
		}
		sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
		if !sid.IsValid() || !allowed[sid.String()] {
			return ErrJournal
		}
	}
	return nil
}

func makePrivateDirectory(path string) error {
	u, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return ErrJournal
	}
	sd, err := windows.SecurityDescriptorFromString("D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FA;;;" + u.User.Sid.String() + ")")
	if err != nil {
		return ErrJournal
	}
	sa := windows.SecurityAttributes{SecurityDescriptor: sd}
	sa.Length = uint32(unsafe.Sizeof(sa))
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return ErrJournal
	}
	if err = windows.CreateDirectory(p, &sa); err != nil {
		return ErrJournal
	}
	return CheckPrivatePath(path, true)
}

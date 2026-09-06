//go:build windows

package enroll

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

type windowsPlatform struct{}

// FILE_ALL_ACCESS from WinNT.h (not exported by the pinned x/sys version).
const fileAllAccess = 0x001f01ff

func NewPlatform() Platform { return windowsPlatform{} }

func (windowsPlatform) Roots() (Roots, error) {
	d, err := windows.KnownFolderPath(windows.FOLDERID_ProgramData, 0)
	if err != nil {
		return Roots{}, ErrRefused
	}
	f, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, 0)
	if err != nil {
		return Roots{}, ErrRefused
	}
	for _, p := range []string{d, f} {
		if !filepath.IsAbs(p) || strings.HasPrefix(p, `\\`) || len(filepath.VolumeName(p)) != 2 || strings.ContainsAny(p, "\r\n\x00\"") {
			return Roots{}, ErrRefused
		}
		root, err := windows.UTF16PtrFromString(filepath.VolumeName(p) + `\`)
		if err != nil || windows.GetDriveType(root) != windows.DRIVE_FIXED {
			return Roots{}, ErrRefused
		}
	}
	return Roots{d, f}, nil
}

func (windowsPlatform) RequireAdmin() error {
	t := windows.GetCurrentProcessToken()
	admin, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		return ErrRefused
	}
	// A null token makes CheckTokenMembership inspect the effective token. Its
	// non-null argument must be an impersonation token, not our primary token.
	member, err := windows.Token(0).IsMember(admin)
	if err != nil || !member || !t.IsElevated() {
		return errors.New("administrator elevation required; reopen explicitly as administrator; no UAC bypass is attempted")
	}
	return nil
}

func ptr(s string) (*uint16, error) { return windows.UTF16PtrFromString(s) }

// Handle checks use FILE_FLAG_OPEN_REPARSE_POINT. No write/delete sharing is
// granted on input files; ancestor handles deny rename/delete for the operation.
func openChecked(path string, directory, private bool) (windows.Handle, error) {
	p, err := ptr(path)
	_, pathErr := pathParts(path)
	if err != nil || pathErr != nil {
		return 0, ErrRefused
	}
	flags := uint32(windows.FILE_FLAG_OPEN_REPARSE_POINT)
	share := uint32(windows.FILE_SHARE_READ)
	access := uint32(windows.GENERIC_READ | windows.READ_CONTROL)
	if directory {
		flags |= windows.FILE_FLAG_BACKUP_SEMANTICS
		share |= windows.FILE_SHARE_WRITE
		access = windows.FILE_READ_ATTRIBUTES | windows.READ_CONTROL
	}
	h, err := windows.CreateFile(p, access, share, nil, windows.OPEN_EXISTING, flags, 0)
	if err != nil {
		return 0, err
	}
	if err = inspectHandle(h, directory, private); err != nil {
		windows.CloseHandle(h)
		return 0, err
	}
	return h, nil
}

func inspectHandle(h windows.Handle, directory, private bool) error {
	var info windows.ByHandleFileInformation
	if windows.GetFileInformationByHandle(h, &info) != nil || info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 ||
		(info.FileAttributes&windows.FILE_ATTRIBUTE_DIRECTORY != 0) != directory || (!directory && info.NumberOfLinks != 1) {
		return ErrRefused
	}
	if !private {
		return nil
	}
	sd, err := windows.GetSecurityInfo(h, windows.SE_FILE_OBJECT, windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return ErrRefused
	}
	return inspectACL(sd)
}

func inspectACL(sd *windows.SECURITY_DESCRIPTOR) error {
	owner, _, err := sd.Owner()
	if err != nil || owner == nil || (owner.String() != "S-1-5-18" && owner.String() != "S-1-5-32-544") {
		return ErrRefused
	}
	control, _, err := sd.Control()
	if err != nil || control&windows.SE_DACL_PROTECTED == 0 {
		return ErrRefused
	}
	acl, _, err := sd.DACL()
	if err != nil || acl == nil || acl.AceCount != 2 {
		return ErrRefused
	}
	seen := map[string]bool{}
	for i := uint32(0); i < uint32(acl.AceCount); i++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if windows.GetAce(acl, i, &ace) != nil || ace == nil || ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE || ace.Header.AceFlags&windows.INHERIT_ONLY_ACE != 0 {
			return ErrRefused
		}
		sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
		if !sid.IsValid() {
			return ErrRefused
		}
		name := sid.String()
		if (name != "S-1-5-18" && name != "S-1-5-32-544") || seen[name] || (ace.Mask&fileAllAccess != fileAllAccess && ace.Mask&windows.GENERIC_ALL == 0) {
			return ErrRefused
		}
		seen[name] = true
	}
	return nil
}

func pathParts(path string) ([]string, error) {
	if !filepath.IsAbs(path) || strings.HasPrefix(path, `\\`) || len(filepath.VolumeName(path)) != 2 || filepath.Clean(path) != path || strings.ContainsAny(path, "\r\n\x00") {
		return nil, ErrRefused
	}
	rest := strings.TrimPrefix(path, filepath.VolumeName(path))
	if strings.Contains(rest, ":") {
		return nil, ErrRefused
	}
	for _, component := range strings.Split(strings.TrimLeft(rest, `\`), `\`) {
		if component == "" {
			continue
		}
		if strings.TrimRight(component, ". ") != component {
			return nil, ErrRefused
		}
		base := strings.ToUpper(strings.SplitN(component, ".", 2)[0])
		if base == "CON" || base == "PRN" || base == "AUX" || base == "NUL" || (len(base) == 4 && (strings.HasPrefix(base, "COM") || strings.HasPrefix(base, "LPT")) && base[3] >= '1' && base[3] <= '9') {
			return nil, ErrRefused
		}
	}
	var parts []string
	for p := path; ; p = filepath.Dir(p) {
		parts = append(parts, p)
		if filepath.Dir(p) == p {
			break
		}
	}
	for i, j := 0, len(parts)-1; i < j; i, j = i+1, j-1 {
		parts[i], parts[j] = parts[j], parts[i]
	}
	return parts, nil
}

func holdAncestors(path, privateBase string, allowMissing bool) (func(), error) {
	parts, err := pathParts(path)
	if err != nil {
		return nil, err
	}
	var held []windows.Handle
	closeAll := func() {
		for i := len(held) - 1; i >= 0; i-- {
			windows.CloseHandle(held[i])
		}
	}
	for _, part := range parts {
		private := privateBase != "" && (strings.EqualFold(part, privateBase) || strings.HasPrefix(strings.ToLower(part), strings.ToLower(privateBase)+`\`))
		h, e := openChecked(part, true, private)
		if e != nil {
			if allowMissing && (errors.Is(e, windows.ERROR_FILE_NOT_FOUND) || errors.Is(e, windows.ERROR_PATH_NOT_FOUND)) {
				return closeAll, nil
			}
			closeAll()
			return nil, ErrRefused
		}
		held = append(held, h)
	}
	return closeAll, nil
}

func (windowsPlatform) Hold(r Roots) (func(), error) {
	var closes []func()
	for _, path := range []string{r.Private(), filepath.Dir(r.INI())} {
		base := filepath.Join(r.ProgramData, "OpenUEM")
		if strings.HasPrefix(path, r.ProgramFiles+string(filepath.Separator)) {
			base = filepath.Join(r.ProgramFiles, "OpenUEM")
		}
		close, err := holdAncestors(path, base, true)
		if err != nil {
			for _, f := range closes {
				f()
			}
			return nil, err
		}
		closes = append(closes, close)
	}
	return func() {
		for _, f := range closes {
			f()
		}
	}, nil
}

func (windowsPlatform) Exists(path string) (bool, error) {
	p, err := ptr(path)
	if err != nil {
		return false, ErrRefused
	}
	attr, err := windows.GetFileAttributes(p)
	if errors.Is(err, windows.ERROR_FILE_NOT_FOUND) || errors.Is(err, windows.ERROR_PATH_NOT_FOUND) {
		return false, nil
	}
	if err != nil || attr&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		return false, ErrRefused
	}
	return true, nil
}

func security() (*windows.SecurityAttributes, error) {
	sd, err := windows.SecurityDescriptorFromString("O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)")
	if err != nil {
		return nil, err
	}
	sa := &windows.SecurityAttributes{SecurityDescriptor: sd}
	sa.Length = uint32(unsafe.Sizeof(*sa))
	return sa, nil
}

func (p windowsPlatform) EnsurePrivateParent(path string) error {
	exists, err := p.Exists(path)
	if err != nil {
		return err
	}
	if !exists {
		return p.MkdirNew(path)
	}
	h, err := openChecked(path, true, true)
	if err != nil {
		return ErrRefused
	}
	return windows.CloseHandle(h)
}

func (windowsPlatform) MkdirNew(path string) error {
	close, err := holdAncestors(filepath.Dir(path), "", false)
	if err != nil {
		return err
	}
	defer close()
	sa, err := security()
	if err != nil {
		return err
	}
	p, err := ptr(path)
	if err != nil {
		return err
	}
	if err = windows.CreateDirectory(p, sa); err != nil {
		return ErrRefused
	}
	h, err := openChecked(path, true, true)
	if err != nil {
		return err
	}
	return windows.CloseHandle(h)
}

func (windowsPlatform) WriteNew(path string, data []byte) error {
	close, err := holdAncestors(filepath.Dir(path), filepath.Dir(path), false)
	if err != nil {
		return err
	}
	defer close()
	sa, err := security()
	if err != nil {
		return err
	}
	p, err := ptr(path)
	if err != nil {
		return err
	}
	h, err := windows.CreateFile(p, windows.GENERIC_WRITE|windows.READ_CONTROL, 0, sa, windows.CREATE_NEW, windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return ErrRefused
	}
	f := os.NewFile(uintptr(h), path)
	defer f.Close()
	if err = inspectHandle(h, false, true); err != nil {
		return err
	}
	if n, e := f.Write(data); e != nil || n != len(data) {
		return ErrRefused
	}
	return f.Sync()
}

func (windowsPlatform) Read(path string, limit int64, private bool) ([]byte, error) {
	close, err := holdAncestors(filepath.Dir(path), "", false)
	if err != nil {
		return nil, err
	}
	defer close()
	if private {
		ph, e := openChecked(filepath.Dir(path), true, true)
		if e != nil {
			return nil, e
		}
		defer windows.CloseHandle(ph)
	}
	h, err := openChecked(path, false, private)
	if err != nil {
		return nil, ErrRefused
	}
	f := os.NewFile(uintptr(h), path)
	defer f.Close()
	b, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil || int64(len(b)) > limit {
		return nil, ErrRefused
	}
	return b, nil
}

func openService(access uint32) (*mgr.Service, error) {
	m, err := windows.OpenSCManager(nil, nil, windows.SC_MANAGER_CONNECT)
	if err != nil {
		return nil, err
	}
	defer windows.CloseServiceHandle(m)
	name, _ := ptr(ServiceName)
	h, err := windows.OpenService(m, name, access)
	if err != nil {
		return nil, err
	}
	return &mgr.Service{Name: ServiceName, Handle: h}, nil
}

func serviceState(s *mgr.Service) (ServiceState, error) {
	c, err := s.Config()
	if err != nil {
		return ServiceState{}, err
	}
	state, err := s.Query()
	if err != nil {
		return ServiceState{}, err
	}
	return ServiceState{true, state.State != svc.Stopped, c.StartType == mgr.StartDisabled, c.StartType == mgr.StartAutomatic, c.BinaryPathName, c.ServiceStartName == "LocalSystem", c.ServiceType == windows.SERVICE_WIN32_OWN_PROCESS}, nil
}

func (windowsPlatform) Service() (ServiceState, error) {
	s, err := openService(windows.SERVICE_QUERY_CONFIG | windows.SERVICE_QUERY_STATUS)
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return ServiceState{}, nil
	}
	if err != nil {
		return ServiceState{}, err
	}
	defer s.Close()
	return serviceState(s)
}

func (windowsPlatform) CreateDisabled(binary string) error {
	m, err := windows.OpenSCManager(nil, nil, windows.SC_MANAGER_CREATE_SERVICE)
	if err != nil {
		return err
	}
	defer windows.CloseServiceHandle(m)
	name, _ := ptr(ServiceName)
	display, _ := ptr("OpenUEM RailTime inventory agent")
	path, err := ptr(`"` + binary + `"`)
	if err != nil {
		return err
	}
	// No mgr.CreateService convenience cleanup: never delete a partial service.
	h, err := windows.CreateService(m, name, display, windows.SERVICE_QUERY_CONFIG|windows.SERVICE_QUERY_STATUS, windows.SERVICE_WIN32_OWN_PROCESS, windows.SERVICE_DISABLED, windows.SERVICE_ERROR_NORMAL, path, nil, nil, nil, nil, nil)
	if err != nil {
		return err
	}
	return windows.CloseServiceHandle(h)
}

func matchingHandle(binary string, access uint32) (*mgr.Service, error) {
	s, err := openService(access | windows.SERVICE_QUERY_CONFIG | windows.SERVICE_QUERY_STATUS)
	if err != nil {
		return nil, err
	}
	state, err := serviceState(s)
	if err != nil || state.BinaryPath != `"`+binary+`"` || !state.LocalSystem || !state.OwnProcess {
		s.Close()
		return nil, ErrRefused
	}
	return s, nil
}

func setStart(s *mgr.Service, kind uint32) error {
	return windows.ChangeServiceConfig(s.Handle, windows.SERVICE_NO_CHANGE, kind, windows.SERVICE_NO_CHANGE, nil, nil, nil, nil, nil, nil, nil)
}

func (windowsPlatform) StartAutomatic(binary string) error {
	s, err := matchingHandle(binary, windows.SERVICE_CHANGE_CONFIG|windows.SERVICE_START)
	if err != nil {
		return err
	}
	defer s.Close()
	state, err := s.Query()
	if err != nil || state.State != svc.Stopped {
		return ErrRefused
	}
	if err = setStart(s, windows.SERVICE_AUTO_START); err != nil {
		return err
	}
	if err = s.Start(); err != nil {
		return err
	}
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		state, err = s.Query()
		if err != nil {
			return err
		}
		if state.State == svc.Running {
			return nil
		}
		if state.State == svc.Stopped {
			return ErrRefused
		}
		time.Sleep(200 * time.Millisecond)
	}
	return errors.New("service did not enter running state in 30 seconds")
}

func (windowsPlatform) DisableStop(binary string) error {
	s, err := matchingHandle(binary, windows.SERVICE_CHANGE_CONFIG|windows.SERVICE_STOP)
	if err != nil {
		return err
	}
	defer s.Close()
	disableErr := setStart(s, windows.SERVICE_DISABLED)
	state, err := s.Query()
	if err != nil {
		return errors.Join(disableErr, err)
	}
	if state.State == svc.Stopped {
		return disableErr
	}
	_, stopErr := s.Control(svc.Stop)
	if stopErr != nil && !errors.Is(stopErr, windows.ERROR_SERVICE_NOT_ACTIVE) {
		return errors.Join(disableErr, stopErr)
	}
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		state, err = s.Query()
		if err != nil {
			return errors.Join(disableErr, err)
		}
		if state.State == svc.Stopped {
			return disableErr
		}
		time.Sleep(200 * time.Millisecond)
	}
	return errors.Join(disableErr, fmt.Errorf("service stop not confirmed; manual administrator intervention required"))
}

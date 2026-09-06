//go:build !windows

package agentexec

import (
	"os"
	"path/filepath"
)

func ConfigRoot() (string, error) { return "", ErrJournal }

func CheckPrivatePath(path string, directory bool) error {
	if !filepath.IsAbs(path) {
		return ErrJournal
	}
	for p := filepath.Clean(path); ; p = filepath.Dir(p) {
		i, err := os.Lstat(p)
		if err != nil || i.Mode()&os.ModeSymlink != 0 {
			return ErrJournal
		}
		if filepath.Dir(p) == p {
			break
		}
	}
	i, err := os.Lstat(path)
	if err != nil || i.IsDir() != directory || i.Mode().Perm()&0077 != 0 || (!directory && !i.Mode().IsRegular()) {
		return ErrJournal
	}
	return nil
}

func makePrivateDirectory(path string) error { return os.Mkdir(path, 0700) }

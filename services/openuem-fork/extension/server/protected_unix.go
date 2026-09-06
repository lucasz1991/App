//go:build !windows

package server

import (
	"errors"
	"os"
	"syscall"
)

func protectedOwnership(info os.FileInfo, leaf bool) error {
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || (int(stat.Uid) != os.Geteuid() && stat.Uid != 0) {
		return errors.New("protected path owner is not trusted")
	}
	if leaf && info.Mode().Perm()&0077 != 0 {
		return errors.New("protected file requires owner-only permissions")
	}
	if !leaf && info.Mode().Perm()&0022 != 0 {
		return errors.New("protected parent directory must not be writable by others")
	}
	return nil
}

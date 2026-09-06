//go:build windows

package server

import (
	"errors"
	"os"
)

func protectedOwnership(_ os.FileInfo, _ bool) error {
	return errors.New("native worker run API configuration requires Linux permission verification")
}

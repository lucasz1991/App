//go:build !windows

package enroll

import "errors"

type unsupported struct{}

var errWindows = errors.New("device enrollment is available only on Windows; no changes performed")

func NewPlatform() Platform                                  { return unsupported{} }
func (unsupported) Roots() (Roots, error)                    { return Roots{}, errWindows }
func (unsupported) RequireAdmin() error                      { return errWindows }
func (unsupported) Hold(Roots) (func(), error)               { return nil, errWindows }
func (unsupported) Exists(string) (bool, error)              { return false, errWindows }
func (unsupported) EnsurePrivateParent(string) error         { return errWindows }
func (unsupported) MkdirNew(string) error                    { return errWindows }
func (unsupported) WriteNew(string, []byte) error            { return errWindows }
func (unsupported) Read(string, int64, bool) ([]byte, error) { return nil, errWindows }
func (unsupported) Service() (ServiceState, error)           { return ServiceState{}, errWindows }
func (unsupported) CreateDisabled(string) error              { return errWindows }
func (unsupported) StartAutomatic(string) error              { return errWindows }
func (unsupported) DisableStop(string) error                 { return errWindows }

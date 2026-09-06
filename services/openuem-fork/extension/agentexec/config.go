package agentexec

import (
	"crypto/hmac"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"

	"railtime.local/openuem-extension/protocol"
)

var ErrDisabled = errors.New("correlated execution disabled")

type Config struct {
	Enabled  bool   `json:"enabled"`
	AgentID  string `json:"agent_id"`
	TenantID int    `json:"tenant_id"`
	SiteID   int    `json:"site_id"`
	KeyID    string `json:"key_id"`
	LedgerID string `json:"ledger_id"`
	Key      []byte `json:"-"`
}

func (c Config) SameEnrollment(other Config) bool {
	return c.Enabled && other.Enabled && c.AgentID == other.AgentID && c.TenantID == other.TenantID && c.SiteID == other.SiteID && c.KeyID == other.KeyID && c.LedgerID == other.LedgerID && hmac.Equal(c.Key, other.Key)
}

// LoadConfig reads only fixed filenames below an already protected enrollment
// directory. Missing configuration disables the feature; missing journal/key
// after enrollment is never repaired or replaced implicitly.
func LoadConfig(root string) (Config, error) {
	path := filepath.Join(root, "execution.json")
	if _, err := os.Lstat(path); os.IsNotExist(err) {
		return Config{}, ErrDisabled
	}
	if CheckPrivatePath(root, true) != nil {
		return Config{}, ErrJournal
	}
	b, err := privateRead(path, 8192)
	if err != nil {
		return Config{}, err
	}
	var c Config
	if protocol.Decode(b, &c) != nil {
		return Config{}, ErrJournal
	}
	if !c.Enabled {
		return Config{}, ErrDisabled
	}
	if _, err := protocol.CommandSubject(c.AgentID); err != nil || c.TenantID <= 0 || c.SiteID <= 0 || c.LedgerID == "" || len(c.LedgerID) > 128 {
		return Config{}, ErrJournal
	}
	b, err = privateRead(filepath.Join(root, "agent-hmac.key"), 1024)
	if err != nil {
		return Config{}, err
	}
	c.Key, err = hex.DecodeString(strings.TrimSpace(string(b)))
	if err != nil || len(c.Key) != 32 {
		return Config{}, ErrJournal
	}
	if _, err := protocol.Sign(protocol.AckContext, c.KeyID, c.Key, protocol.Ack{}); err != nil {
		return Config{}, ErrJournal
	}
	return c, nil
}

func privateRead(path string, max int64) ([]byte, error) {
	if CheckPrivatePath(path, false) != nil {
		return nil, ErrJournal
	}
	before, err := os.Lstat(path)
	if err != nil {
		return nil, ErrJournal
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, ErrJournal
	}
	defer f.Close()
	opened, err := f.Stat()
	if err != nil || !os.SameFile(before, opened) || opened.Size() > max {
		return nil, ErrJournal
	}
	b, err := io.ReadAll(io.LimitReader(f, max+1))
	if err != nil || int64(len(b)) > max || CheckPrivatePath(path, false) != nil {
		return nil, ErrJournal
	}
	after, err := os.Lstat(path)
	if err != nil || !os.SameFile(opened, after) {
		return nil, ErrJournal
	}
	return b, nil
}

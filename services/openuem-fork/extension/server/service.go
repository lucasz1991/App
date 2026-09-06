package server

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"net/http"
	"time"

	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/store"
)

var ErrForbidden = errors.New("scope denied")
var ErrProfile = errors.New("unsupported or non-dedicated profile")

type Snapshot struct {
	YAML                                   []byte
	ExpectedTasks, Exclusions, Deployments []string
}
type Materializer interface {
	Authorize(context.Context, Principal, protocol.RunRequest) error
	Snapshot(context.Context, Principal, protocol.RunRequest) (Snapshot, error)
}
type Transport interface {
	Connected() bool
	Request(context.Context, string, []byte) ([]byte, error)
}
type Service struct {
	Config    Config
	Ledger    store.Ledger
	Profiles  Materializer
	Transport Transport
	Now       func() time.Time
}

func (s *Service) now() time.Time {
	if s.Now != nil {
		return s.Now().UTC()
	}
	return time.Now().UTC()
}

func (s *Service) Authenticate(token string) (Principal, bool) {
	if len(token) < 32 || len(token) > 512 {
		return Principal{}, false
	}
	hash := sha256.Sum256([]byte(token))
	encoded := hex.EncodeToString(hash[:])
	var matched Principal
	ok := false
	for _, p := range s.Config.Principals {
		if subtle.ConstantTimeCompare([]byte(encoded), []byte(p.BearerSHA256)) == 1 {
			matched = p
			ok = true
		}
	}
	return matched, ok
}

func (s *Service) Accept(ctx context.Context, p Principal, r protocol.RunRequest) (protocol.RunView, int, error) {
	if err := r.Validate(); err != nil {
		return protocol.RunView{}, http.StatusUnprocessableEntity, err
	}
	if !p.Allows(r.AgentID, r.ProfileID) {
		return protocol.RunView{}, http.StatusForbidden, ErrForbidden
	}
	// Read the immutable prior receipt before materializing a potentially changed
	// profile. A retry is never turned into a different native command.
	existing, err := s.Ledger.FindRequest(ctx, p.ID, r.CorrelationID)
	if err == nil {
		if existing.Request != r || existing.Command.TenantID != p.TenantID || existing.Command.SiteID != p.SiteID {
			return protocol.RunView{}, http.StatusConflict, store.ErrConflict
		}
		if err = s.Profiles.Authorize(ctx, p, r); err != nil {
			return protocol.RunView{}, http.StatusForbidden, err
		}
		return existing.View(), http.StatusOK, nil
	}
	if !errors.Is(err, store.ErrNotFound) {
		return protocol.RunView{}, http.StatusServiceUnavailable, err
	}
	if err = s.Profiles.Authorize(ctx, p, r); err != nil {
		return protocol.RunView{}, http.StatusForbidden, err
	}
	snapshot, err := s.Profiles.Snapshot(ctx, p, r)
	if err != nil {
		return protocol.RunView{}, http.StatusUnprocessableEntity, err
	}
	key, ok := s.Config.DeviceKey(r.AgentID)
	if !ok {
		return protocol.RunView{}, http.StatusForbidden, ErrForbidden
	}
	keyBytes, err := key.Bytes()
	if err != nil {
		return protocol.RunView{}, http.StatusServiceUnavailable, err
	}
	id, err := protocol.NewID()
	if err != nil {
		return protocol.RunView{}, http.StatusServiceUnavailable, err
	}
	now := s.now()
	command := protocol.Command{Version: protocol.Version, RunID: id, CommandID: r.CommandID, CorrelationID: r.CorrelationID, AgentID: r.AgentID, ProfileID: r.ProfileID, TenantID: p.TenantID, SiteID: p.SiteID, IssuedAt: now, ExpiresAt: now.Add(time.Duration(s.Config.CommandTTLSeconds) * time.Second), Snapshot: snapshot.YAML, ExpectedTasks: snapshot.ExpectedTasks, Exclusions: snapshot.Exclusions, Deployments: snapshot.Deployments}
	command.PayloadSHA256 = command.Fingerprint()
	if err = command.Validate(); err != nil {
		return protocol.RunView{}, http.StatusUnprocessableEntity, err
	}
	wire, err := protocol.Sign(protocol.CommandContext, key.KeyID, keyBytes, command)
	if err != nil {
		return protocol.RunView{}, http.StatusServiceUnavailable, err
	}
	record, created, err := s.Ledger.Accept(ctx, store.Record{Principal: p.ID, Request: r, Command: command, KeyID: key.KeyID, Wire: wire})
	if err != nil {
		status := http.StatusServiceUnavailable
		if errors.Is(err, store.ErrConflict) {
			status = http.StatusConflict
		}
		return protocol.RunView{}, status, err
	}
	if !created {
		return record.View(), http.StatusOK, nil
	}
	return record.View(), http.StatusAccepted, nil
}

func (s *Service) Get(ctx context.Context, p Principal, id string) (protocol.RunView, error) {
	r, err := s.Ledger.Get(ctx, p.ID, id)
	if err != nil {
		return protocol.RunView{}, err
	}
	if !p.Allows(r.Command.AgentID, r.Command.ProfileID) || r.Command.TenantID != p.TenantID || r.Command.SiteID != p.SiteID {
		return protocol.RunView{}, ErrForbidden
	}
	if err = s.Profiles.Authorize(ctx, p, r.Request); err != nil {
		return protocol.RunView{}, ErrForbidden
	}
	return r.View(), nil
}

// DispatchOne claims one committed outbox row. A transport timeout changes only
// the next retry time, never the run ID or the recorded execution outcome.
func (s *Service) DispatchOne(ctx context.Context) error {
	if !s.Transport.Connected() {
		return errors.New("broker unavailable")
	}
	d, err := s.Ledger.Claim(ctx, s.now(), 45*time.Second)
	if err != nil {
		return err
	}
	reschedule := func() error {
		delay := time.Duration(min(d.Attempts, 12)) * 5 * time.Second
		return s.Ledger.Reschedule(ctx, d, s.now().Add(delay))
	}
	var principal Principal
	found := false
	for _, p := range s.Config.Principals {
		if p.ID == d.Record.Principal {
			principal = p
			found = true
			break
		}
	}
	c := d.Record.Command
	if !found || !principal.Allows(c.AgentID, c.ProfileID) || principal.TenantID != c.TenantID || principal.SiteID != c.SiteID {
		_ = reschedule()
		return ErrForbidden
	}
	if err = s.Profiles.Authorize(ctx, principal, d.Record.Request); err != nil {
		_ = reschedule()
		return err
	}
	key, ok := s.Config.DeviceKey(c.AgentID)
	if !ok || key.KeyID != d.Record.KeyID {
		_ = reschedule()
		return ErrForbidden
	}
	keyBytes, err := key.Bytes()
	if err != nil {
		_ = reschedule()
		return err
	}
	// Validate stored signed bytes against the immutable command before sending.
	var persisted protocol.Command
	if err = protocol.Verify(protocol.CommandContext, key.KeyID, keyBytes, d.Record.Wire, &persisted); err != nil || persisted.PayloadSHA256 != c.PayloadSHA256 {
		_ = reschedule()
		return store.ErrConflict
	}
	subject, err := protocol.CommandSubject(c.AgentID)
	if err != nil {
		_ = reschedule()
		return err
	}
	requestCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	wire, err := s.Transport.Request(requestCtx, subject, d.Record.Wire)
	if err != nil {
		if retryErr := reschedule(); retryErr != nil {
			return retryErr
		}
		return err
	}
	var ack protocol.Ack
	if err = protocol.Verify(protocol.AckContext, key.KeyID, keyBytes, wire, &ack); err != nil {
		_ = reschedule()
		return err
	}
	if ack.Version != protocol.Version || ack.RunID != c.RunID || ack.PayloadSHA256 != c.PayloadSHA256 {
		_ = reschedule()
		return store.ErrConflict
	}
	if ack.EventID != "" && (protocol.RunRequest{CommandID: ack.EventID, CorrelationID: ack.EventID, AgentID: "validation", ProfileID: 1}).Validate() != nil {
		_ = reschedule()
		return store.ErrConflict
	}
	// A terminal duplicate can return its stable EventID, but even that signed
	// acknowledgment proves acceptance only. ReceiveResult owns terminal writes.
	if ack.Status != "accepted" {
		return reschedule()
	}
	return s.Ledger.Accepted(ctx, d)
}

// ReceiveResult is called by the native subscriber with an exact configured
// device subject. The signed payload must agree; a JSON AgentID is not authority.
func (s *Service) ReceiveResult(ctx context.Context, subjectAgent string, wire []byte) ([]byte, error) {
	key, ok := s.Config.DeviceKey(subjectAgent)
	if !ok {
		return nil, ErrForbidden
	}
	secret, err := key.Bytes()
	if err != nil {
		return nil, err
	}
	var result protocol.Result
	if err = protocol.Verify(protocol.ResultContext, key.KeyID, secret, wire, &result); err != nil {
		return nil, err
	}
	if result.AgentID != subjectAgent {
		return nil, ErrForbidden
	}
	record, err := s.Ledger.GetInternal(ctx, result.RunID)
	if err != nil {
		return nil, err
	}
	if record.KeyID != key.KeyID {
		return nil, ErrForbidden
	}
	if err = result.ValidateFor(record.Command); err != nil {
		return nil, err
	}
	if _, err = s.Ledger.Complete(ctx, key.KeyID, result); err != nil {
		return nil, err
	}
	// Only a committed immutable result receives a signed positive receipt.
	return protocol.Sign(protocol.AckContext, key.KeyID, secret, protocol.Ack{Version: protocol.Version, RunID: result.RunID, PayloadSHA256: result.PayloadSHA256, EventID: result.EventID, Status: "persisted"})
}

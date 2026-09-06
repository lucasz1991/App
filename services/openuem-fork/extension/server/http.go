package server

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"strings"
	"time"

	"railtime.local/openuem-extension/protocol"
	"railtime.local/openuem-extension/store"
)

type Health struct {
	Protocol         string   `json:"protocol"`
	Ready            bool     `json:"ready"`
	StorageReady     bool     `json:"storage_ready"`
	BrokerReady      bool     `json:"broker_ready"`
	ProvisioningOnly bool     `json:"provisioning_only"`
	Capabilities     []string `json:"capabilities"`
}

func (s *Service) Handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		// Never accept ambient console cookies as machine authentication.
		authorization := r.Header.Values("Authorization")
		if len(authorization) != 1 || !strings.HasPrefix(authorization[0], "Bearer ") {
			writeError(w, 401, "unauthorized")
			return
		}
		p, ok := s.Authenticate(strings.TrimPrefix(authorization[0], "Bearer "))
		if !ok {
			writeError(w, 401, "unauthorized")
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
		defer cancel()
		switch {
		case r.URL.Path == "/railtime/v1/health" && r.Method == http.MethodGet:
			h := Health{Protocol: protocol.Version, StorageReady: s.Ledger.Health(ctx) == nil, BrokerReady: s.Transport.Connected(), ProvisioningOnly: s.Config.ProvisioningOnly, Capabilities: []string{}}
			if !h.ProvisioningOnly {
				h.Capabilities = []string{"profile_runs_v1"}
			}
			h.Ready = h.StorageReady && h.BrokerReady && !h.ProvisioningOnly
			status := 200
			if !h.Ready {
				status = 503
			}
			writeJSON(w, status, h)
		case r.URL.Path == "/railtime/v1/runs" && r.Method == http.MethodPost:
			if s.Config.ProvisioningOnly {
				writeError(w, 503, "provisioning_only")
				return
			}
			if r.Header.Get("Content-Type") != "application/json" {
				writeError(w, 415, "json_required")
				return
			}
			b, err := io.ReadAll(io.LimitReader(r.Body, 4097))
			if err != nil || len(b) > 4096 {
				writeError(w, 413, "request_too_large")
				return
			}
			var request protocol.RunRequest
			if err = protocol.Decode(b, &request); err != nil {
				writeError(w, 422, "invalid_request")
				return
			}
			view, status, err := s.Accept(ctx, p, request)
			if err != nil {
				code := "service_unavailable"
				switch status {
				case 403:
					code = "scope_denied"
				case 409:
					code = "idempotency_conflict"
				case 422:
					code = "invalid_or_non_dedicated_profile"
				}
				writeError(w, status, code)
				return
			}
			writeJSON(w, status, view)
		case strings.HasPrefix(r.URL.Path, "/railtime/v1/runs/") && r.Method == http.MethodGet:
			id := strings.TrimPrefix(r.URL.Path, "/railtime/v1/runs/")
			if (protocol.RunRequest{CommandID: id, CorrelationID: id, AgentID: "validation", ProfileID: 1}).Validate() != nil {
				writeError(w, 404, "run_not_found")
				return
			}
			view, err := s.Get(ctx, p, id)
			if err != nil {
				if errors.Is(err, store.ErrNotFound) || errors.Is(err, ErrForbidden) {
					writeError(w, 404, "run_not_found")
				} else {
					writeError(w, 503, "service_unavailable")
				}
				return
			}
			writeJSON(w, 200, view)
		default:
			writeError(w, 404, "not_found")
		}
	})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
func writeError(w http.ResponseWriter, status int, code string) {
	writeJSON(w, status, map[string]string{"error": code})
}

type Runtime struct {
	HTTP     *http.Server
	Listener net.Listener
}

func (s *Service) Start() (*Runtime, error) {
	if err := s.Config.Validate(); err != nil {
		return nil, err
	}
	var tlsConfig *tls.Config
	if s.Config.TLSCertificate != "" {
		if err := ProtectedFile(s.Config.TLSPrivateKey); err != nil {
			return nil, err
		}
		pair, err := tls.LoadX509KeyPair(s.Config.TLSCertificate, s.Config.TLSPrivateKey)
		if err != nil {
			return nil, errors.New("run API TLS material unavailable")
		}
		tlsConfig = &tls.Config{MinVersion: tls.VersionTLS12, Certificates: []tls.Certificate{pair}}
	}
	l, err := net.Listen("tcp", s.Config.Listen)
	if err != nil {
		return nil, errors.New("run API loopback listener unavailable")
	}
	// Plain HTTP is allowed only on the exact loopback bind validated above.
	// Public access requires a separately secured TLS reverse proxy.
	if tlsConfig != nil {
		l = tls.NewListener(l, tlsConfig)
	}
	httpServer := &http.Server{Handler: s.Handler(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 20 * time.Second, WriteTimeout: 20 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 8192}
	return &Runtime{HTTP: httpServer, Listener: l}, nil
}

func (r *Runtime) Serve() error                    { return r.HTTP.Serve(r.Listener) }
func (r *Runtime) Close(ctx context.Context) error { return r.HTTP.Shutdown(ctx) }

package agent

import (
	"crypto/x509"
	"errors"
	"testing"
)

func TestDisabledSFTPDoesNotReadCertificate(t *testing.T) {
	cert, err := sftpCertificate(Config{SFTPDisabled: true, SFTPCert: "not-provisioned"}, func(string) (*x509.Certificate, error) {
		t.Fatal("disabled SFTP attempted to read a certificate")
		return nil, errors.New("missing")
	})
	if err != nil || cert != nil {
		t.Fatal("disabled SFTP retained a certificate or failed", err)
	}
}

func TestEnabledSFTPRequiresCertificate(t *testing.T) {
	for _, name := range []string{"missing", "invalid", "empty-reader-result"} {
		t.Run(name, func(t *testing.T) {
			called := false
			cert, err := sftpCertificate(Config{SFTPDisabled: false, SFTPCert: name}, func(path string) (*x509.Certificate, error) {
				called = true
				if path != name {
					t.Fatal("wrong certificate path")
				}
				if name == "empty-reader-result" {
					return nil, nil
				}
				return nil, errors.New(name)
			})
			if !called || err == nil || cert != nil {
				t.Fatal("enabled SFTP accepted missing or invalid certificate")
			}
		})
	}
}

func TestSFTPCertificateReloadTransitions(t *testing.T) {
	fixture := &x509.Certificate{}
	read := func(string) (*x509.Certificate, error) { return fixture, nil }
	cert, err := sftpCertificate(Config{SFTPCert: "synthetic"}, read)
	if err != nil || cert != fixture {
		t.Fatal("enabled SFTP rejected the parsed certificate", err)
	}
	cert, err = sftpCertificate(Config{SFTPDisabled: true, SFTPCert: "synthetic"}, read)
	if err != nil || cert != nil {
		t.Fatal("disable retained the previous certificate", err)
	}
	cert, err = sftpCertificate(Config{SFTPCert: "missing-after-reload"}, func(string) (*x509.Certificate, error) {
		return nil, errors.New("missing")
	})
	if err == nil || cert != nil {
		t.Fatal("re-enable retained a stale certificate after load failure")
	}
}

//go:build windows

package main

import (
	"log"
	"os"
	"runtime"

	"github.com/open-uem/openuem-agent/internal/agent"
	"github.com/open-uem/openuem-agent/internal/logger"
	"golang.org/x/sys/windows/svc"
)

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--railtime-init-ledger" {
		if agent.InitializeRailTimeLedger() != nil {
			log.Print("RailTime ledger initialization refused; check protected enrollment and existing state")
			os.Exit(1)
		}
		log.Print("RailTime ledger initialized; no service or device task started")
		return
	}

	// the agent will use two CPUs at maximum
	runtime.GOMAXPROCS(2)

	// Instantiate logger
	l := logger.New()

	// Instantiate service
	s := NewService(l)

	// Run service
	err := svc.Run("openuem-agent", s)
	if err != nil {
		log.Fatalf("could not run service: %v", err)
	}
}

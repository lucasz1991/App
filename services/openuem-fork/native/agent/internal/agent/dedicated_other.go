//go:build !windows

package agent

// Dedicated inventory enrollment is explicitly Windows-only for this pilot.
func readDedicatedConfig(string) (Config, error) { return Config{}, errDedicatedConfig }
func (a *Agent) dedicatedTick()                  {}
func (a *Agent) stopDedicated()                  {}

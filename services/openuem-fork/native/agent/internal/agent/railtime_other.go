//go:build !windows

package agent

type railTimeRuntime struct{}

func (a *Agent) subscribeRailTime() {}
func (a *Agent) stopRailTime()      {}

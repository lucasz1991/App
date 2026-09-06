package commands

import (
	"context"
	"encoding/json"
	"time"

	"github.com/open-uem/openuem-worker/internal/common"
	"github.com/urfave/cli/v2"
)

func Bootstrap() *cli.Command {
	return &cli.Command{
		Name:  "bootstrap",
		Usage: "Initialize only an empty dedicated native PostgreSQL database; creates no devices, profiles, users or credentials",
		Flags: []cli.Flag{&cli.StringFlag{Name: "config", Required: true, Usage: "absolute owner-protected bootstrap JSON file"}},
		Action: func(c *cli.Context) error {
			config, err := common.LoadBootstrapConfig(c.String("config"))
			if err != nil {
				return err
			}
			ctx, cancel := context.WithTimeout(c.Context, 90*time.Second)
			defer cancel()
			result, err := common.BootstrapNativeDatabase(ctx, config)
			if err != nil {
				return err
			}
			return json.NewEncoder(c.App.Writer).Encode(result)
		},
	}
}

package commands

import (
	"context"
	"encoding/json"
	"time"

	"github.com/open-uem/openuem-worker/internal/common"
	"github.com/urfave/cli/v2"
)

func AdmitInventory() *cli.Command {
	return &cli.Command{
		Name:  "admit-inventory",
		Usage: "Admit exactly one configured device with a committed certificate-bound inventory report; creates no device, certificate or command",
		Flags: []cli.Flag{&cli.StringFlag{Name: "config", Required: true, Usage: "absolute owner-protected admission request JSON file"}},
		Action: func(c *cli.Context) error {
			config, err := common.LoadInventoryAdmissionConfig(c.String("config"))
			if err != nil {
				return err
			}
			ctx, cancel := context.WithTimeout(c.Context, 30*time.Second)
			defer cancel()
			result, err := common.AdmitInventoryDevice(ctx, config)
			if err != nil {
				return err
			}
			return json.NewEncoder(c.App.Writer).Encode(result)
		},
	}
}

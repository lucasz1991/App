package common

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"time"

	native "github.com/open-uem/nats"
	"github.com/open-uem/openuem-worker/internal/models"
	"railtime.local/openuem-extension/inventory"
)

// receiveInventory does all strict decoding and transport/native identity
// checks before the first database call; only a committed receipt is returned.
func receiveInventory(ctx context.Context, store models.InventoryStore, e inventory.Enrollment, subject string, wire []byte, now time.Time) ([]byte, error) {
	report, err := inventory.DecodeReport(wire, now)
	if err != nil {
		return nil, models.ErrInventoryRejected
	}
	var data native.AgentReport
	decoder := json.NewDecoder(bytes.NewReader(report.Report))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&data) != nil || decoder.Decode(new(any)) != io.EOF {
		return nil, models.ErrInventoryRejected
	}
	if len(data.Applications)+len(data.LogicalDisks)+len(data.PhysicalDisks)+len(data.Monitors)+len(data.MemorySlots)+len(data.Printers)+len(data.Shares)+len(data.NetworkAdapters)+len(data.LoggedOnUsers)+len(data.Updates) > 10000 {
		return nil, models.ErrInventoryRejected
	}
	if err = models.ValidateNativeReport(e, subject, report, &data, now); err != nil {
		return nil, err
	}
	receipt, err := store.Save(ctx, e, subject, report, &data, now)
	if err != nil {
		return nil, models.ErrInventoryRejected
	}
	return json.Marshal(receipt)
}

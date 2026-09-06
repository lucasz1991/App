package models

import (
	"context"
	"strings"

	"github.com/open-uem/ent"
	"github.com/open-uem/ent/agent"
	"github.com/open-uem/ent/antivirus"
	"github.com/open-uem/ent/app"
	"github.com/open-uem/ent/computer"
	"github.com/open-uem/ent/logicaldisk"
	"github.com/open-uem/ent/memoryslot"
	"github.com/open-uem/ent/monitor"
	"github.com/open-uem/ent/netbird"
	"github.com/open-uem/ent/networkadapter"
	"github.com/open-uem/ent/operatingsystem"
	"github.com/open-uem/ent/physicaldisk"
	"github.com/open-uem/ent/printer"
	"github.com/open-uem/ent/share"
	"github.com/open-uem/ent/systemupdate"
	"github.com/open-uem/ent/update"
	native "github.com/open-uem/nats"
)

// This client is bound to the outer inventory SQL transaction. Unlike legacy
// Save* methods these helpers neither commit/rollback nor hide a write error.
// No observation can trigger a release download, command or network lookup.
func saveInventoryObservations(ctx context.Context, c *ent.Client, d *native.AgentReport) error {
	if err := c.Computer.Create().SetManufacturer(d.Computer.Manufacturer).SetModel(d.Computer.Model).SetSerial(d.Computer.Serial).
		SetMemory(d.Computer.Memory).SetProcessor(d.Computer.Processor).SetProcessorArch(d.Computer.ProcessorArch).SetProcessorCores(d.Computer.ProcessorCores).
		SetOwnerID(d.AgentID).OnConflictColumns(computer.OwnerColumn).UpdateNewValues().Exec(ctx); err != nil {
		return err
	}
	if err := c.OperatingSystem.Create().SetType(d.OS).SetVersion(d.OperatingSystem.Version).SetDescription(d.OperatingSystem.Description).
		SetEdition(d.OperatingSystem.Edition).SetInstallDate(d.OperatingSystem.InstallDate).SetArch(d.OperatingSystem.Arch).
		SetUsername(d.OperatingSystem.Username).SetLastBootupTime(d.OperatingSystem.LastBootUpTime).SetDomain(d.OperatingSystem.Domain).
		SetOwnerID(d.AgentID).OnConflictColumns(operatingsystem.OwnerColumn).UpdateNewValues().Exec(ctx); err != nil {
		return err
	}
	if err := c.Antivirus.Create().SetName(d.Antivirus.Name).SetIsActive(d.Antivirus.IsActive).SetIsUpdated(d.Antivirus.IsUpdated).
		SetOwnerID(d.AgentID).OnConflictColumns(antivirus.OwnerColumn).UpdateNewValues().Exec(ctx); err != nil {
		return err
	}
	if err := c.SystemUpdate.Create().SetSystemUpdateStatus(d.SystemUpdate.Status).SetLastInstall(d.SystemUpdate.LastInstall).
		SetLastSearch(d.SystemUpdate.LastSearch).SetPendingUpdates(d.SystemUpdate.PendingUpdates).SetOwnerID(d.AgentID).
		OnConflictColumns(systemupdate.OwnerColumn).UpdateNewValues().Exec(ctx); err != nil {
		return err
	}
	if err := c.Netbird.Create().SetVersion(d.Netbird.Version).SetInstalled(d.Netbird.Installed).SetIP(d.Netbird.IP).
		SetSSHEnabled(d.Netbird.SSHEnabled).SetProfile(d.Netbird.Profile).SetManagementConnected(d.Netbird.ManagementConnected).
		SetManagementURL(d.Netbird.ManagementURL).SetSignalConnected(d.Netbird.SignalConnected).SetSignalURL(d.Netbird.SignalURL).
		SetPeersConnected(d.Netbird.PeersConnected).SetPeersTotal(d.Netbird.PeersTotal).SetServiceStatus(d.Netbird.ServiceStatus).
		SetProfilesAvailable(strings.Join(d.Netbird.Profiles, ",")).SetDNSServer(strings.Join(d.Netbird.DNSServers, ",")).
		SetOwnerID(d.AgentID).OnConflictColumns(netbird.OwnerColumn).UpdateNewValues().Exec(ctx); err != nil {
		return err
	}

	if _, err := c.App.Delete().Where(app.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.Applications {
		if err := c.App.Create().SetName(v.Name).SetVersion(v.Version).SetPublisher(v.Publisher).SetInstallDate(v.InstallDate).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.Monitor.Delete().Where(monitor.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.Monitors {
		if err := c.Monitor.Create().SetManufacturer(v.Manufacturer).SetModel(v.Model).SetSerial(v.Serial).SetWeekOfManufacture(v.WeekOfManufacture).SetYearOfManufacture(v.YearOfManufacture).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.MemorySlot.Delete().Where(memoryslot.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.MemorySlots {
		if err := c.MemorySlot.Create().SetSlot(v.Slot).SetType(v.MemoryType).SetPartNumber(v.PartNumber).SetSerialNumber(v.SerialNumber).SetSize(v.Size).SetSpeed(v.Speed).SetManufacturer(v.Manufacturer).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.LogicalDisk.Delete().Where(logicaldisk.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.LogicalDisks {
		if err := c.LogicalDisk.Create().SetLabel(v.Label).SetUsage(v.Usage).SetVolumeName(v.VolumeName).SetSizeInUnits(v.SizeInUnits).SetFilesystem(v.Filesystem).SetRemainingSpaceInUnits(v.RemainingSpaceInUnits).SetBitlockerStatus(v.BitLockerStatus).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.PhysicalDisk.Delete().Where(physicaldisk.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.PhysicalDisks {
		if err := c.PhysicalDisk.Create().SetDeviceID(v.DeviceID).SetModel(v.Model).SetSerialNumber(v.SerialNumber).SetSizeInUnits(v.SizeInUnits).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.Printer.Delete().Where(printer.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.Printers {
		if err := c.Printer.Create().SetName(v.Name).SetPort(v.Port).SetIsDefault(v.IsDefault).SetIsNetwork(v.IsNetwork).SetIsShared(v.IsShared).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.NetworkAdapter.Delete().Where(networkadapter.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.NetworkAdapters {
		if err := c.NetworkAdapter.Create().SetName(v.Name).SetMACAddress(v.MACAddress).SetAddresses(v.Addresses).SetSubnet(v.Subnet).SetDNSDomain(v.DNSDomain).SetDNSServers(v.DNSServers).SetDefaultGateway(v.DefaultGateway).SetDhcpEnabled(v.DHCPEnabled).SetDhcpLeaseExpired(v.DHCPLeaseExpired).SetDhcpLeaseObtained(v.DHCPLeaseObtained).SetSpeed(v.Speed).SetVirtual(v.Virtual).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.Share.Delete().Where(share.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.Shares {
		if err := c.Share.Create().SetName(v.Name).SetDescription(v.Description).SetPath(v.Path).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	if _, err := c.Update.Delete().Where(update.HasOwnerWith(agent.ID(d.AgentID))).Exec(ctx); err != nil {
		return err
	}
	for _, v := range d.Updates {
		if err := c.Update.Create().SetTitle(v.Title).SetDate(v.Date).SetSupportURL(v.SupportURL).SetOwnerID(d.AgentID).Exec(ctx); err != nil {
			return err
		}
	}
	return nil
}

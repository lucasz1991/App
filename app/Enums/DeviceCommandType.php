<?php

namespace App\Enums;

enum DeviceCommandType: string
{
    case Sync = 'sync';
    case Lock = 'lock';
    case Unlock = 'unlock';
    case Wipe = 'wipe';
    case Restart = 'restart';
    case ExecuteScript = 'execute_script';
    case InstallSoftware = 'install_software';
    case UninstallSoftware = 'uninstall_software';
    case CollectDiagnostics = 'collect_diagnostics';
    case ApplyProfile = 'apply_profile';
    case ApplyManagedProfile = 'apply_managed_profile';
    case StartRemoteSupport = 'start_remote_support';
}

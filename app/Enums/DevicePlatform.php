<?php

namespace App\Enums;

enum DevicePlatform: string
{
    case Windows = 'windows';
    case MacOS = 'macos';
    case Linux = 'linux';
    case Android = 'android';
    case IOS = 'ios';
    case IPadOS = 'ipados';
    case ChromeOS = 'chromeos';
    case Unknown = 'unknown';
}

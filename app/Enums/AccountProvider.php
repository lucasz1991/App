<?php

namespace App\Enums;

enum AccountProvider: string
{
    case Microsoft365 = 'microsoft_365';
    case GoogleWorkspace = 'google_workspace';
    case AppleManaged = 'apple_managed';
    case LocalDirectory = 'local_directory';
}

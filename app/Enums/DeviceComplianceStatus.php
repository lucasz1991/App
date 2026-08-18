<?php

namespace App\Enums;

enum DeviceComplianceStatus: string
{
    case Unknown = 'unknown';
    case Compliant = 'compliant';
    case Warning = 'warning';
    case NonCompliant = 'non_compliant';
    case Exempt = 'exempt';
}

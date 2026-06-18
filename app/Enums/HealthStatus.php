<?php

namespace App\Enums;

enum HealthStatus: string
{
    case Pass = 'PASS';
    case Warn = 'WARN';
    case Fail = 'FAIL';
}

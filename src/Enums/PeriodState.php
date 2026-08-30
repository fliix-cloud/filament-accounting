<?php

namespace FilamentAccounting\Enums;

enum PeriodState: string
{
    case Open = 'open';
    case SoftClosed = 'soft_closed';
    case HardClosed = 'hard_closed';
}

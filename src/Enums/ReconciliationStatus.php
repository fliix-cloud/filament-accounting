<?php

namespace FilamentAccounting\Enums;

enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Review = 'review';
}

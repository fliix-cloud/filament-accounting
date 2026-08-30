<?php

namespace FilamentAccounting\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Received = 'received';
    case Corrected = 'corrected';
    case Cancelled = 'cancelled';
}

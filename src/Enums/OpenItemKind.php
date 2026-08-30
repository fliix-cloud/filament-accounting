<?php

namespace FilamentAccounting\Enums;

enum OpenItemKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}

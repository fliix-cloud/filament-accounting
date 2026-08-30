<?php

namespace FilamentAccounting\Enums;

enum StatementLineStatus: string
{
    case Pending = 'pending';
    case Booked = 'booked';
    case Storno = 'storno';
}

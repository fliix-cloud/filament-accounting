<?php

namespace FilamentAccounting\Enums;

enum DocumentDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
}

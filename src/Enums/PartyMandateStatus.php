<?php

namespace FilamentAccounting\Enums;

enum PartyMandateStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Closed = 'closed';
}

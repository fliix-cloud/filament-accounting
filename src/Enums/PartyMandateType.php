<?php

namespace FilamentAccounting\Enums;

enum PartyMandateType: string
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';
}

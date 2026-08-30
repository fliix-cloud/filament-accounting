<?php

namespace FilamentAccounting\Enums;

enum DerivedReconciliationBadge: string
{
    case Unassigned = 'unassigned';
    case Partial = 'partial';
    case Assigned = 'assigned';
    case Review = 'review';
}

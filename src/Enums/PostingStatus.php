<?php

namespace FilamentAccounting\Enums;

enum PostingStatus: string
{
    case Unposted = 'unposted';
    case Posted = 'posted';
    case Reversed = 'reversed';
}

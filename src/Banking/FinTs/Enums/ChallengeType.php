<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

enum ChallengeType: string
{
    case Text = 'text';
    case Image = 'image';
    case Flicker = 'flicker';
    case Decoupled = 'decoupled';
    case None = 'none';
}

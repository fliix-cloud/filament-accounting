<?php

return [
    'connection' => ['pending' => 'Pending', 'active' => 'Active', 'needs_attention' => 'Needs attention', 'error' => 'Error', 'disabled' => 'Disabled'],
    'direction' => ['credit' => 'Credit', 'debit' => 'Debit'],
    'payment' => [
        'draft' => 'Draft', 'initiating' => 'Initiating', 'awaiting_vop' => 'Awaiting payee confirmation',
        'awaiting_tan' => 'Awaiting TAN', 'awaiting_decoupled_confirmation' => 'Awaiting app confirmation',
        'waiting_bank' => 'Waiting for bank', 'submitted' => 'Submitted', 'failed' => 'Failed',
        'expired' => 'Expired', 'cancelled' => 'Cancelled', 'ambiguous' => 'Needs review',
    ],
    'transfer_type' => ['sepa' => 'SEPA transfer', 'realtime' => 'SEPA instant', 'international' => 'International'],
    'sequence' => ['FRST' => 'First', 'RCUR' => 'Recurring', 'OOFF' => 'One-off', 'FNAL' => 'Final'],
    'scheme' => ['CORE' => 'CORE', 'B2B' => 'B2B'],
    'mandate_type' => ['one_off' => 'One-off', 'recurring' => 'Recurring'],
    'mandate_status' => ['active' => 'Active', 'revoked' => 'Revoked', 'closed' => 'Closed'],
    'sca' => [
        'needs_tan' => 'TAN required', 'needs_decoupled' => 'App confirmation required',
        'needs_vop' => 'Payee confirmation required', 'needs_polling' => 'Waiting for bank',
        'done' => 'Completed', 'failed' => 'Failed', 'expired' => 'Expired',
    ],
    'sync' => ['accounts' => 'Accounts', 'balances' => 'Balances', 'transactions' => 'Transactions', 'all' => 'All'],
    'sync_run' => ['running' => 'Running', 'completed' => 'Completed', 'requires_attention' => 'Requires attention', 'failed' => 'Failed'],
    'vop' => [
        'full_match' => 'Full match', 'close_match' => 'Close match', 'partial_match' => 'Partial match',
        'no_match' => 'No match', 'not_applicable' => 'Not applicable', 'unknown' => 'Unknown',
    ],
];

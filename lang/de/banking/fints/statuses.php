<?php

return [
    'connection' => ['pending' => 'Ausstehend', 'active' => 'Aktiv', 'needs_attention' => 'Handlung erforderlich', 'error' => 'Fehler', 'disabled' => 'Deaktiviert'],
    'direction' => ['credit' => 'Haben', 'debit' => 'Soll'],
    'payment' => [
        'draft' => 'Entwurf', 'initiating' => 'Wird eingereicht', 'awaiting_vop' => 'Empfängerbestätigung ausstehend',
        'awaiting_tan' => 'TAN ausstehend', 'awaiting_decoupled_confirmation' => 'App-Bestätigung ausstehend',
        'waiting_bank' => 'Wartet auf Bank', 'submitted' => 'Eingereicht', 'failed' => 'Fehlgeschlagen',
        'expired' => 'Abgelaufen', 'cancelled' => 'Abgebrochen', 'ambiguous' => 'Prüfung erforderlich',
    ],
    'transfer_type' => ['sepa' => 'SEPA-Überweisung', 'realtime' => 'Echtzeitüberweisung', 'international' => 'Auslandsüberweisung'],
    'sequence' => ['FRST' => 'Erstmalig', 'RCUR' => 'Wiederkehrend', 'OOFF' => 'Einmalig', 'FNAL' => 'Letztmalig'],
    'scheme' => ['CORE' => 'CORE', 'B2B' => 'B2B'],
    'mandate_type' => ['one_off' => 'Einmalig', 'recurring' => 'Wiederkehrend'],
    'mandate_status' => ['active' => 'Aktiv', 'revoked' => 'Widerrufen', 'closed' => 'Abgeschlossen'],
    'sca' => [
        'needs_tan' => 'TAN erforderlich', 'needs_decoupled' => 'App-Bestätigung erforderlich',
        'needs_vop' => 'Empfängerbestätigung erforderlich', 'needs_polling' => 'Wartet auf Bank',
        'done' => 'Abgeschlossen', 'failed' => 'Fehlgeschlagen', 'expired' => 'Abgelaufen',
    ],
    'sync' => ['accounts' => 'Konten', 'balances' => 'Salden', 'transactions' => 'Umsätze', 'all' => 'Alles'],
    'sync_run' => ['running' => 'Läuft', 'completed' => 'Abgeschlossen', 'requires_attention' => 'Handlung erforderlich', 'failed' => 'Fehlgeschlagen'],
    'vop' => [
        'full_match' => 'Vollständige Übereinstimmung', 'close_match' => 'Nahe Übereinstimmung',
        'partial_match' => 'Teilweise Übereinstimmung', 'no_match' => 'Keine Übereinstimmung',
        'not_applicable' => 'Nicht anwendbar', 'unknown' => 'Unbekannt',
    ],
];

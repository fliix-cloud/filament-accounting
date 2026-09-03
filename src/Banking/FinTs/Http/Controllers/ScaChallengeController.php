<?php

namespace FilamentAccounting\Banking\FinTs\Http\Controllers;

use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use Illuminate\Http\Response;

class ScaChallengeController
{
    public function __invoke(
        string $session,
        OwnerScope $owners,
        BankAuthorizer $authorizer,
    ): Response {
        abort_unless($authorizer->can('confirm_bank_sca'), 403);

        $connectionQuery = $owners->connections();
        $record = StrongAuthenticationSession::query()
            ->where('uuid', $session)
            ->whereIn('bank_connection_id', $connectionQuery->select('id'))
            ->firstOrFail();

        abort_unless($record->isOpen() && filled($record->encrypted_challenge_payload), 404);

        $binary = base64_decode((string) $record->encrypted_challenge_payload, true) ?: '';
        $mime = $record->challenge_mime ?: 'application/octet-stream';

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'], true)) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

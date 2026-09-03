<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\GetStatementOfAccount;
use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use FilamentAccounting\Banking\FinTs\Actions\GetCamtStatementOfAccount;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\ProvidesCamtStatementSchemas;

final class StatementActionFactory
{
    public function create(
        FintsClient $client,
        SEPAAccount $account,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): GetStatementOfAccount|GetCamtStatementOfAccount {
        if ($client instanceof ProvidesCamtStatementSchemas && $client->supportedCamtStatementSchemas() !== []) {
            return GetCamtStatementOfAccount::create($account, $from, $to);
        }

        return GetStatementOfAccount::create($account, $from, $to, false, true);
    }

    public function supports(BaseAction $action): bool
    {
        return $action instanceof GetStatementOfAccount || $action instanceof GetCamtStatementOfAccount;
    }

    public function result(BaseAction $action): StatementOfAccount
    {
        return match (true) {
            $action instanceof GetStatementOfAccount => $action->getStatement(),
            $action instanceof GetCamtStatementOfAccount => $action->getStatement(),
            default => throw new \InvalidArgumentException('Unsupported statement action.'),
        };
    }
}

<?php

namespace FilamentAccounting\Banking\FinTs\Contracts;

interface ProvidesCamtStatementSchemas
{
    /** @return list<string> */
    public function supportedCamtStatementSchemas(): array;
}

<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Support\SepaPainCreditTransfer;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SepaPainCreditTransferTest extends TestCase
{
    #[Test]
    public function future_execution_dates_are_written_into_pain_xml(): void
    {
        $xml = (new SepaPainCreditTransfer)->toXml(
            'pain.001.003.03',
            'msg-1',
            'pmt-1',
            'Demo GmbH',
            'DE89370400440532013000',
            'COBADEFFXXX',
            'Lieferant GmbH',
            'DE02120300000000202051',
            'BYLADEM1001',
            '10.25',
            'EUR',
            'Rechnung',
            'E2E-1',
            '2099-03-15',
        );

        $this->assertStringContainsString('<ReqdExctnDt>2099-03-15</ReqdExctnDt>', $xml);
        $this->assertStringNotContainsString('1999-01-01', $xml);
    }

    #[Test]
    public function missing_or_past_dates_use_the_immediate_sepa_sentinel(): void
    {
        $xml = (new SepaPainCreditTransfer)->toXml(
            'pain.001.003.03',
            'msg-1',
            'pmt-1',
            'Demo GmbH',
            'DE89370400440532013000',
            null,
            'Lieferant GmbH',
            'DE02120300000000202051',
            null,
            '10.25',
            'EUR',
            null,
            null,
            now()->toDateString(),
        );

        $this->assertStringContainsString('<ReqdExctnDt>1999-01-01</ReqdExctnDt>', $xml);
    }
}

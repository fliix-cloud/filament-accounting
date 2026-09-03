<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Fhp\CAMT\CAMT;
use Fhp\Model\StatementOfAccount\StatementOfAccount;

final class CamtStatementParser
{
    /**
     * @param  list<string>  $bookedXml
     * @param  list<string>  $pendingXml
     */
    public function parse(array $bookedXml, array $pendingXml): StatementOfAccount
    {
        $booked = $this->parseDocuments($bookedXml, true);
        $pending = $this->parseDocuments($pendingXml, false);

        foreach ($pending as $date => $statement) {
            if (! isset($booked[$date])) {
                $booked[$date] = $statement;

                continue;
            }

            $booked[$date]['transactions'] = array_merge(
                $booked[$date]['transactions'] ?? [],
                $statement['transactions'] ?? [],
            );
        }

        ksort($booked);

        return StatementOfAccount::fromCAMTArray($booked);
    }

    /**
     * @param  list<string>  $documents
     * @return array<string, array<string, mixed>>
     */
    private function parseDocuments(array $documents, bool $booked): array
    {
        if ($documents === []) {
            return [];
        }

        $parsed = (new CAMT)->parse(array_map($this->normalizeMissingBookingDate(...), $documents));

        foreach ($parsed as &$statement) {
            if (! isset($statement['transactions'])) {
                continue;
            }

            foreach ($statement['transactions'] as &$transaction) {
                $transaction['booked'] = $booked;
            }

            unset($transaction);
        }

        unset($statement);

        return $parsed;
    }

    private function normalizeMissingBookingDate(string $xml): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $namespace = $document->documentElement?->namespaceURI;
        if (! $loaded || ! is_string($namespace) || ! str_contains($namespace, 'camt.052')) {
            return $xml;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', $namespace);

        foreach ($xpath->query('//c:Ntry[not(c:BookgDt)]') ?: [] as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }

            $source = $xpath->query('./c:ValDt/c:Dt | ./c:ValDt/c:DtTm', $entry)?->item(0);
            $date = $source ? substr(trim($source->textContent), 0, 10) : '';
            if ($date === '') {
                continue;
            }

            $bookingDate = $document->createElementNS($namespace, 'BookgDt');
            $bookingDate->appendChild($document->createElementNS($namespace, 'Dt', $date));
            $valueDate = $xpath->query('./c:ValDt', $entry)?->item(0);
            $entry->insertBefore($bookingDate, $valueDate);
        }

        return $document->saveXML() ?: $xml;
    }
}

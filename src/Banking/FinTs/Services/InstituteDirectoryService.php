<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use FilamentAccounting\Banking\FinTs\Exceptions\NetworkException;
use FilamentAccounting\Banking\FinTs\Models\BankInstitute;
use FilamentAccounting\Banking\FinTs\Support\BankQuirks;
use Illuminate\Support\Facades\Http;

class InstituteDirectoryService
{
    /**
     * @return array{imported: int, with_pin_tan: int, skipped: int}
     */
    public function sync(?string $url = null, bool $includeWithoutEndpoint = false): array
    {
        $url ??= (string) config('filament-accounting.banking.fints.institutes.url');
        $body = $this->download($url);
        $rows = $this->parse($body);
        $now = now();
        $imported = 0;
        $withPinTan = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! $includeWithoutEndpoint && ($row['pin_tan_url'] ?? '') === '') {
                $skipped++;

                continue;
            }

            $urlValue = $row['pin_tan_url'] !== '' ? $row['pin_tan_url'] : null;
            $bankCode = $urlValue !== null
                ? BankQuirks::normalizeBankCode($row['bank_code'], $urlValue)
                : $row['bank_code'];

            BankInstitute::query()->updateOrCreate(
                ['bank_code' => $bankCode],
                [
                    'name' => $row['name'],
                    'city' => $row['city'] !== '' ? $row['city'] : null,
                    'bic' => $row['bic'] !== '' ? $row['bic'] : null,
                    'checksum_method' => $row['checksum_method'] !== '' ? $row['checksum_method'] : null,
                    'hbci_host' => $row['hbci_host'] !== '' ? $row['hbci_host'] : null,
                    'pin_tan_url' => $urlValue,
                    'hbci_version' => $row['hbci_version'] !== '' ? $row['hbci_version'] : null,
                    'pin_tan_version' => $row['pin_tan_version'] !== '' ? $row['pin_tan_version'] : null,
                    'has_pin_tan' => $urlValue !== null,
                    'source' => $row['source'],
                    'synced_at' => $now,
                ],
            );

            $imported++;
            if ($urlValue !== null) {
                $withPinTan++;
            }
        }

        return [
            'imported' => $imported,
            'with_pin_tan' => $withPinTan,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array{
     *     bank_code: string,
     *     name: string,
     *     city: string,
     *     bic: string,
     *     checksum_method: string,
     *     hbci_host: string,
     *     pin_tan_url: string,
     *     hbci_version: string,
     *     pin_tan_version: string,
     *     source: string
     * }>
     */
    public function parse(string $body): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$bankCode, $rest] = explode('=', $line, 2);
            $bankCode = trim($bankCode);

            if (! preg_match('/^\d{8}$/', $bankCode)) {
                continue;
            }

            $parts = array_pad(explode('|', $rest), 8, '');

            $rows[] = [
                'bank_code' => $bankCode,
                'name' => trim($parts[0]),
                'city' => trim($parts[1]),
                'bic' => strtoupper(trim($parts[2])),
                'checksum_method' => trim($parts[3]),
                'hbci_host' => trim($parts[4]),
                'pin_tan_url' => $this->normalizeUrl(trim($parts[5])),
                'hbci_version' => trim($parts[6]),
                'pin_tan_version' => trim($parts[7]),
                'source' => 'hbci4j/hbci4java',
            ];
        }

        return $rows;
    }

    private function download(string $url): string
    {
        $response = Http::timeout((int) config('filament-accounting.banking.fints.institutes.timeout', 30))
            ->accept('text/plain')
            ->withHeaders([
                'User-Agent' => (string) config('filament-accounting.banking.fints.product.user_agent', 'filament-accounting'),
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new NetworkException('The bank institute directory could not be downloaded.');
        }

        return (string) $response->body();
    }

    private function normalizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}

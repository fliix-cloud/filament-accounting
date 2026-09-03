<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Composer\InstalledVersions;
use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Exceptions\ConfigurationException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Support\BankQuirks;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Banking\FinTs\Support\RedactingLogger;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PhpFintsClientFactory implements FintsClientFactory
{
    public function __construct(
        private readonly FintsDialogStore $dialogs,
    ) {}

    public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient
    {
        $productId = ProductRegistration::id();

        if ($productId === '') {
            throw new ConfigurationException('FINTS_PRODUCT_ID is not configured.');
        }

        $url = EndpointValidator::validate((string) $connection->endpoint_url);
        $bankCode = BankQuirks::normalizeBankCode((string) $connection->bank_code, $url);

        $options = new FinTsOptions;
        $options->url = $url;
        $options->bankCode = $bankCode;
        $options->productName = $productId;
        $options->userAgent = (string) config('filament-accounting.banking.fints.product.user_agent', 'filament-accounting');
        $options->productVersion = $this->productVersion();

        $credentials = Credentials::create(
            (string) $connection->username,
            (string) $connection->pin,
        );

        $resumingSca = $persistedInstance !== null;
        $blob = $persistedInstance ?? $this->dialogs->restore($connection);

        try {
            $fints = FinTs::new($options, $credentials, $blob);
        } catch (\InvalidArgumentException) {
            $this->dialogs->forget($connection);
            $fints = FinTs::new($options, $credentials);
        }

        // Restored dialog IDs are only valid while an SCA challenge is in
        // progress. A leftover ID from a previous HTTP request is almost
        // always expired; phpFinTS documents forgetDialog() for that case.
        if (! $resumingSca) {
            $fints->forgetDialog();
        }

        if (! $resumingSca && is_string($blob) && $blob !== '') {
            $connection->encrypted_fints_state = base64_encode($fints->persist(false));
            $connection->fints_state_saved_at = now();
            $connection->save();
        }

        $fints->setLogger($this->logger());

        if (BankQuirks::isIngDiba($bankCode)) {
            $fints->selectTanMode(new NoPsd2TanMode);
        } elseif (filled($connection->tan_mode_id)) {
            $medium = filled($connection->tan_medium_name) ? $connection->tan_medium_name : null;
            $fints->selectTanMode((int) $connection->tan_mode_id, $medium);
        }

        return new PhpFintsClient($fints);
    }

    private function productVersion(): string
    {
        $configured = config('filament-accounting.banking.fints.product.version');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (config('filament-accounting.banking.fints.product.derive_version_from_package')) {
            try {
                $version = InstalledVersions::getPrettyVersion('fliix-cloud/filament-accounting');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return '1.0.0';
    }

    private function logger(): LoggerInterface
    {
        if (config('filament-accounting.banking.fints.security.sensitive_logging')) {
            $channel = config('filament-accounting.banking.fints.logging.channel');

            return $channel ? Log::channel($channel) : Log::getLogger();
        }

        $inner = config('filament-accounting.banking.fints.logging.channel')
            ? Log::channel(config('filament-accounting.banking.fints.logging.channel'))
            : new NullLogger;

        return new RedactingLogger($inner);
    }
}

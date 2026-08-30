<?php

namespace FilamentAccounting\Support;

use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Money as BrickMoney;
use FilamentAccounting\Exceptions\CurrencyMismatchException;
use FilamentAccounting\Exceptions\InvalidMoneyException;

final readonly class ExactMoney
{
    private function __construct(
        public int $minorAmount,
        public string $currency,
        public int $scale,
    ) {}

    public static function ofMinor(int $minorAmount, string $currency): self
    {
        $currency = strtoupper($currency);
        $scale = Currency::of($currency)->getDefaultFractionDigits();

        return new self($minorAmount, $currency, $scale);
    }

    public static function ofString(string $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $amount = trim($amount);

        if ($amount === '' || ! is_numeric($amount)) {
            throw new InvalidMoneyException("Invalid monetary amount [{$amount}].");
        }

        try {
            $brick = BrickMoney::of($amount, $currency, null, RoundingMode::UNNECESSARY);
        } catch (\Throwable $e) {
            throw new InvalidMoneyException($e->getMessage(), 0, $e);
        }

        return self::fromBrick($brick);
    }

    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    public static function fromBrick(BrickMoney $money): self
    {
        return new self(
            $money->getUnscaledAmount()->toInt(),
            $money->getCurrency()->getCurrencyCode(),
            $money->getCurrency()->getDefaultFractionDigits(),
        );
    }

    public function toBrick(): BrickMoney
    {
        return BrickMoney::ofMinor($this->minorAmount, $this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::ofMinor($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::ofMinor($this->minorAmount - $other->minorAmount, $this->currency);
    }

    public function negated(): self
    {
        return self::ofMinor(-$this->minorAmount, $this->currency);
    }

    public function abs(): self
    {
        return self::ofMinor(abs($this->minorAmount), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->scale === $other->scale
            && $this->minorAmount === $other->minorAmount;
    }

    public function isZero(): bool
    {
        return $this->minorAmount === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorAmount > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorAmount < 0;
    }

    public function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency || $this->scale !== $other->scale) {
            throw new CurrencyMismatchException(
                "Currency/scale mismatch: {$this->currency}/{$this->scale} vs {$other->currency}/{$other->scale}."
            );
        }
    }

    public function decimalString(): string
    {
        return (string) $this->toBrick()->getAmount();
    }

    public function signedDecimalString(): string
    {
        return $this->decimalString();
    }
}

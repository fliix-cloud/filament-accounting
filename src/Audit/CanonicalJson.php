<?php

namespace FilamentAccounting\Audit;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final class CanonicalJson
{
    public const VERSION = 1;

    public function encode(mixed $value): string
    {
        return json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Floats are not supported in canonical audit payloads; use an exact decimal string or minor units.');
        }

        if ($value instanceof BackedEnum) {
            return $this->normalize($value->value);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Unsupported value in canonical audit payload: '.get_debug_type($value));
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalize($item);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}

<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;

final class SerializedFintsPayload
{
    public static function unserialize(string $payload, bool $requireObject = false): mixed
    {
        self::assertOnlyFhpClasses($payload);

        $value = unserialize($payload, ['allowed_classes' => true]);
        if ($requireObject && ! is_object($value)) {
            throw new ScaExpiredException('Stored FinTS action is invalid.');
        }

        return $value;
    }

    private static function assertOnlyFhpClasses(string $payload): void
    {
        if (preg_match_all('/(?:C|O):\d+:"([^"]+)"/', $payload, $matches) === false) {
            throw new ScaExpiredException('Stored FinTS payload could not be inspected.');
        }

        foreach ($matches[1] as $class) {
            if (self::isAllowedClass($class)) {
                continue;
            }

            throw new ScaExpiredException("Stored FinTS payload contains a disallowed class [{$class}].");
        }
    }

    private static function isAllowedClass(string $class): bool
    {
        if ($class === \DateTime::class
            || $class === \DateTimeImmutable::class
            || $class === \stdClass::class
            || str_starts_with($class, 'Fhp\\')) {
            return true;
        }

        if (! class_exists($class)) {
            return false;
        }

        foreach (class_parents($class) ?: [] as $parent) {
            if (str_starts_with($parent, 'Fhp\\')) {
                return true;
            }
        }

        foreach (class_implements($class) ?: [] as $interface) {
            if (str_starts_with($interface, 'Fhp\\')) {
                return true;
            }
        }

        return false;
    }
}

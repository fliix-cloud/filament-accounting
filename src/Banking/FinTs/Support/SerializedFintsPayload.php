<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;

final class SerializedFintsPayload
{
    public static function unserialize(string $payload, bool $requireObject = false): mixed
    {
        $classes = self::assertOnlyFhpClasses($payload);

        $value = unserialize($payload, ['allowed_classes' => $classes === [] ? false : $classes]);
        if ($requireObject && ! is_object($value)) {
            throw new ScaExpiredException('Stored FinTS action is invalid.');
        }

        return $value;
    }

    /** @return list<class-string> */
    private static function assertOnlyFhpClasses(string $payload): array
    {
        if (preg_match_all('/(?:C|O):\d+:"([^"]+)"/', $payload, $matches) === false) {
            throw new ScaExpiredException('Stored FinTS payload could not be inspected.');
        }

        $classes = [];
        foreach ($matches[1] as $class) {
            if (! self::isAllowedClass($class)) {
                throw new ScaExpiredException("Stored FinTS payload contains a disallowed class [{$class}].");
            }
            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    private static function isAllowedClass(string $class): bool
    {
        if ($class === \DateTime::class
            || $class === \DateTimeImmutable::class
            || $class === \stdClass::class
            || str_starts_with($class, 'Fhp\\')
            || str_starts_with($class, 'FilamentAccounting\\Banking\\FinTs\\')) {
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

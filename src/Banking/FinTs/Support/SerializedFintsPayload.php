<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Fhp\BaseAction;
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

        foreach ($matches[1] ?? [] as $class) {
            if (self::isAllowedClass($class)) {
                continue;
            }

            throw new ScaExpiredException('Stored FinTS payload contains a disallowed class.');
        }
    }

    private static function isAllowedClass(string $class): bool
    {
        if (str_starts_with($class, 'Fhp\\')
            || in_array($class, [\DateTime::class, \DateTimeImmutable::class, \stdClass::class], true)) {
            return true;
        }

        return class_exists($class) && is_subclass_of($class, BaseAction::class);
    }
}

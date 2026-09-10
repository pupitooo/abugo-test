<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Types;

final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof DateTimeImmutable) {
            throw ConversionException::conversionFailedInvalidType(
                $value,
                Types::DATETIME_IMMUTABLE,
                ['null', DateTimeImmutable::class],
            );
        }

        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format($platform->getDateTimeFormatString());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            '!' . $platform->getDateTimeFormatString(),
            (string) $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($dateTime !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dateTime;
        }

        throw ConversionException::conversionFailedFormat(
            $value,
            Types::DATETIME_IMMUTABLE,
            $platform->getDateTimeFormatString(),
        );
    }
}

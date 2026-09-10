<?php

declare(strict_types=1);

namespace App\UserInterface\GraphQL;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class UtcDateTimeFormatter
{
    public static function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }
}

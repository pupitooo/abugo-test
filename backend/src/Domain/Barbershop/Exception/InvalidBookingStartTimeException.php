<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Exception;

final class InvalidBookingStartTimeException extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Start time must use YYYY-MM-DDTHH:MM:SS with Z or an explicit UTC offset; '
            . 'the booking must stay within UTC years 0000-9999.',
            previous: $previous,
        );
    }
}

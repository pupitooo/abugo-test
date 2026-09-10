<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Exception;

final class BookingTemporarilyUnavailableException extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Booking service is temporarily unavailable. Please try again.', previous: $previous);
    }
}

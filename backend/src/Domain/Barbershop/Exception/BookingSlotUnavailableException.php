<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Exception;

final class BookingSlotUnavailableException extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This time slot is no longer available.', previous: $previous);
    }
}

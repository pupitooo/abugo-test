<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Repository;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Exception\BookingSlotUnavailableException;
use App\Domain\Barbershop\Exception\NotFoundException;
use App\Domain\ValueObject\Uuid;

interface BookingRepositoryInterface
{
    /** @throws NotFoundException */
    public function getById(Uuid $id): Booking;

    /** @throws BookingSlotUnavailableException */
    public function save(Booking $booking): void;
}

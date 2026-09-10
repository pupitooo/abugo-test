<?php

declare(strict_types=1);

namespace App\Infrastructure\Barbershop\Repository;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Exception\BookingSlotUnavailableException;
use App\Domain\Barbershop\Exception\BookingTemporarilyUnavailableException;
use App\Domain\Barbershop\Exception\NotFoundException;
use App\Domain\Barbershop\Repository\BookingRepositoryInterface;
use App\Domain\ValueObject\Uuid;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineBookingRepository implements BookingRepositoryInterface
{
    private const SLOT_UNAVAILABLE_SIGNAL = 'booking_slot_unavailable';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function getById(Uuid $id): Booking
    {
        return $this->em->find(Booking::class, $id)
            ?? throw new NotFoundException("Booking {$id} not found");
    }

    public function save(Booking $booking): void
    {
        try {
            $this->em->persist($booking);
            $this->em->flush();
        } catch (LockWaitTimeoutException $exception) {
            throw new BookingTemporarilyUnavailableException(previous: $exception);
        } catch (DriverException $exception) {
            if (!self::isSlotUnavailableViolation($exception)) {
                throw $exception;
            }

            throw new BookingSlotUnavailableException(previous: $exception);
        }
    }

    private static function isSlotUnavailableViolation(DriverException $exception): bool
    {
        return $exception->getSQLState() === '23000'
            && $exception->getCode() === 19
            && str_contains($exception->getMessage(), self::SLOT_UNAVAILABLE_SIGNAL);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Barbershop\Command\CreateBooking;

use App\Application\CommandResult;
use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Service;
use App\Domain\Barbershop\Entity\Stylist;
use App\Domain\Barbershop\Exception\InvalidBookingStartTimeException;
use App\Domain\Barbershop\Repository\BookingRepositoryInterface;
use App\Domain\ValueObject\UuidFactory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final class CreateBookingCommandHandler
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookingRepository,
        private readonly EntityManagerInterface $em,
        private readonly UuidFactory $uuidFactory,
    ) {}

    public function handle(CreateBookingCommand $command): CommandResult
    {
        $service = $this->em->find(Service::class, $this->uuidFactory->fromString($command->serviceId))
            ?? throw new DomainException("Service {$command->serviceId} not found");

        $stylist = $this->em->find(Stylist::class, $this->uuidFactory->fromString($command->stylistId))
            ?? throw new DomainException("Stylist {$command->stylistId} not found");

        $start = $this->parseStartTime($command->startTime);
        $end   = $start->modify("+{$service->getDurationMinutes()} minutes");

        $booking = new Booking(
            $this->uuidFactory->generate(),
            $service,
            $stylist,
            $start,
            $end,
            $command->customerName,
            $command->customerContact,
        );

        $this->bookingRepository->save($booking);

        return new CommandResult($booking->getId()->toString());
    }

    private function parseStartTime(string $value): DateTimeImmutable
    {
        // Booking storage and overlap checks use second precision, so accept one explicit input precision as well.
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidBookingStartTimeException();
        }

        $dateTime = DateTimeImmutable::createFromFormat('!' . DateTimeInterface::RFC3339, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidBookingStartTimeException();
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}

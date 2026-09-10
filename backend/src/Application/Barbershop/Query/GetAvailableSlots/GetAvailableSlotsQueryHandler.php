<?php

declare(strict_types=1);

namespace App\Application\Barbershop\Query\GetAvailableSlots;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Enum\BookingStatus;
use App\Domain\Barbershop\Enum\DayOfWeek;
use App\Domain\Barbershop\Repository\ServiceRepositoryInterface;
use App\Domain\Barbershop\Repository\StylistRepositoryInterface;
use App\Domain\Barbershop\ValueObject\Slot;
use App\Domain\ValueObject\UuidFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final class GetAvailableSlotsQueryHandler
{
    public function __construct(
        private readonly StylistRepositoryInterface $stylistRepository,
        private readonly ServiceRepositoryInterface $serviceRepository,
        private readonly EntityManagerInterface $em,
        private readonly UuidFactory $uuidFactory,
    ) {}

    /** @return Slot[] */
    public function handle(GetAvailableSlotsQuery $query): array
    {
        $stylist = $this->stylistRepository->getById($this->uuidFactory->fromString($query->stylistId));
        $service = $this->serviceRepository->getById($this->uuidFactory->fromString($query->serviceId));

        $business   = $stylist->getBusiness();
        $duration   = $service->getDurationMinutes();
        $timezone   = new DateTimeZone($business->getTimezone());
        $localDate  = $this->parseLocalDate($query->date, $timezone);
        $dayOfWeek  = DayOfWeek::from((int) $localDate->format('N'));
        $todayHours = $business->getOpeningHoursForDay($dayOfWeek);

        if ($todayHours === null) {
            return [];
        }

        $openFrom = $this->parseLocalDateTime($query->date, $todayHours->getOpenFrom(), $timezone);
        $openTo   = $this->parseLocalDateTime($query->date, $todayHours->getOpenTo(), $timezone);
        $step     = new DateInterval("PT{$duration}M");
        $utc      = new DateTimeZone('UTC');

        $allSlots  = [];
        $slotStart = $openFrom;
        while (true) {
            $slotEnd = $slotStart->add($step);
            if ($slotEnd > $openTo) {
                break;
            }
            $allSlots[] = new Slot(
                $slotStart->setTimezone($utc),
                $slotEnd->setTimezone($utc),
            );
            $slotStart  = $slotEnd;
        }

        $dayStart = $localDate->setTime(0, 0)->setTimezone($utc);
        $dayEnd   = $localDate->modify('+1 day')->setTime(0, 0)->setTimezone($utc);

        /** @var Booking[] $bookings */
        $bookings = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Booking::class, 'b')
            ->where('b.stylist = :stylist')
            ->andWhere('b.startTime < :dayEnd')
            ->andWhere('b.endTime > :dayStart')
            ->andWhere('b.status != :rejected')
            ->setParameter('stylist', $stylist)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->setParameter('rejected', BookingStatus::Rejected->value)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $allSlots,
            static function (Slot $slot) use ($bookings): bool {
                foreach ($bookings as $booking) {
                    if ($booking->getStartTime() < $slot->endTime && $booking->getEndTime() > $slot->startTime) {
                        return false;
                    }
                }
                return true;
            },
        ));
    }

    private function parseLocalDate(string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $localDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $localDate === false
            || $localDate->format('Y-m-d') !== $date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new DomainException('Date must use the YYYY-MM-DD format.');
        }

        return $localDate;
    }

    private function parseLocalDateTime(string $date, string $time, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = "$date $time";
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $dateTime === false
            || $dateTime->format('Y-m-d H:i') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new DomainException("Opening hours contain a non-existent local time: $value");
        }

        return $dateTime;
    }
}

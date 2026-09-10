<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Entity;

use App\Domain\Barbershop\Enum\BookingStatus;
use App\Domain\Barbershop\Exception\InvalidCustomerNameException;
use App\Domain\ValueObject\Uuid;
use DateTimeImmutable;
use DateTimeZone;

class Booking
{
    private Uuid $id;
    private Service $service;
    private Stylist $stylist;
    private DateTimeImmutable $startTime;
    private DateTimeImmutable $endTime;
    private BookingStatus $status;
    private string $customerName;
    private string $customerContact;

    public function __construct(
        Uuid $id,
        Service $service,
        Stylist $stylist,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        string $customerName,
        string $customerContact,
    ) {
        if (preg_match('/\S/u', $customerName) !== 1) {
            throw new InvalidCustomerNameException();
        }

        $utc = new DateTimeZone('UTC');

        $this->id              = $id;
        $this->service         = $service;
        $this->stylist         = $stylist;
        $this->startTime       = $startTime->setTimezone($utc);
        $this->endTime         = $endTime->setTimezone($utc);
        $this->status          = BookingStatus::Pending;
        $this->customerName    = $customerName;
        $this->customerContact = $customerContact;
    }

    public function getId(): Uuid { return $this->id; }
    public function getService(): Service { return $this->service; }
    public function getStylist(): Stylist { return $this->stylist; }
    public function getStartTime(): DateTimeImmutable { return $this->startTime; }
    public function getEndTime(): DateTimeImmutable { return $this->endTime; }
    public function getStatus(): BookingStatus { return $this->status; }
    public function getCustomerName(): string { return $this->customerName; }
    public function getCustomerContact(): string { return $this->customerContact; }

    public function confirm(): void { $this->status = BookingStatus::Confirmed; }
    public function reject(): void  { $this->status = BookingStatus::Rejected; }
}

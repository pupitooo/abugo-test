<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Exception;

final class InvalidCustomerNameException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Customer name must not be blank.');
    }
}

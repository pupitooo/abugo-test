<?php

declare(strict_types=1);

namespace App\UserInterface\GraphQL\Types\Barbershop;

use App\Domain\Barbershop\ValueObject\Slot;
use App\UserInterface\GraphQL\UtcDateTimeFormatter;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

final class SlotType extends GraphQLType
{
    protected $attributes = [
        'name' => 'BarbershopSlot',
        'description' => 'An available time slot',
        'model' => Slot::class,
    ];

    public function fields(): array
    {
        return [
            'startTime' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Start time in UTC YYYY-MM-DDTHH:MM:SS+00:00 format',
                'resolve' => fn(Slot $slot): string => UtcDateTimeFormatter::format($slot->startTime),
            ],
            'endTime' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'End time in UTC YYYY-MM-DDTHH:MM:SS+00:00 format',
                'resolve' => fn(Slot $slot): string => UtcDateTimeFormatter::format($slot->endTime),
            ],
        ];
    }
}

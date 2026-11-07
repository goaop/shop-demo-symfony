<?php

declare(strict_types=1);

namespace App\Event;

use App\Domain\Order;

/**
 * Dispatched through Symfony's event dispatcher once an order is placed
 */
final readonly class OrderPlaced
{
    public function __construct(
        public Order $order,
    ) {}
}

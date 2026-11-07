<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Order;
use App\Event\OrderPlaced;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class NotificationService
{
    /** @var list<string> */
    private array $outbox = [];

    #[AsEventListener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->orderConfirmation($event->order);
    }

    public function orderConfirmation(Order $order): void
    {
        $this->outbox[] = \sprintf(
            "To: %s\nYour order #%d of %d items: %.2f %s",
            $order->customer->email,
            $order->id,
            \count($order->lines()),
            $order->total()->cents / 100,
            $order->total()->currency,
        );
    }

    public function sent(): int
    {
        return \count($this->outbox);
    }
}

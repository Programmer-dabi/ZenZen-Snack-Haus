<?php
namespace App\Orders;

final class StorePickupWorkflow extends AbstractOrderWorkflow
{
    public const STATUSES = ['Placed order', 'Confirmed', 'Preparing', 'Ready for pickup', 'Completed', 'Cancelled'];

    // Flow: Return the allowed statuses for store pickup.
    public function statuses(): array
    {
        return self::STATUSES;
    }

    // Flow: Return the customer tracking labels for store pickup.
    protected function trackingSteps(): array
    {
        return ['Placed order', 'Order confirmed', 'Preparing your order', 'Ready for pickup', 'Completed'];
    }

    // Flow: Map statuses to tracking progress for store pickup.
    protected function progressStates(): array
    {
        return [
            'preparing' => [3, 'Your order is being prepared.', 'preparing'],
            'ready' => [4, 'Your order is ready for pickup.', 'ready'],
        ];
    }
}

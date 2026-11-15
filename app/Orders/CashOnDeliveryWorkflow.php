<?php
namespace App\Orders;

final class CashOnDeliveryWorkflow extends AbstractOrderWorkflow
{
    // Flow: Return the allowed statuses for cash on delivery.
    public function statuses(): array
    {
        return ['Placed order', 'Confirmed', 'Preparing', 'Delivery', 'Completed', 'Cancelled'];
    }

    // Flow: Return the customer tracking labels for cash on delivery.
    protected function trackingSteps(): array
    {
        return ['Placed order', 'Order confirmed', 'Preparing your order', 'Delivery', 'Completed'];
    }

    // Flow: Map statuses to tracking progress for cash on delivery.
    protected function progressStates(): array
    {
        return [
            'preparing' => [3, 'Your order is being prepared.', 'preparing'],
            'ready' => [4, 'Your order is on delivery.', 'delivery'],
            'delivery' => [4, 'Your order is on delivery.', 'delivery'],
        ];
    }

    // Flow: Choose the readiness status for cash on delivery.
    protected function readyStatus(): string
    {
        return 'Delivery';
    }

    // Flow: Translate historical readiness labels for cash on delivery.
    protected function displayStatus(string $status, string $normalized): string
    {
        return in_array($normalized, ['ready', 'ready for pickup', 'ready to pickup'], true)
            ? 'Delivery' : $status;
    }
}

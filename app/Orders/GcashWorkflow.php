<?php

namespace App\Orders;

// GCash adds a payment-confirmed stage before preparation and delivery; Payed is the existing saved label.
final class GcashWorkflow extends AbstractOrderWorkflow
{
    // Flow: Return the allowed statuses for GCash.
    public function statuses(): array
    {
        return [
            'Placed order',
            'Confirmed',
            'Payed',
            'Preparing',
            'On delivery',
            'Completed',
            'Cancelled'
        ];
    }

    // Flow: Return the customer tracking labels for GCash.
    protected function trackingSteps(): array
    {
        return [
            'Placed order',
            'Order confirmed',
            'Payed',
            'Preparing your order',
            'On delivery',
            'Completed'
        ];
    }

    // Flow: Map statuses to tracking progress for GCash.
    // Each tracking entry contains the progress step number, customer-facing heading, and CSS class.
    protected function progressStates(): array
    {
        return [
            'preparing' => [4, 'Your order is being prepared.', 'preparing'],
            'ready' => [4, 'Your order is ready for delivery.', 'ready'],
            'payed' => [3, 'Your payment has been confirmed.', 'payed'],
            'delivery' => [5, 'Your order is on delivery.', 'delivery'],
        ];
    }

    // Flow: Choose the readiness status for GCash.
    protected function readyStatus(): string
    {
        return 'Preparing';
    }

    // Flow: Choose the delivery status for GCash.
    protected function deliveryStatus(): string
    {
        return 'On delivery';
    }
}

<?php

namespace App\Orders;

/** The behavior needed by admin status controls and customer order tracking. */
interface OrderWorkflow
{
    // Flow: Define the contract for a payment method's allowed order statuses.
    public function statuses(): array;

    // Flow: Define the contract for valid transitions from the current order status.
    public function availableStatuses(string $currentStatus): array;

    // Flow: Define the contract for customer tracking information.
    public function trackingState(string $status): array;
}

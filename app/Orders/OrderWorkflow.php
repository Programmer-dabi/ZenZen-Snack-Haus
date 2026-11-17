<?php

namespace App\Orders;

/** The behavior needed by admin status controls and customer order tracking. */
// The existing interface lists the three methods used by administrator status controls and customer tracking.
// Payment workflows provide these methods through the shared abstract class and their own configuration.
interface OrderWorkflow
{
    // Flow: Define the contract for a payment method's allowed order statuses.
    public function statuses(): array;

    // Flow: Define the contract for valid transitions from the current order status.
    public function availableStatuses(string $currentStatus): array;

    // Flow: Define the contract for customer tracking information.
    public function trackingState(string $status): array;
}

<?php

namespace App\Orders;

final class OrderWorkflowFactory
{
    // Flow: Select the workflow implementation for the stored payment method.
    public static function forPayment(string $method): OrderWorkflow
    {
        // Select using the stored payment code; the default intentionally retains pickup handling for older values.
        return match ($method) {
            'gcash' => new GcashWorkflow(),
            'cash_on_delivery' => new CashOnDeliveryWorkflow(),
            // Preserve pickup behavior for legacy or unknown stored methods.
            default => new StorePickupWorkflow(),
        };
    }
}

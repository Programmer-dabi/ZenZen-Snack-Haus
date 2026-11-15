<?php
namespace App\Orders;

/** Shared status normalization and progression with payment-specific hooks. */
abstract class AbstractOrderWorkflow implements OrderWorkflow
{
    // Flow: Require each payment workflow to define its tracking steps.
    abstract protected function trackingSteps(): array;

    // Flow: Require each payment workflow to define payment-specific progress states.
    abstract protected function progressStates(): array;

    // Flow: Provide the default readiness status used by pickup orders.
    protected function readyStatus(): string
    {
        return 'Ready for pickup';
    }

    // Flow: Provide the default delivery status for historical status normalization.
    protected function deliveryStatus(): string
    {
        return 'Delivery';
    }

    // Flow: Keep the stored status label unless a concrete workflow specializes it.
    protected function displayStatus(string $status, string $normalized): string
    {
        return $status;
    }

    // Flow: Normalize the current status and prevent backward or terminal-state changes.
    final public function availableStatuses(string $currentStatus): array
    {
        $normalized = $this->normalize($currentStatus);
        $current = match ($normalized) {
            'pending', 'placed order' => 'Placed order',
            'paid', 'payed' => 'Payed',
            'ready', 'ready for pickup', 'ready to pickup' => $this->readyStatus(),
            'delivery', 'on delivery', 'out for delivery' => $this->deliveryStatus(),
            'complete', 'completed', 'picked up', 'collected' => 'Completed',
            'cancelled', 'canceled' => 'Cancelled',
            default => ucfirst($normalized),
        };
        if (in_array($current, ['Completed', 'Cancelled'], true)) {
            return [$current];
        }
        $statuses = $this->statuses();
        $position = array_search($current, $statuses, true);
        // Unknown historical statuses cannot safely be ranked.
        return $position === false ? [$currentStatus] : array_slice($statuses, $position);
    }

    // Flow: Build the status, progress, and labels used by tracking HTML and JSON.
    final public function trackingState(string $status): array
    {
        $steps = $this->trackingSteps();
        $normalized = $this->normalize($status);
        $key = match ($normalized) {
            'ready for pickup', 'ready to pickup' => 'ready',
            'paid' => 'payed',
            'on delivery', 'out for delivery' => 'delivery',
            'complete', 'picked up', 'collected' => 'completed',
            'canceled' => 'cancelled',
            default => $normalized,
        };
        $states = [
            'placed order' => [1, 'Your order has been placed and is awaiting confirmation.', 'placed_order'],
            'confirmed' => [2, 'We have received your order.', 'confirmed'],
            'pending' => [1, 'We have received your order.', 'pending'],
            'completed' => [count($steps), 'Your order is complete.', 'completed'],
            'cancelled' => [0, 'Your order has been cancelled.', 'cancelled'],
        ];
        $states = array_merge($states, $this->progressStates());
        [$step, $heading, $class] = $states[$key] ?? [0, 'Your order status', ''];

        return [
            'status' => $this->displayStatus($status, $normalized),
            'progress_step' => $step,
            'tracking_heading' => $heading,
            'status_class' => $class,
            'tracking_steps' => $steps,
        ];
    }

    // Flow: Normalize historical capitalization, separators, and pickup spelling.
    private function normalize(string $status): string
    {
        $normalized = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $status)));
        return str_replace('pick up', 'pickup', $normalized);
    }
}

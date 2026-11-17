<?php

namespace App\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Middleware\AuthMiddleware;
use App\Orders\OrderWorkflowFactory;
use DateTimeImmutable;
use DateTimeZone;
use Flight;
use InvalidArgumentException;
use Throwable;

class OrderController
{
    // Flow: Validate checkout details and its CSRF token before creating the order.
    public function place(): void
    {
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin(str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'))) {
            return;
        }

        // Step 1: Read customer details and prepare field-specific validation errors.
        $values = [];
        foreach ([
            'name',
            'phone_number',
            'email',
            'payment_method',
            'pickup_at',
            'notes',
            'barangay',
            'street',
            'house_no'
        ] as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field], " \t\n\r\v") : '';
        }
        $errors = [];
        // Require a customer name within the supported character limit.
        if ($values['name'] === '') {
            $errors['name'] = 'Full name is required.';
        } elseif (mb_strlen($values['name']) > 100) {
            $errors['name'] = 'Full name must be 100 characters or fewer.';
        }
        // Allow common phone punctuation, then require 7 to 15 digits after removing that punctuation.
        if ($values['phone_number'] === '') {
            $errors['phone_number'] = 'Contact number is required.';
        } elseif (strlen($values['phone_number']) > 20 || !preg_match('/\A\+?[0-9 ()-]+\z/', $values['phone_number']) || !preg_match('/\A[0-9]{7,15}\z/', preg_replace('/\D/', '', $values['phone_number']))) {
            $errors['phone_number'] = 'Enter a valid contact number with 7 to 15 digits.';
        }
        // Require a valid email within the database field length.
        if ($values['email'] === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (strlen($values['email']) > 150 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address (150 characters or fewer).';
        }
        // Only the configured payment methods can be saved with a new order.
        if (!array_key_exists($values['payment_method'], Order::PAYMENT_METHODS)) {
            $errors['payment_method'] = 'Choose a valid mode of payment.';
        }
        // Add field-specific delivery errors only for payment methods that require an address.
        $errors = array_merge($errors, Order::deliveryAddressErrors($values));
        $pickup = null;
        // Pickup orders use a future pickup time; delivery orders use an address instead.
        if ($values['payment_method'] === 'store_pickup') {
            // Clear delivery fields for pickup so unused address values are not carried into the order.
            foreach (Order::ADDRESS_FIELDS as $field => $settings) {
                $values[$field] = '';
            }
            // Interpret the browser datetime-local value in the store timezone, not the server default timezone.
            $timezone = new DateTimeZone('Asia/Manila');
            // Parse only the exact year-month-day and hour-minute format accepted by the checkout field.
            $pickup = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $values['pickup_at'])
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $values['pickup_at'], $timezone)
                : false;
            // Check parser warnings and a round trip of the date so impossible calendar dates are rejected.
            $dateErrors = DateTimeImmutable::getLastErrors();
            // Distinguish missing, invalid, and past pickup times so the form can show a useful message.
            if ($values['pickup_at'] === '') {
                $errors['pickup_at'] = 'Pickup date and time are required.';
            } elseif (!$pickup || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $pickup->format('Y-m-d\TH:i') !== $values['pickup_at']) {
                $errors['pickup_at'] = 'Enter a valid pickup date and time.';
            } elseif ($pickup <= new DateTimeImmutable('now', $timezone)) {
                $errors['pickup_at'] = 'Choose a pickup date and time in the future.';
            }
        } else {
            $values['pickup_at'] = '';
        }
        // Limit optional notes before they reach the database.
        if (mb_strlen($values['notes']) > 2000) {
            $errors['notes'] = 'Order notes must be 2,000 characters or fewer.';
        }
        // Step 2: Reject submissions that do not match the session's cart CSRF token.
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !isset($_SESSION['cart_csrf']) || !hash_equals($_SESSION['cart_csrf'], $token)) {
            $errors['form'] = 'Your checkout form has expired. Please review your details and submit again.';
        }
        // Preserve entered values and return to checkout whenever any validation fails.
        if ($errors !== []) {
            $this->returnToCheckout($values, $errors);
            return;
        }

        try {
            // Convert only the validated pickup time to the database timestamp format.
            $details = $values;
            $details['pickup_at'] = $pickup ? $pickup->format('Y-m-d H:i:s') : null;
            // Step 3: Save the validated order through the shared PDO model connection.
            $order = (new Order())->create($details);
            // Step 4: Clear temporary form values and redirect to the saved confirmation.
            unset($_SESSION['checkout_values'], $_SESSION['checkout_errors']);
            Flight::redirect('/order-success/' . $order['order_number'], 303);
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            $this->returnToCheckout($values, ['cart' => $error->getMessage()]);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Order placement failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            $this->returnToCheckout($values, ['form' => 'Could not place your order. Please try again.']);
        }
    }

    // Flow: Check the order number and display only the current customer's confirmation.
    public function success(string $number): void
    {
        try {
            // Reject malformed order references before querying customer-owned orders.
            if (!preg_match('/\AKP-[A-F0-9]{12}\z/', $number)) {
                Flight::halt(404, 'Order not found.');
                return;
            }
            // The model searches this reference within the signed-in customer account.
            $order = (new Order())->getByNumber($number);
            // Return not found for both missing orders and orders outside the current account.
            if ($order === null) {
                Flight::halt(404, 'Order not found.');
                return;
            }
            // Turn a stored pickup timestamp into a date object for Twig; delivery orders keep null.
            $order['pickup_time'] = $order['pickup_at'] !== null ? new DateTimeImmutable($order['pickup_at'], new DateTimeZone('Asia/Manila')) : null;
            // Render the saved order snapshot rather than reusing submitted checkout values.
            Flight::render('order-success.twig', [
                'order' => $order,
                'cart_count' => (new Cart())->getCount(),
            ]);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Order confirmation failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load your order confirmation. Please try again.');
        }
    }

    // Flow: Validate the order ID and display tracking using its payment workflow.
    public function tracking(string $id): void
    {
        // Reject non-positive order IDs before a tracking query is attempted.
        $orderId = filter_var($id, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            Flight::halt(404, 'Order not found.');
            return;
        }

        try {
            // Load a full order only when it belongs to the current customer.
            $order = (new Order())->getById($orderId);
            // Return not found for both missing orders and orders outside the current account.
            if ($order === null) {
                Flight::halt(404, 'Order not found.');
                return;
            }
            // Turn a stored pickup timestamp into a date object for Twig; delivery orders keep null.
            $order['pickup_time'] = $order['pickup_at'] !== null ? new DateTimeImmutable($order['pickup_at'], new DateTimeZone('Asia/Manila')) : null;
            // Choose the payment-specific status labels and tracking steps used by both HTML and polling JSON.
            $workflow = OrderWorkflowFactory::forPayment($order['payment_method']);
            // Combine the saved order with the workflow progress labels for the tracking page.
            Flight::render('tracking.twig', array_merge($workflow->trackingState($order['status']), [
                'order' => $order,
                'cart_count' => (new Cart())->getCount(),
            ]));
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Order tracking failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load your order. Please try again.');
        }
    }

    // Flow: Return private tracking JSON for an order owned by the current customer.
    public function trackingStatus(string $id): void
    {
        // Prevent shared caches from retaining private customer tracking data.
        Flight::response()->header('Cache-Control', 'no-store, private');
        // Reject non-positive order IDs before a tracking query is attempted.
        $orderId = filter_var($id, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            Flight::json(['success' => false, 'message' => 'Order not found.'], 404);
            return;
        }
        try {
            // Fetch only the status and payment method needed for the private polling response.
            $order = (new Order())->getTrackingStatus($orderId);
            // Return not found for both missing orders and orders outside the current account.
            if ($order === null) {
                Flight::json(['success' => false, 'message' => 'Order not found.'], 404);
                return;
            }
            // Choose the payment-specific status labels and tracking steps used by both HTML and polling JSON.
            $workflow = OrderWorkflowFactory::forPayment($order['payment_method']);
            // Send the same progress data used by the page so JavaScript can update it consistently.
            Flight::json(array_merge(['success' => true], $workflow->trackingState($order['status'])));
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Order status refresh failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::json(['success' => false, 'message' => 'Could not refresh your order status.'], 500);
        }
    }

    // Flow: Store entered values and validation errors in the session before redirecting.
    private function returnToCheckout(array $values, array $errors): void
    {
        $_SESSION['checkout_values'] = $values;
        $_SESSION['checkout_errors'] = $errors;
        Flight::redirect('/checkout', 303);
    }
}

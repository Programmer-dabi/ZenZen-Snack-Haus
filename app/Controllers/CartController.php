<?php

namespace App\Controllers;

use App\Models\Cart;
use App\Models\User;
use App\Models\Order;
use App\Middleware\AuthMiddleware;
use Flight;
use InvalidArgumentException;
use Throwable;

class CartController
{
    // Flow: Require login, load cart totals, and prepare a CSRF-protected cart page.
    public function index(): void
    {
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin()) {
            return;
        }

        try {
            // Read saved cart items and calculate the display totals from their server-stored prices.
            $data = $this->cartData(new Cart());

            // Create a form token once per session so cart and checkout POSTs can be verified.
            if (!isset($_SESSION['cart_csrf'])) {
                $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
            }
            // Consume a one-time message from an earlier failed cart change.
            $error = $_SESSION['cart_error'] ?? null;
            unset($_SESSION['cart_error']);

            // Send the cart items, totals, form token, and any saved error to Twig.
            Flight::render('cart.twig', array_merge($data, [
                'csrf_token' => $_SESSION['cart_csrf'],
                'cart_error' => $error,
            ]));
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Cart display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load your cart. Please try again.');
        }
    }

    // Flow: Require login and prepare customer details and the checkout form.
    public function checkout(): void
    {
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin()) {
            return;
        }

        try {
            // Read saved cart items and calculate the display totals from their server-stored prices.
            $data = $this->cartData(new Cart());
            // Send an empty cart back to the cart page, but allow checkout errors to remain visible.
            if ($data['items'] === [] && empty($_SESSION['checkout_errors'])) {
                // Use a redirect after a form POST so refreshing the page does not submit it again.
                Flight::redirect('/cart');
                return;
            }

            // Use the signed-in profile to prefill contact details without accepting a different customer ID.
            $customer = (new User())->getById((int) $_SESSION['user']);
            // Create a form token once per session so cart and checkout POSTs can be verified.
            if (!isset($_SESSION['cart_csrf'])) {
                $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
            }
            // Restore a failed checkout submission; otherwise start with profile details and pickup defaults.
            $values = $_SESSION['checkout_values'] ?? [
                'name' => $customer['name'] ?? '',
                'phone_number' => $customer['phone_number'] ?? '',
                'email' => $customer['email'] ?? '',
                'payment_method' => 'store_pickup',
                'pickup_at' => '',
                'notes' => '',
                'barangay' => '',
                'street' => '',
                'house_no' => '',
            ];
            // Read field errors once, then clear temporary session form data.
            $errors = $_SESSION['checkout_errors'] ?? [];
            unset($_SESSION['checkout_values'], $_SESSION['checkout_errors']);

            // Pass payment/address settings and the earliest pickup minute to the checkout template.
            Flight::render('checkout.twig', array_merge($data, [
                'customer' => $customer,
                'values' => $values,
                'errors' => $errors,
                'csrf_token' => $_SESSION['cart_csrf'],
                'payment_methods' => Order::PAYMENT_METHODS,
                'address_fields' => Order::ADDRESS_FIELDS,
                'delivery_city' => Order::DELIVERY_CITY,
                'pickup_min' => (new \DateTimeImmutable('+1 minute', new \DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i'),
            ]));
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Checkout display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load checkout. Please try again.');
        }
    }

    // Flow: Send an item quantity request through the shared cart validation flow.
    public function updateQuantity(string $id): void
    {
        $this->change($id, false);
    }

    // Flow: Send an item removal request through the shared cart validation flow.
    public function remove(string $id): void
    {
        $this->change($id, true);
    }

    // Flow: Check login, CSRF, item ID, and quantity before changing the cart.
    private function change(string $id, bool $remove): void
    {
        // Use the Accept header to support both JavaScript updates and ordinary form submissions.
        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin($wantsJson)) {
            return;
        }

        // Compare the posted CSRF token with the session before allowing any cart mutation.
        $token = $_POST['csrf_token'] ?? null;
        // Reject an invalid form token using the response format expected by the caller.
        if (!is_string($token) || !isset($_SESSION['cart_csrf']) || !hash_equals($_SESSION['cart_csrf'], $token)) {
            // JavaScript receives JSON directly; normal forms continue to the cart redirect.
            if ($wantsJson) {
                Flight::json(['success' => false, 'message' => 'Please reload your cart and try again.'], 403);
                return;
            }
            Flight::halt(403, 'Please reload your cart and try again.');
            return;
        }

        try {
            // Require a positive saved cart item ID; the model checks customer ownership as well.
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('Invalid cart item.');
            }
            $cart = new Cart();
            // The same request checks protect removal and quantity changes; only their model operation differs.
            if ($remove) {
                $cart->deleteFromCart((int) $id);
            } else {
                // Only whole quantities from 1 to 99 are accepted by this quantity endpoint.
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 1 || $quantity > 99) {
                    throw new InvalidArgumentException('Quantity must be between 1 and 99.');
                }
                $cart->updateQuantity((int) $id, $quantity);
            }
            // JavaScript receives JSON directly; normal forms continue to the cart redirect.
            if ($wantsJson) {
                Flight::json(array_merge(['success' => true], $this->cartData($cart)));
                return;
            }
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            // JavaScript receives JSON directly; normal forms continue to the cart redirect.
            if ($wantsJson) {
                Flight::json(['success' => false, 'message' => $error->getMessage()], 422);
                return;
            }
            $_SESSION['cart_error'] = $error->getMessage();
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Cart update failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            // JavaScript receives JSON directly; normal forms continue to the cart redirect.
            if ($wantsJson) {
                Flight::json([
                    'success' => false,
                    'message' => 'Could not update your cart. Please try again.'
                ], 500);
                return;
            }
            $_SESSION['cart_error'] = 'Could not update your cart. Please try again.';
        }
        // Use a redirect after a form POST so refreshing the page does not submit it again.
        Flight::redirect('/cart', 303);
    }

    // Flow: Calculate display totals from server-stored cart prices.
    private function cartData(Cart $cart): array
    {
        $items = $cart->getItems();
        $subtotalCentavos = 0;
        // Calculate each line in integer centavos before adding it to the cart subtotal.
        foreach ($items as & $item) {
            $lineCentavos = (int) round($item['unit_price'] * 100) * $item['quantity'];
            $item['line_total'] = $lineCentavos / 100;
            $subtotalCentavos += $lineCentavos;
        }
        // End the reference to the last item so later assignments cannot accidentally change it.
        unset($item);

        // Keep items keyed by database ID and report the total number of units, not just the number of lines.
        return [
            'items' => $items,
            'cart_count' => array_sum(array_column($items, 'quantity')),
            'subtotal' => $subtotalCentavos / 100,
            'total' => $subtotalCentavos / 100,
        ];
    }
}

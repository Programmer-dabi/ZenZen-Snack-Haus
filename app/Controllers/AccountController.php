<?php

namespace App\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Middleware\AuthMiddleware;
use Flight;
use InvalidArgumentException;
use Throwable;

class AccountController
{
    // Flow: Check the customer session and load the profile and order history.
    public function index(): void
    {
        // Check the customer session ID before loading any private account information.
        $userId = filter_var($_SESSION['user'] ?? null, FILTER_VALIDATE_INT);
        // Guests and invalid sessions return to sign-in instead of querying a customer profile.
        if ($userId === false || $userId < 1) {
            Flight::redirect('/login');
            return;
        }

        try {
            // Retrieve the profile for this session, not an ID submitted by the browser.
            $customer = (new User())->getById($userId);
            // Clear a stale customer session when its database account no longer exists.
            if ($customer === null) {
                unset($_SESSION['user']);
                Flight::redirect('/login');
                return;
            }
            // Split the customer name into words to build the profile avatar initials.
            $names = preg_split('/\s+/u', trim($customer['name']), -1, PREG_SPLIT_NO_EMPTY);
            $initials = '';
            // Use the first and last words when available, keeping Unicode name characters intact.
            if ($names) {
                $initials = mb_substr($names[0], 0, 1);
                if (count($names) > 1) {
                    $initials .= mb_substr($names[count($names) - 1], 0, 1);
                }
            }
            // Create a session token that protects cancellation forms from cross-site submissions.
            $_SESSION['account_csrf'] ??= bin2hex(random_bytes(32));
            // Read one-time messages left by cancellation, then remove them after this page request.
            $notice = $_SESSION['account_notice'] ?? null;
            $accountError = $_SESSION['account_error'] ?? null;
            unset($_SESSION['account_notice'], $_SESSION['account_error']);
            // Render profile details and only the orders owned by the current customer.
            Flight::render('account.twig', [
                'customer' => $customer,
                'initials' => mb_strtoupper($initials),
                'orders' => (new Order())->getForCurrentUser(),
                'cart_count' => (new Cart())->getCount(),
                'csrf_token' => $_SESSION['account_csrf'],
                'account_notice' => $notice,
                'account_error' => $accountError,
            ]);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Account display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load your account. Please try again.');
        }
    }

    // Flow: Validate the cancellation request and delegate ownership checks to Order.
    public function cancelOrder(string $id): void
    {
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin()) {
            return;
        }
        // Validate the cancellation form token before interpreting the requested order ID.
        $token = $_POST['csrf_token'] ?? null;
        // Stop with a forbidden response when the form token is missing or expired.
        if (!is_string($token) || !isset($_SESSION['account_csrf']) || !hash_equals($_SESSION['account_csrf'], $token)) {
            Flight::halt(403, 'Please reload your account and try again.');
            return;
        }
        try {
            // Require a positive order ID; the model will additionally check ownership and cancellation rules.
            $orderId = filter_var($id, FILTER_VALIDATE_INT);
            if ($orderId === false || $orderId < 1) {
                throw new InvalidArgumentException('Invalid order ID.');
            }
            // Cancellation runs with a locked order and restores reserved inventory only once.
            (new Order())->cancelForCurrentUser($orderId);
            $_SESSION['account_notice'] = 'Your order has been cancelled.';
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            $_SESSION['account_error'] = $error->getMessage();
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Order cancellation failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            $_SESSION['account_error'] = 'Could not cancel your order. Please try again.';
        }
        // Return to the order list, where the saved success or error message is displayed once.
        Flight::redirect('/account#orders', 303);
    }
}

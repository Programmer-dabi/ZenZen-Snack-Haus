<?php

namespace App\Middleware;

class AuthMiddleware
{
    // Flow: Check that the session contains a positive customer ID.
    public static function isLoggedIn(): bool
    {
        // Treat a session as signed in only when it contains a positive integer customer ID.
        $userId = filter_var($_SESSION['user'] ?? null, FILTER_VALIDATE_INT);
        return $userId !== false && $userId > 0;
    }

    // Flow: Allow signed-in customers or return the login redirect/JSON response.
    public static function requireLogin(bool $json = false): bool
    {
        // Stop here when the customer already has a valid session ID.
        if (self::isLoggedIn()) {
            return true;
        }

        // JavaScript callers need a 401 JSON reply; normal page requests need a redirect to sign-in.
        if ($json) {
            \Flight::json([
                'success' => false,
                'message' => 'Please sign in to add items to your cart or place an order.',
                'redirect_url' => '/login',
            ], 401);
        } else {
            \Flight::redirect('/login', 303);
        }
        // Tell the controller to stop, so protected work does not continue after the response.
        return false;
    }
}
?>

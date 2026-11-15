<?php

namespace App\Middleware;

class AuthMiddleware
{
    // Flow: Check that the session contains a positive customer ID.
    public static function isLoggedIn(): bool
    {
        $userId = filter_var($_SESSION['user'] ?? null, FILTER_VALIDATE_INT);
        return $userId !== false && $userId > 0;
    }

    // Flow: Allow signed-in customers or return the login redirect/JSON response.
    public static function requireLogin(bool $json = false): bool
    {
        if (self::isLoggedIn()) {
            return true;
        }

        if ($json) {
            \Flight::json([
                'success' => false,
                'message' => 'Please sign in to add items to your cart or place an order.',
                'redirect_url' => '/login',
            ], 401);
        } else {
            \Flight::redirect('/login', 303);
        }
        return false;
    }

    // Flow: Apply the login requirement when this middleware is attached to a route group.
    public function before()
    {
        return self::requireLogin();
    }
}
?>

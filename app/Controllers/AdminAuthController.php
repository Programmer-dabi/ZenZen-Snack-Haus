<?php

namespace App\Controllers;

use App\Models\User;
use Flight;
use Throwable;

class AdminAuthController
{
    // Flow: Check an existing administrator session before showing the sign-in form.
    public function login(): void
    {
        try {
            // Validate the separate administrator session ID before looking up its current database role.
            $id = filter_var($_SESSION['admin_user'] ?? null, FILTER_VALIDATE_INT);
            // Redirect only if that account still exists and still has the administrator role.
            if ($id !== false && $id > 0 && (new User())->getAdministrator($id) !== null) {
                Flight::redirect('/admin', 303);
                return;
            }
            // Clear administrator login state and its form token before returning to sign-in.
            unset($_SESSION['admin_user'], $_SESSION['admin_csrf']);
            $this->renderLogin();
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Admin login display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load administrator sign-in. Please try again.');
        }
    }

    // Flow: Create the login CSRF token and render the administrator form.
    private function renderLogin(?string $error = null, string $email = ''): void
    {
        // Ask browsers and proxies not to cache this private sign-in page.
        Flight::response()->header('Cache-Control', 'no-store, private');
        // Reuse a random form token for this session; it will be checked on submission.
        $_SESSION['admin_login_csrf'] ??= bin2hex(random_bytes(32));
        // Send the form token, retained email, and optional error to Twig.
        Flight::render('admin-login.twig', [
            'error' => $error,
            'email' => $email,
            'csrf_token' => $_SESSION['admin_login_csrf'],
        ]);
    }

    // Flow: Validate the admin form, verify the password and role, then store the session.
    public function authenticate(): void
    {
        // Read form fields carefully because a client can submit arrays instead of strings.
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $password = $_POST['password'] ?? null;
        // Read the form token used to protect administrator sign-in or sign-out.
        $token = $_POST['csrf_token'] ?? null;
        try {
            // Reject submissions whose CSRF token does not match this session.
            if (!is_string($token) || !isset($_SESSION['admin_login_csrf']) || !hash_equals($_SESSION['admin_login_csrf'], $token)) {
                $this->renderLogin('Your sign-in form expired. Please try again.', $email);
                return;
            }
            // Limit input sizes and require a valid email plus a nonempty password before account lookup.
            if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' || strlen($password) > 4096) {
                $this->renderLogin('Enter a valid email address and password.', $email);
                return;
            }
            // Step: Check the password hash and the persisted administrator role.
            $user = (new User())->getByEmail($email);
            // Use one failure message for unknown accounts, incorrect passwords, and accounts without the admin role.
            if ($user === null || !password_verify($password, $user['password']) || $user['role'] !== 'admin') {
                $this->renderLogin('Invalid administrator email or password.', $email);
                return;
            }
            // Step: Renew the session ID before storing administrator login state.
            // Replace the session ID so an older session identifier cannot be reused for this login state.
            session_regenerate_id(true);
            // Step: Store only the administrator ID and a fresh form token in the session.
            $_SESSION['admin_user'] = (int) $user['id'];
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            // Discard the used login token and old form messages after successful administrator sign-in.
            unset($_SESSION['admin_login_csrf'], $_SESSION['admin_error'], $_SESSION['admin_success'], $_SESSION['admin_product_values']);
            Flight::redirect('/admin', 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Admin login failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            $this->renderLogin('Could not sign in. Please try again.', $email);
        }
    }

    // Flow: Check the logout CSRF token and remove administrator session values.
    public function logout(): void
    {
        // Read the form token used to protect administrator sign-in or sign-out.
        $token = $_POST['csrf_token'] ?? null;
        // Reject submissions whose CSRF token does not match this session.
        if (!is_string($token) || !isset($_SESSION['admin_csrf']) || !hash_equals($_SESSION['admin_csrf'], $token)) {
            Flight::halt(403, 'Please reload the admin page and try again.');
            return;
        }
        // Clear administrator login state and its form token before returning to sign-in.
        unset($_SESSION['admin_user'], $_SESSION['admin_csrf'], $_SESSION['admin_login_csrf'], $_SESSION['admin_error'], $_SESSION['admin_success'], $_SESSION['admin_product_values']);
        // Replace the session ID so an older session identifier cannot be reused for this login state.
        session_regenerate_id(true);
        Flight::redirect('/admin/login', 303);
    }
}

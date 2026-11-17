<?php

namespace App\Controllers;

use App\Models\User;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

class AuthenController
{
    private const REMEMBER_EMAIL_COOKIE = 'zz_remembered_email';

    private function renderLogin(array $data = []): void
    {
        $email = $_COOKIE[self::REMEMBER_EMAIL_COOKIE] ?? '';
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        \Flight::render('login.twig', array_merge([
            'email' => $email,
            'remember_me' => $email !== '',
        ], $data));
    }

    private function rememberEmail(string $email, bool $remember): void
    {
        // Store only the email preference; passwords belong in a password manager.
        setcookie(self::REMEMBER_EMAIL_COOKIE, $remember ? $email : '', [
            'expires' => $remember ? time() + 30 * 24 * 60 * 60 : time() - 3600,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($remember) {
            $_COOKIE[self::REMEMBER_EMAIL_COOKIE] = $email;
        } else {
            unset($_COOKIE[self::REMEMBER_EMAIL_COOKIE]);
        }
    }

    // Flow: Show the customer login form, or redirect an already signed-in customer.
    public function login()
    {
        if (isset($_SESSION['user'])) {
            \Flight::redirect('/');
            return;
        }
        $this->renderLogin();
    }

    // Flow: Show the customer registration form.
    public function register()
    {
        \Flight::render('register.twig', []);

    }
    // Flow: Clear session values and destroy the customer session.
    public function logout()
    {
        session_unset();
        session_destroy();
        \Flight::redirect('/login');
    }
    // Flow: Check registration fields and password confirmation, then request account storage.
    public function addUser()
    {
        if (isset($_POST['email']) && isset($_POST['name']) && isset($_POST['phonenum']) && isset($_POST['password']) && isset($_POST['confpassword'])) {
            if ($_POST['password'] !== $_POST['confpassword']) {
                \Flight::render('register.twig', [
                    'err' => 'Password Doesnt Match'
                ]);
                return;
            }
            $email = $_POST['email'];
            $name = $_POST['name'];
            $phonenum = $_POST['phonenum'];
            $password = $_POST['password'];

            try {
                $user = new User();
                $user->register($name, $email, $phonenum, $password);

                // echo 'HELLOO???';
                // die();
                $this->renderLogin(['succ' => 'Registration Complete']);
            } catch (\Exception $e) {
                echo '<pre>';
                echo $e->getMessage();
                echo '</pre>';

                die();
            }
        } else {
            \Flight::render('register.twig', [
                'err' => 'Error sir'
            ]);
        }
    }

    // Flow: This is the part where the customer logs in using an email and password.
    public function Authent()
    {
        // Step 1: Read and validate the submitted customer login fields.
        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;
        $remember = ($_POST['remember_me'] ?? '') === '1';
        $form = ['email' => is_string($email) ? trim($email) : '', 'remember_me' => $remember];
        if (!$remember) {
            $this->rememberEmail('', false);
        }
        if (!is_string($email) || !is_string($password) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->renderLogin($form + ['err' => 'Enter a valid email address and password.']);
            return;
        }

        try {
            // Step 2: Look up the account and verify the submitted password against its hash.
            $user = (new User())->getByEmail(trim($email));
            if ($user === null || !password_verify($password, $user['password'])) {
                $this->renderLogin($form + ['err' => 'Invalid email address or password.']);
                return;
            }

            // Step 3: Replace the session ID after a successful login.
            session_regenerate_id(true);
            // Step 4: Store the customer ID in the session; never store the password.
            $_SESSION['user'] = (int) $user['id'];
            $this->rememberEmail(trim($email), $remember);
            // Step 5: Discard the old cart form token and redirect to the storefront.
            unset($_SESSION['cart_csrf']);
            \Flight::redirect('/', 303);
        } catch (\Throwable $error) {
            error_log('Login failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            $this->renderLogin($form + ['err' => 'Could not sign in. Please try again.']);
        }
    }

}
?>

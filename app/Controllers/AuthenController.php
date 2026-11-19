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
        // Read the saved email preference only; this cookie does not authenticate the customer.
        $email = $_COOKIE[self::REMEMBER_EMAIL_COOKIE] ?? '';
        // Ignore malformed cookies instead of passing unexpected values to the login template.
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        // Merge defaults with the submitted email and any success or validation message.
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
        // Keep the current request consistent with the cookie value sent to the browser.
        if ($remember) {
            $_COOKIE[self::REMEMBER_EMAIL_COOKIE] = $email;
        } else {
            unset($_COOKIE[self::REMEMBER_EMAIL_COOKIE]);
        }
    }

    // Flow: Show the customer login form, or redirect an already signed-in customer.
    public function login()
    {
        // An existing customer session skips the login form and returns to the shop.
        if (isset($_SESSION['user'])) {
            \Flight::redirect('/');
            return;
        }
        $this->renderLogin();
    }

    // Flow: Show the customer registration form.
    public function register()
    {
        // Show the registration form, including a validation message when one is supplied.
        \Flight::render('register.twig', []);

    }
    // Flow: Clear session values and destroy the customer session.
    public function logout()
    {
        // Clear session values and end this session before returning to customer sign-in.
        session_unset();
        session_destroy();
        \Flight::redirect('/login');
    }
    // Flow: Check registration fields and password confirmation, then request account storage.
    public function addUser()
    {
        // Only attempt registration when all expected form fields are present.
        if (isset($_POST['email']) && isset($_POST['name']) && isset($_POST['phonenum']) && isset($_POST['password']) && isset($_POST['confpassword'])) {
            // Reject a mismatched confirmation before asking the model to store the account.
            if ($_POST['password'] !== $_POST['confpassword']) {
                // Show the registration form, including a validation message when one is supplied.
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
                // The User model hashes the password and inserts the account using a prepared statement.
                
                if($user->getByEmail($email) != null){
                 \Flight::render('login.twig', [
                    'err' => 'User Exist please log in'
                ]);
                return;
                }
                $user->register($name, $email, $phonenum, $password);

                $this->renderLogin(['succ' => 'Registration Complete']);
            // This existing registration error path prints the exception and ends the request.
            } catch (\Exception $e) {
                \Flight::render('register.twig', [
                'err' => 'Error sir '.$e ,
            ]);
            }
        } else {
            // Show the registration form, including a validation message when one is supplied.
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
        // Read whether the customer wants the email remembered and retain form values after an error.
        $remember = ($_POST['remember_me'] ?? '') === '1';
        $form = ['email' => is_string($email) ? trim($email) : '', 'remember_me' => $remember];
        // Clear any previous remembered email when the checkbox is not selected.
        if (!$remember) {
            $this->rememberEmail('', false);
        }
        // Reject malformed login fields before querying the account or verifying its password.
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
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (\Throwable $error) {
            error_log('Login failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            // Return to the login form with a generic message when an unexpected error occurs.
            $this->renderLogin($form + ['err' => 'Could not sign in. Please try again.']);
        }
    }

}
?>

<?php

namespace App\Models;

use PDO;

class User extends Model
{
    // Flow: Find the current customer profile using a parameterized user ID.
    public function getById(int $id): ?array
    {
        // Retrieve only the profile fields needed for the customer account page. The placeholder is the user ID.
        $stmt = $this->connection()->prepare('SELECT name, email, phone_number FROM users WHERE id = ?');
        // Supply the requested ID separately from SQL so PDO handles it as a value.
        $stmt->execute([$id]);
        // Fetch one matching record as an associative array, or use null when there is no match.
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release the result so the connection can be used for the next query.
        $stmt->closeCursor();

        return $user ?: null;
    }

    // Flow: Look up the stored password hash and role for login verification.
    public function getByEmail(string $email): ?array
    {
        // Find the ID, password hash, and role for one email address; password verification happens in the controller.
        $stmt = $this->connection()->prepare('SELECT id, password, role FROM users WHERE email = ?');
        // Bind the submitted email to the placeholder without building it into the SQL text.
        $stmt->execute([$email]);
        // Fetch one matching record as an associative array, or use null when there is no match.
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release the result so the connection can be used for the next query.
        $stmt->closeCursor();

        return $user ?: null;
    }

    // Flow: Confirm that the account still has the administrator role.
    public function getAdministrator(int $id): ?array
    {
        // Recheck the stored admin role as well as the ID, so a stale session cannot grant administrator access.
        $stmt = $this->connection()->prepare("SELECT id, name, email, phone_number FROM users WHERE id = ? AND role = 'admin'");
        // Supply the requested ID separately from SQL so PDO handles it as a value.
        $stmt->execute([$id]);
        // Fetch one matching record as an associative array, or use null when there is no match.
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release the result so the connection can be used for the next query.
        $stmt->closeCursor();
        return $user ?: null;
    }

    // Flow: Hash the password and store a customer account with a prepared INSERT.
    public function register(string $name, string $email, string $phoneNumber, string $password): bool
    {
        // Step 1: Turn the plain-text password into a one-way hash before storage.
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        // New registrations always receive the customer role; callers cannot choose an administrator role.
        $sql = " INSERT INTO users (name, email, phone_number, password, role) VALUES (?, ?, ?, ?, 'customer')";

        // Prepare the insert with placeholders for the customer details and hashed password.
        $stmt = $this->connection()->prepare($sql);

        // Step 2: Send values separately from SQL; the database receives only the hash.
        return $stmt->execute([
            $name,
            $email,
            $phoneNumber,
            $hashedPassword
        ]);
    }

}

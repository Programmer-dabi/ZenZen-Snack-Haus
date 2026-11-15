<?php
namespace App\Models;

use PDO;

class User extends Model
{
    // Flow: Retrieve user records from the database.
    public function getUsers()
    {
        $sql = "SELECT * FROM users";

        $result = $this->connection()->query($sql);

        return $result->fetchAll(PDO::FETCH_ASSOC);
    }

    // Flow: Find the current customer profile using a parameterized user ID.
    public function getById(int $id): ?array
    {
        $stmt = $this->connection()->prepare('SELECT name, email, phone_number FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();

        return $user ?: null;
    }

    // Flow: Look up the stored password hash and role for login verification.
    public function getByEmail(string $email): ?array
    {
        $stmt = $this->connection()->prepare('SELECT id, password, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();

        return $user ?: null;
    }

    // Flow: Confirm that the account still has the administrator role.
    public function getAdministrator(int $id): ?array
    {
        $stmt = $this->connection()->prepare("SELECT id, name, email, phone_number FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $user ?: null;
    }

    // Flow: Change an existing account role and confirm that the account exists.
    public function makeAdministrator(int $id): void
    {
        $stmt = $this->connection()->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$id]);
        $stmt->closeCursor();
        if ($this->getAdministrator($id) === null) {
            throw new \InvalidArgumentException('Account not found.');
        }
    }

    // Flow: Validate administrator details, create the account, and assign its role atomically.
    public function createAdministrator(string $name, string $email, string $phoneNumber, string $password): int
    {
        $name = trim($name);
        $email = trim($email);
        $phoneNumber = trim($phoneNumber);
        if ($name === '' || mb_strlen($name) > 100 || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a name of up to 100 characters and a valid email address.');
        }
        if (strlen($phoneNumber) > 20 || !preg_match('/\A\+?[0-9 ()-]+\z/', $phoneNumber) || !preg_match('/\A[0-9]{7,15}\z/', preg_replace('/\D/', '', $phoneNumber))) {
            throw new \InvalidArgumentException('Contact number must contain 7 to 15 digits.');
        }
        if (mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('A new administrator password must have at least 12 characters and at most 72 bytes, without null characters.');
        }
        $this->connection()->beginTransaction();
        try {
            $this->register($name, $email, $phoneNumber, $password);
            $id = (int) $this->connection()->lastInsertId();
            $this->makeAdministrator($id);
            $this->connection()->commit();
            return $id;
        } catch (\Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

     // Flow: Hash the password and store a customer account with a prepared INSERT.
     public function register( string $name, string $email, string $phoneNumber, string $password):bool {
        // Step 1: Turn the plain-text password into a one-way hash before storage.
        $hashedPassword = password_hash( $password, PASSWORD_DEFAULT);
        $sql = " INSERT INTO users (name, email, phone_number, password, role) VALUES (?, ?, ?, ?, 'customer')";

        $stmt = $this->connection()->prepare($sql);

        // Step 2: Send values separately from SQL; the database receives only the hash.
        return $stmt->execute([$name,
            $email,
            $phoneNumber,
            $hashedPassword]);
    }

}

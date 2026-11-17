<?php

namespace App\Database;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;

// Step 1: Load Composer so the application can use Dotenv and its other libraries.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Step 2: Read database settings from .env without copying credentials into source code.
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

// This class reads connection settings and opens PDO connections; models contain the actual queries.
class Database
{
    private string $host;
    private string $username;
    private string $password;
    private string $database;

    // Step 3: Store the connection settings privately inside the database object.
    public function __construct()
    {
        // Read local environment settings rather than storing database credentials in application code.
        $this->host = $_ENV['DB_HOST'];
        $this->username = $_ENV['DB_USERNAME'];
        $this->password = $_ENV['DB_PASSWORD'];
        $this->database = $_ENV['DB_DATABASE'];
    }

    // Step 4: Open a PDO connection with UTF-8 and native prepared statements.
    public function getConnection(): PDO
    {
        try {
            // The DSN selects the MySQL database and UTF-8 character set.
            // Exceptions report failed queries; associative fetches use column names; native prepares keep values separate from SQL.
            return new PDO(
                'mysql:host=' . $this->host . ';dbname=' . $this->database . ';charset=utf8mb4',
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        // Replace driver details with a safe message for callers to handle.
        } catch (PDOException $error) {
            // Step 5: Report connection failure without exposing credentials or driver details.
            throw new RuntimeException('Database connection failed.');
        }
    }
}

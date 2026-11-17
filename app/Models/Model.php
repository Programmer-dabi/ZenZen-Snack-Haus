<?php

namespace App\Models;

use App\Database\Database;
use PDO;

/** Shared database infrastructure; concrete models own their domain queries. */
abstract class Model
{
    private readonly PDO $connection;

    // Flow: Use the supplied PDO connection, or open the configured application database.
    public function __construct(?PDO $db = null)
    {
        $this->connection = $db ?? (new Database())->getConnection();
    }

    // Subclasses can use the connection, but callers cannot access or replace it.
    // Flow: Let child models use the connection while keeping it hidden from controllers.
    final protected function connection(): PDO
    {
        return $this->connection;
    }
}

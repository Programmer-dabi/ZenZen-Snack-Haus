<?php

namespace App\Models;

use App\Database\Database;
use PDO;

/** Shared database infrastructure; concrete models own their domain queries. */
abstract class Model
{
    // Each model keeps one PDO connection; readonly prevents replacing it after construction.
    private readonly PDO $connection;

    // Flow: Use the supplied PDO connection, or open the configured application database.
    public function __construct(?PDO $db = null)
    {
        // An injected connection lets Order and Cart take part in the same transaction.
        // When no connection is supplied, open one using the application settings.
        $this->connection = $db ?? (new Database())->getConnection();
    }

    // Subclasses can use the connection, but callers cannot access or replace it.
    // Flow: Let child models use the connection while keeping it hidden from controllers.
    final protected function connection(): PDO
    {
        // Child models use this connection for their prepared queries and transactions.
        return $this->connection;
    }
}

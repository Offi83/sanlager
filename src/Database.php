<?php

namespace LagerApp;

use PDO;

class Database
{
    private PDO $connection;

    public function __construct(string $database)
    {
        $this->connection = new PDO(
            'sqlite:' . $database
        );

        $this->connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->connection->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $this->connection->exec('PRAGMA foreign_keys = ON');
    }

    public function connection(): PDO
    {
        return $this->connection;
    }
}

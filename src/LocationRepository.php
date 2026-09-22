<?php

namespace LagerApp;

use PDO;
use RuntimeException;

class LocationRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function all(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM storage_locations
                 WHERE active = 1
                 ORDER BY name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM storage_locations
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);

        $location = $statement->fetch();

        return $location ?: null;
    }

    public function create(string $name, string $description): int
    {
        if ($this->existsWithName($name)) {
            throw new RuntimeException(
                'Ein Lagerort mit diesem Namen existiert bereits.'
            );
        }

        $statement = $this->db->prepare(
            'INSERT INTO storage_locations
                (name, description, active)
             VALUES
                (:name, :description, 1)'
        );

        $statement->execute([
            'name' => $name,
            'description' => $description !== '' ? $description : null
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $description): void
    {
        if ($this->existsWithName($name, $id)) {
            throw new RuntimeException(
                'Ein Lagerort mit diesem Namen existiert bereits.'
            );
        }

        $statement = $this->db->prepare(
            'UPDATE storage_locations
             SET name = :name,
                 description = :description
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description !== '' ? $description : null
        ]);
    }

    public function deactivate(int $id): void
    {
        if ($this->activeCount() <= 1) {
            throw new RuntimeException(
                'Es muss mindestens ein aktiver Lagerort vorhanden bleiben.'
            );
        }

        $statement = $this->db->prepare(
            'UPDATE storage_locations
             SET active = 0
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);
    }

    public function activeCount(): int
    {
        return (int) $this->db
            ->query(
                'SELECT COUNT(*)
                 FROM storage_locations
                 WHERE active = 1'
            )
            ->fetchColumn();
    }

    private function existsWithName(string $name, ?int $excludeId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM storage_locations
             WHERE name = :name
             AND id != :exclude_id
             LIMIT 1'
        );

        $statement->execute([
            'name' => $name,
            'exclude_id' => $excludeId ?? 0
        ]);

        return $statement->fetchColumn() !== false;
    }
}

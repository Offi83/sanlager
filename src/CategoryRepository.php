<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Artikelkategorien (Tabelle `article_categories`).
 */
class CategoryRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Liefert alle aktiven Kategorien in ihrer per Drag & Drop
     * festgelegten Reihenfolge (`sort_order`).
     */
    public function all(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM article_categories
                 WHERE active = 1
                 ORDER BY sort_order, name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    /**
     * Liefert eine einzelne aktive Kategorie.
     */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM article_categories
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);

        $category = $statement->fetch();

        return $category ?: null;
    }

    /**
     * Legt eine neue Kategorie an und hängt sie ans Ende der Sortierung an.
     *
     * @throws RuntimeException wenn der Name bereits vergeben ist
     */
    public function create(
        string $name,
        string $shortName,
        string $color
    ): int {
        if ($this->existsWithName($name)) {
            throw new RuntimeException(
                'Eine Kategorie mit diesem Namen existiert bereits.'
            );
        }

        $statement = $this->db->prepare(
            'INSERT INTO article_categories
                (name, short_name, color, sort_order, active)
             VALUES
                (:name, :short_name, :color, :sort_order, 1)'
        );

        $statement->execute([
            'name' => $name,
            'short_name' => $shortName,
            'color' => $color,
            'sort_order' => $this->nextSortOrder()
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @throws RuntimeException wenn der Name bereits von einer anderen
     *                          Kategorie verwendet wird
     */
    public function update(
        int $id,
        string $name,
        string $shortName,
        string $color
    ): void {
        if ($this->existsWithName($name, $id)) {
            throw new RuntimeException(
                'Eine Kategorie mit diesem Namen existiert bereits.'
            );
        }

        $statement = $this->db->prepare(
            'UPDATE article_categories
             SET name = :name,
                 short_name = :short_name,
                 color = :color
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'short_name' => $shortName,
            'color' => $color
        ]);
    }

    /**
     * Löscht eine Kategorie unwiderruflich (Hard-Delete).
     *
     * Anders als bei Artikeln/Lagerorten ist das hier unbedenklich, da
     * `articles.category_id` per ON DELETE SET NULL abgesichert ist und
     * keine Lagerbewegungen an Kategorien hängen. Der Aufrufer sollte
     * vorher mit articleCount() prüfen, dass keine Artikel mehr
     * zugeordnet sind.
     */
    public function delete(int $id): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM article_categories
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);
    }

    /**
     * Zählt die aktiven Artikel dieser Kategorie – dient als Schutz vor
     * dem Löschen einer noch benutzten Kategorie.
     */
    public function articleCount(int $id): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM articles
             WHERE category_id = :category_id
             AND active = 1'
        );

        $statement->execute([
            'category_id' => $id
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Setzt die Sortierreihenfolge anhand der übergebenen ID-Liste neu
     * (Reihenfolge der IDs = neue Reihenfolge). Wird vom Drag & Drop auf
     * der Kategorien-Seite aufgerufen.
     */
    public function reorder(array $ids): void
    {
        $statement = $this->db->prepare(
            'UPDATE article_categories
             SET sort_order = :sort_order
             WHERE id = :id
             AND active = 1'
        );

        $this->db->beginTransaction();

        try {

            foreach ($ids as $position => $id) {

                $statement->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id
                ]);
            }

            $this->db->commit();

        } catch (\Throwable $exception) {

            $this->db->rollBack();

            throw $exception;
        }
    }

    /**
     * @param int|null $excludeId eigene ID beim Bearbeiten ausschließen
     */
    private function existsWithName(string $name, ?int $excludeId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM article_categories
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

    private function nextSortOrder(): int
    {
        $sortOrder = $this->db->query(
            'SELECT COALESCE(MAX(sort_order), 0)
             FROM article_categories
             WHERE active = 1'
        )->fetchColumn();

        return ((int) $sortOrder) + 10;
    }
}

<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Einheiten (Tabelle `units`): Einzahl (`name`) und
 * Mehrzahl (`plural`), z. B. "Rolle" / "Rollen". Artikel verweisen per
 * `unit_id` darauf; angezeigt wird die passende Form, siehe
 * quantityText().
 */
class UnitRepository
{
    use SortOrder;

    /**
     * Spalten `unit` (Einzahl) und `unit_plural` (Mehrzahl) für Abfragen,
     * die `units` als `u` dazuholen – wie quantityText() sie erwartet.
     * Ohne Einheit gilt "Stück".
     */
    public const SELECT_COLUMNS = 'COALESCE(u.name, \'Stück\') AS unit, '
        . 'COALESCE(u.plural, u.name, \'Stück\') AS unit_plural';

    private const SORT_TABLE = 'units';
    private const SORT_ACTIVE_ONLY = false;

    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Alle Einheiten in der per Drag & Drop festgelegten Reihenfolge,
     * jeweils mit der Zahl der (aktiven) Artikel, die sie verwenden.
     */
    public function all(): array
    {
        return $this->db
            ->query(
                'SELECT
                    u.*,
                    (
                        SELECT COUNT(*)
                        FROM articles a
                        WHERE a.unit_id = u.id
                        AND a.active = 1
                    ) AS article_count
                 FROM units u
                 ORDER BY u.sort_order, u.name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM units
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);

        $unit = $statement->fetch();

        return $unit ?: null;
    }

    /**
     * Einheit anhand der Einzahl, ohne Groß-/Kleinschreibung (siehe
     * nameKey()) – z. B. für Skripte, die Artikel mit "Stück" anlegen.
     */
    public function findByName(string $name): ?array
    {
        foreach ($this->db->query('SELECT * FROM units')->fetchAll() as $unit) {
            if (nameKey($unit['name']) === nameKey($name)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Legt eine Einheit an (ans Ende der Sortierung). Ohne Mehrzahl gilt
     * die Einzahl auch für die Mehrzahl (z. B. "Stück", "Paar").
     *
     * @throws RuntimeException wenn es die Einheit schon gibt
     */
    public function create(string $name, string $plural = ''): int
    {
        $this->assertNameAvailable($name);

        $statement = $this->db->prepare(
            'INSERT INTO units
                (name, plural, sort_order)
             VALUES
                (:name, :plural, :sort_order)'
        );

        $statement->execute([
            'name' => $name,
            'plural' => $plural !== '' ? $plural : $name,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Umbenennen wirkt bei allen Artikeln mit dieser Einheit.
     *
     * @throws RuntimeException wenn eine andere Einheit so heißt
     */
    public function update(int $id, string $name, string $plural = ''): void
    {
        $this->assertNameAvailable($name, $id);

        $statement = $this->db->prepare(
            'UPDATE units
             SET name = :name,
                 plural = :plural
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'plural' => $plural !== '' ? $plural : $name,
        ]);
    }

    /**
     * Löscht eine Einheit, die kein aktiver Artikel mehr verwendet.
     * Gelöschte Artikel verlieren den Verweis (ON DELETE SET NULL).
     *
     * @throws RuntimeException wenn sie noch verwendet wird
     */
    public function delete(int $id): void
    {
        $unit = $this->find($id);

        if (!$unit) {
            throw new RuntimeException('Einheit nicht gefunden.');
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM articles
             WHERE unit_id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);

        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new RuntimeException(
                'Die Einheit „' . $unit['name'] . '“ wird noch von '
                . $count . ($count === 1 ? ' Artikel' : ' Artikeln')
                . ' verwendet und kann nicht gelöscht werden.'
            );
        }

        $statement = $this->db->prepare(
            'DELETE FROM units
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);
    }


    /**
     * @throws RuntimeException wenn eine (andere) Einheit so heißt – in
     *                          Einzahl oder Mehrzahl, ohne Groß-/Klein-
     *                          schreibung
     */
    private function assertNameAvailable(string $name, ?int $excludeId = null): void
    {
        foreach ($this->db->query('SELECT * FROM units')->fetchAll() as $unit) {
            if ((int) $unit['id'] === $excludeId) {
                continue;
            }

            if (in_array(nameKey($name), [nameKey($unit['name']), nameKey($unit['plural'])], true)) {
                throw new RuntimeException(
                    'Diese Einheit gibt es schon (' . $unit['name'] . ').'
                );
            }
        }
    }
}

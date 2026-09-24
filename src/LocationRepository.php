<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Lagerorte (Tabelle `storage_locations`).
 *
 * Lagerorte werden nie hart gelöscht, sondern über deactivate() als
 * Soft-Delete markiert: `stock_movements.location_id` ist per
 * ON DELETE CASCADE mit dieser Tabelle verknüpft, ein echtes DELETE
 * würde also die komplette Bewegungshistorie dieses Lagerorts mitlöschen.
 */
class LocationRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Liefert alle aktiven Lagerorte in ihrer per Drag & Drop festgelegten
     * Reihenfolge (`sort_order`). Diese Reihenfolge bestimmt auch die
     * Ziel-Auswahl auf der Buchen-Seite und das Lagerort-Dropdown beim
     * Bestand buchen.
     */
    public function all(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM storage_locations
                 WHERE active = 1
                 ORDER BY sort_order, name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    /**
     * Liefert einen einzelnen aktiven Lagerort.
     */
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

    /**
     * Standard-Lagerort: der erste aktive Lagerort in der festgelegten
     * Reihenfolge (per Drag & Drop auf der Lagerorte-Seite). Er ist beim
     * Buchen und Einlagern vorausgewählt.
     *
     * Bewusst nicht über einen festen Namen wie "Hauptlager" – ein
     * Umbenennen soll die Vorauswahl nicht kaputt machen.
     */
    public function defaultLocation(): ?array
    {
        return $this->all()[0] ?? null;
    }

    /**
     * Sucht einen aktiven Lagerort anhand seines Namens.
     */
    public function findByName(string $name): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM storage_locations
             WHERE name = :name
             AND active = 1
             LIMIT 1'
        );

        $statement->execute([
            'name' => $name
        ]);

        $location = $statement->fetch();

        return $location ?: null;
    }

    /**
     * Legt einen neuen Lagerort an und hängt ihn ans Ende der Sortierung an.
     *
     * Heißt ein deaktivierter Lagerort so, wird stattdessen dieser wieder
     * aktiviert (Name und Beschreibung wie eingegeben, ans Ende der
     * Sortierung). Seine gespeicherten Mindestbestände gelten dann wieder;
     * Bestand hat er keinen, deaktivieren geht nur leer.
     *
     * @return int ID des neuen bzw. wieder aktivierten Lagerorts
     * @throws RuntimeException wenn ein aktiver Lagerort so heißt
     */
    public function create(string $name, string $description): int
    {
        $existing = $this->findWithName($name);

        if ($existing && (int) $existing['active'] === 1) {
            throw new RuntimeException(
                'Ein Lagerort mit diesem Namen existiert bereits ('
                . $existing['name'] . ').'
            );
        }

        if ($existing) {
            $statement = $this->db->prepare(
                'UPDATE storage_locations
                 SET name = :name,
                     description = :description,
                     sort_order = :sort_order,
                     active = 1
                 WHERE id = :id'
            );

            $statement->execute([
                'id' => $existing['id'],
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'sort_order' => $this->nextSortOrder()
            ]);

            return (int) $existing['id'];
        }

        $statement = $this->db->prepare(
            'INSERT INTO storage_locations
                (name, description, sort_order, active)
             VALUES
                (:name, :description, :sort_order, 1)'
        );

        $statement->execute([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'sort_order' => $this->nextSortOrder()
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Ob create() mit diesem Namen einen deaktivierten Lagerort wieder
     * aktivieren würde – für die passende Meldung.
     */
    public function isDeactivatedName(string $name): bool
    {
        $existing = $this->findWithName($name);

        return $existing !== null && (int) $existing['active'] === 0;
    }

    /**
     * @throws RuntimeException wenn der Name bereits von einem anderen
     *                          (auch deaktivierten) Lagerort verwendet wird
     */
    public function update(int $id, string $name, string $description): void
    {
        $existing = $this->findWithName($name, $id);

        if ($existing && (int) $existing['active'] === 1) {
            throw new RuntimeException(
                'Ein Lagerort mit diesem Namen existiert bereits ('
                . $existing['name'] . ').'
            );
        }

        /*
         * Der Name ist in der Datenbank eindeutig, auch gegenüber
         * deaktivierten Lagerorten.
         */
        if ($existing) {
            throw new RuntimeException(
                'So heißt ein deaktivierter Lagerort. Um ihn wieder zu nutzen, '
                . 'einen neuen Lagerort mit diesem Namen anlegen – er wird dann '
                . 'wieder aktiviert.'
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

    /**
     * Deaktiviert einen Lagerort (Soft-Delete), siehe Klassenkommentar.
     *
     * Ob dort noch Bestand vorhanden ist, muss der Aufrufer vorher selbst
     * prüfen (siehe StockRepository::locationHasStock()) – diese Methode
     * verhindert nur, dass der letzte verbleibende aktive Lagerort
     * deaktiviert wird.
     *
     * @throws RuntimeException wenn es der letzte aktive Lagerort ist
     */
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

    /**
     * Anzahl der aktuell aktiven Lagerorte.
     */
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

    /**
     * Setzt die Sortierreihenfolge anhand der übergebenen ID-Liste neu
     * (Reihenfolge der IDs = neue Reihenfolge). Wird vom Drag & Drop auf
     * der Lagerorte-Seite aufgerufen.
     */
    public function reorder(array $ids): void
    {
        $statement = $this->db->prepare(
            'UPDATE storage_locations
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

    private function nextSortOrder(): int
    {
        $sortOrder = $this->db->query(
            'SELECT COALESCE(MAX(sort_order), 0)
             FROM storage_locations
             WHERE active = 1'
        )->fetchColumn();

        return ((int) $sortOrder) + 10;
    }

    /**
     * Lagerort (aktiv oder deaktiviert) mit diesem Namen, ohne Groß-/
     * Kleinschreibung verglichen (siehe nameKey()).
     */
    private function findWithName(string $name, ?int $excludeId = null): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, active
             FROM storage_locations
             WHERE id != :exclude_id
             ORDER BY active DESC'
        );

        $statement->execute([
            'exclude_id' => $excludeId ?? 0
        ]);

        foreach ($statement->fetchAll() as $location) {
            if (nameKey($location['name']) === nameKey($name)) {
                return $location;
            }
        }

        return null;
    }
}

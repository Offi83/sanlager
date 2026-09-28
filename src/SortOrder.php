<?php

namespace LagerApp;

/**
 * Per Drag & Drop festgelegte Reihenfolge (Spalte `sort_order`) für
 * Kategorien, Lagerorte und Einheiten.
 *
 * Die Klasse legt fest, welche Tabelle gemeint ist (SORT_TABLE) und ob nur
 * aktive Zeilen zählen (SORT_ACTIVE_ONLY; Einheiten haben keine Spalte
 * `active`). Sie braucht eine PDO-Verbindung in `$this->db`.
 */
trait SortOrder
{
    /**
     * Setzt die Reihenfolge anhand der ID-Liste neu (Reihenfolge der IDs =
     * neue Reihenfolge, Abstand 10). Aufgerufen vom Drag & Drop.
     */
    public function reorder(array $ids): void
    {
        $statement = $this->db->prepare(
            'UPDATE ' . self::SORT_TABLE . '
             SET sort_order = :sort_order
             WHERE id = :id'
            . (self::SORT_ACTIVE_ONLY ? ' AND active = 1' : '')
        );

        $this->db->beginTransaction();

        try {
            foreach (array_values($ids) as $position => $id) {
                $statement->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }
    }

    /**
     * `sort_order` für einen neuen Eintrag am Ende der Liste.
     */
    private function nextSortOrder(): int
    {
        return 10 + (int) $this->db
            ->query(
                'SELECT COALESCE(MAX(sort_order), 0)
                 FROM ' . self::SORT_TABLE
                . (self::SORT_ACTIVE_ONLY ? ' WHERE active = 1' : '')
            )
            ->fetchColumn();
    }
}

<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Artikelstammdaten (Tabelle `articles`).
 *
 * Bestände selbst werden nicht hier, sondern über StockRepository anhand
 * der Lagerbewegungen ermittelt.
 */
class ArticleRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Liefert alle aktiven Artikel inkl. Kategorie-Infos, optional gefiltert
     * nach Suchbegriff (Name/Artikelnummer/Beschreibung) und Kategorie.
     */
    public function all(string $search = '', ?int $categoryId = null): array
    {
        $conditions = [
            'a.active = 1'
        ];

        $parameters = [];

        if ($categoryId !== null) {
            $conditions[] = 'a.category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }

        $sql = 'SELECT
                    a.*,
                    COALESCE(u.name, \'Stück\') AS unit,
                    COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                    c.name AS category_name,
                    c.color AS category_color,
                    c.sort_order AS category_sort_order
                FROM articles a
                LEFT JOIN units u
                    ON u.id = a.unit_id
                LEFT JOIN article_categories c
                    ON c.id = a.category_id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY
                    COALESCE(c.sort_order, 9999),
                    c.name COLLATE NOCASE,
                    a.name COLLATE NOCASE';

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        $articles = $statement->fetchAll();

        if (trim($search) === '') {
            return $articles;
        }

        /*
         * Suchbegriff in PHP statt per LIKE vergleichen: SQLite ignoriert
         * Groß-/Kleinschreibung nur bei A–Z ("übung" fände "Übungsverband"
         * nicht), und % oder _ im Suchbegriff wären Platzhalter. Bei der
         * Artikelzahl eines Lagers kostet das nichts.
         */
        $needle = nameKey($search);

        return array_values(array_filter(
            $articles,
            static function (array $article) use ($needle): bool {
                foreach (['name', 'article_number', 'description'] as $field) {
                    if (str_contains(nameKey((string) $article[$field]), $needle)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * Liefert einen einzelnen Artikel inkl. Kategorie-Infos, unabhängig
     * vom `active`-Status (z. B. für die Bearbeiten-Seite eines gerade
     * deaktivierten Artikels).
     */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.*,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                c.name AS category_name,
                c.color AS category_color,
                c.sort_order AS category_sort_order
             FROM articles a
             LEFT JOIN units u
                 ON u.id = a.unit_id
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);

        $article = $statement->fetch();

        return $article ?: null;
    }

    /**
     * Legt einen neuen Artikel an.
     *
     * Die Artikelnummer eines gelöschten Artikels wird dabei frei gegeben
     * (siehe releaseNumberOfDeleted()), sein Name zählt nicht mehr.
     *
     * @throws RuntimeException wenn Artikelnummer oder Name bereits von
     *                          einem aktiven Artikel verwendet werden
     */
    public function create(
        ?string $articleNumber,
        string $name,
        string $description,
        int|string $unit, // Einheit: ID oder Einzahl, siehe unitId()
        ?int $categoryId,
        bool $hasExpiry = true
    ): int {
        $unitId = $this->unitId($unit);

        $this->assertArticleNumberAvailable($articleNumber);
        $this->assertNameAvailable($name);
        $this->releaseNumberOfDeleted($articleNumber);

        $statement = $this->db->prepare(
            'INSERT INTO articles
                (
                    article_number,
                    name,
                    description,
                    unit_id,
                    category_id,
                    has_expiry
                )
             VALUES
                (
                    :article_number,
                    :name,
                    :description,
                    :unit_id,
                    :category_id,
                    :has_expiry
                )'
        );

        $statement->execute([
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'unit_id' => $unitId,
            'category_id' => $categoryId,
            'has_expiry' => $hasExpiry ? 1 : 0
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Aktualisiert einen bestehenden Artikel, siehe create().
     *
     * @throws RuntimeException wenn Artikelnummer oder Name bereits von
     *                          einem anderen aktiven Artikel verwendet werden
     */
    public function update(
        int $id,
        ?string $articleNumber,
        string $name,
        string $description,
        int|string $unit, // Einheit: ID oder Einzahl, siehe unitId()
        ?int $categoryId,
        bool $hasExpiry = true
    ): void {
        $unitId = $this->unitId($unit);

        $this->assertArticleNumberAvailable($articleNumber, $id);
        $this->assertNameAvailable($name, $id);
        $this->releaseNumberOfDeleted($articleNumber, $id);

        $statement = $this->db->prepare(
            'UPDATE articles
             SET article_number = :article_number,
                 name = :name,
                 description = :description,
                 unit_id = :unit_id,
                 category_id = :category_id,
                 has_expiry = :has_expiry
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'unit_id' => $unitId,
            'category_id' => $categoryId,
            'has_expiry' => $hasExpiry ? 1 : 0
        ]);
    }

    /**
     * Sucht einen aktiven Artikel anhand seiner Artikelnummer.
     *
     * Wird vom Scanner/der manuellen Eingabe auf der Buchen-Seite verwendet.
     */
    public function findByArticleNumber(
        string $articleNumber
    ): ?array {
        $statement = $this->db->prepare(
            'SELECT
                a.*,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                c.name AS category_name
             FROM articles a
             LEFT JOIN units u
                 ON u.id = a.unit_id
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.article_number = :article_number
             AND a.active = 1
             LIMIT 1'
        );

        $statement->execute([
            'article_number' => $articleNumber
        ]);

        $article = $statement->fetch();

        return $article ?: null;
    }

    /**
     * Deaktiviert einen Artikel (Soft-Delete). Vorhandene Lagerbewegungen
     * bleiben dabei unangetastet, der Artikel verschwindet lediglich aus
     * den aktiven Listen.
     */
    public function deactivate(int $id): void
    {
        $statement = $this->db->prepare(
            'UPDATE articles
             SET active = 0
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);
    }

    /**
     * ID der Einheit: aus der Auswahlliste im Formular direkt als ID, in
     * Skripten und Tests bequemer als Einzahl ("Stück", ohne Groß-/Klein-
     * schreibung), siehe UnitRepository.
     *
     * @throws RuntimeException wenn es die Einheit nicht gibt
     */
    private function unitId(int|string $unit): int
    {
        $units = new UnitRepository($this->db);

        $unitRow = is_int($unit)
            ? $units->find($unit)
            : $units->findByName($unit);

        if ($unitRow === null) {
            throw new RuntimeException(
                is_int($unit)
                    ? 'Bitte eine Einheit auswählen.'
                    : 'Unbekannte Einheit: ' . $unit . ' (siehe Verwaltung → Einheiten).'
            );
        }

        return (int) $unitRow['id'];
    }

    /**
     * Gelöschte (deaktivierte) Artikel zählen nicht: Ihre Nummer gibt
     * releaseNumberOfDeleted() frei.
     *
     * @throws RuntimeException wenn ein aktiver Artikel die Artikelnummer
     *                          bereits verwendet
     */
    private function assertArticleNumberAvailable(
        ?string $articleNumber,
        ?int $excludeId = null
    ): void {
        if ($articleNumber === null || $articleNumber === '') {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT name
             FROM articles
             WHERE article_number = :article_number
             AND id != :exclude_id
             AND active = 1
             LIMIT 1'
        );

        $statement->execute([
            'article_number' => $articleNumber,
            'exclude_id' => $excludeId ?? 0
        ]);

        $usedBy = $statement->fetchColumn();

        if ($usedBy !== false) {
            throw new RuntimeException(
                'Diese Artikelnummer wird bereits verwendet (' . $usedBy . ').'
            );
        }
    }

    /**
     * Nimmt einem gelöschten Artikel seine Artikelnummer, damit ein neuer
     * oder umbenannter Artikel sie verwenden kann (in der Datenbank ist
     * sie eindeutig). Der gelöschte Artikel bleibt mit seinen Buchungen
     * erhalten, ist aber ohnehin nirgends mehr sichtbar oder scanbar.
     */
    private function releaseNumberOfDeleted(
        ?string $articleNumber,
        ?int $excludeId = null
    ): void {
        if ($articleNumber === null || $articleNumber === '') {
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE articles
             SET article_number = NULL
             WHERE article_number = :article_number
             AND id != :exclude_id
             AND active = 0'
        );

        $statement->execute([
            'article_number' => $articleNumber,
            'exclude_id' => $excludeId ?? 0
        ]);
    }

    /**
     * Vergleich ohne Groß-/Kleinschreibung (siehe nameKey()); gelöschte
     * Artikel zählen nicht.
     *
     * @throws RuntimeException wenn ein aktiver Artikel so heißt
     */
    private function assertNameAvailable(
        string $name,
        ?int $excludeId = null
    ): void {
        $statement = $this->db->prepare(
            'SELECT name
             FROM articles
             WHERE id != :exclude_id
             AND active = 1'
        );

        $statement->execute([
            'exclude_id' => $excludeId ?? 0
        ]);

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (nameKey($existingName) === nameKey($name)) {
                throw new RuntimeException(
                    'Ein Artikel mit diesem Namen existiert bereits ('
                    . $existingName . ').'
                );
            }
        }
    }
}

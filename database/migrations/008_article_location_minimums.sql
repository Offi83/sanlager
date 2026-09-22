CREATE TABLE IF NOT EXISTS article_location_minimums (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    location_id INTEGER NOT NULL,
    minimum_stock INTEGER NOT NULL DEFAULT 0,

    FOREIGN KEY (article_id)
        REFERENCES articles(id)
        ON DELETE CASCADE,

    FOREIGN KEY (location_id)
        REFERENCES storage_locations(id)
        ON DELETE CASCADE,

    UNIQUE (article_id, location_id)
);

CREATE INDEX IF NOT EXISTS idx_location_minimums_article
    ON article_location_minimums(article_id);

/*
 * Bisherige (globale) Mindestbestände wurden implizit fürs Hauptlager
 * gepflegt, da es lange der einzige Lagerort war. Sie werden deshalb
 * 1:1 als Hauptlager-Mindestbestand übernommen.
 */
INSERT INTO article_location_minimums (article_id, location_id, minimum_stock)
SELECT a.id, sl.id, a.minimum_stock
FROM articles a
INNER JOIN storage_locations sl ON sl.name = 'Hauptlager'
WHERE a.minimum_stock > 0;

ALTER TABLE articles DROP COLUMN minimum_stock;

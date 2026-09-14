PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS article_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1
);

INSERT OR IGNORE INTO article_categories (name, sort_order) VALUES
    ('Verbandmaterial', 10),
    ('Diagnostik', 20),
    ('Beatmung', 30),
    ('Medikamente', 40),
    ('Infusion & Injektion', 50),
    ('Immobilisation', 60),
    ('Hygiene & Desinfektion', 70),
    ('Schutzausrüstung', 80),
    ('Sonstiges', 90);

ALTER TABLE articles
    ADD COLUMN category_id INTEGER
    REFERENCES article_categories(id)
    ON DELETE SET NULL;

UPDATE articles
SET category_id = (
    SELECT id
    FROM article_categories
    WHERE name = 'Sonstiges'
)
WHERE category_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_articles_category
    ON articles(category_id);

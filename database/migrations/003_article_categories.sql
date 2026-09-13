PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS article_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    short_name TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#64748b',
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1
);

INSERT OR IGNORE INTO article_categories
    (name, short_name, color, sort_order)
VALUES
    ('Verbandmaterial', 'Verband', '#dc2626', 10),
    ('Diagnostik', 'Diagnostik', '#2563eb', 20),
    ('Beatmung', 'Beatmung', '#0891b2', 30),
    ('Medikamente', 'Medikamente', '#7c3aed', 40),
    ('Infusion & Injektion', 'Infusion', '#c2410c', 50),
    ('Immobilisation', 'Immobilisation', '#ca8a04', 60),
    ('Hygiene & Desinfektion', 'Hygiene', '#16a34a', 70),
    ('Schutzausrüstung', 'Schutz', '#4f46e5', 80),
    ('Sonstiges', 'Sonstiges', '#64748b', 90);

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

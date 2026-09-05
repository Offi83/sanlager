PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS storage_locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_number TEXT UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    unit TEXT NOT NULL DEFAULT 'Stück',
    minimum_stock INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    batch_number TEXT,
    expiry_date TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (article_id)
        REFERENCES articles(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stock (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    batch_id INTEGER,
    location_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,

    UNIQUE(article_id, batch_id, location_id),

    FOREIGN KEY (article_id)
        REFERENCES articles(id)
        ON DELETE CASCADE,

    FOREIGN KEY (batch_id)
        REFERENCES batches(id)
        ON DELETE SET NULL,

    FOREIGN KEY (location_id)
        REFERENCES storage_locations(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    batch_id INTEGER,
    location_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (article_id)
        REFERENCES articles(id)
        ON DELETE CASCADE,

    FOREIGN KEY (batch_id)
        REFERENCES batches(id)
        ON DELETE SET NULL,

    FOREIGN KEY (location_id)
        REFERENCES storage_locations(id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_articles_name
    ON articles(name);

CREATE INDEX IF NOT EXISTS idx_batches_expiry
    ON batches(expiry_date);

CREATE INDEX IF NOT EXISTS idx_stock_article
    ON stock(article_id);

CREATE INDEX IF NOT EXISTS idx_stock_location
    ON stock(location_id);

CREATE INDEX IF NOT EXISTS idx_movements_article
    ON stock_movements(article_id);

CREATE INDEX IF NOT EXISTS idx_movements_created
    ON stock_movements(created_at);

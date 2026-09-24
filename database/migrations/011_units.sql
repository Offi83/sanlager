/*
 * Einheiten als eigene Liste (Verwaltung → Einheiten) statt Freitext am
 * Artikel – damit nicht "Packung", "Packungen" und "Pckg." nebeneinander
 * entstehen. Jede Einheit hat Einzahl und Mehrzahl ("1 Rolle",
 * "5 Rollen").
 */
CREATE TABLE IF NOT EXISTS units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    plural TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

/*
 * Bisher verwendete Einheiten übernehmen ("Stück" immer, als erste).
 * Für gängige Einheiten wird die Mehrzahl gleich mitgesetzt, sonst ist
 * sie zunächst gleich der Einzahl und lässt sich in der Verwaltung
 * korrigieren.
 */
INSERT INTO units (name, plural, sort_order)
SELECT
    name,
    CASE name
        WHEN 'Rolle' THEN 'Rollen'
        WHEN 'Flasche' THEN 'Flaschen'
        WHEN 'Packung' THEN 'Packungen'
        WHEN 'Dose' THEN 'Dosen'
        WHEN 'Tube' THEN 'Tuben'
        WHEN 'Schachtel' THEN 'Schachteln'
        WHEN 'Ampulle' THEN 'Ampullen'
        WHEN 'Tablette' THEN 'Tabletten'
        WHEN 'Karton' THEN 'Kartons'
        WHEN 'Paket' THEN 'Pakete'
        WHEN 'Set' THEN 'Sets'
        WHEN 'Box' THEN 'Boxen'
        WHEN 'Kiste' THEN 'Kisten'
        WHEN 'Binde' THEN 'Binden'
        ELSE name
    END,
    ROW_NUMBER() OVER (ORDER BY name <> 'Stück', name COLLATE NOCASE) * 10
FROM (
    SELECT 'Stück' AS name
    UNION
    SELECT DISTINCT TRIM(unit)
    FROM articles
    WHERE TRIM(unit) <> ''
);

/*
 * Gelöschte Einheit (nur möglich, wenn kein aktiver Artikel sie nutzt):
 * Verweise gelöschter Artikel werden leer, angezeigt wird dann "Stück".
 */
ALTER TABLE articles ADD COLUMN unit_id INTEGER
    REFERENCES units(id) ON DELETE SET NULL;

UPDATE articles
SET unit_id = COALESCE(
    (SELECT id FROM units WHERE units.name = TRIM(articles.unit)),
    (SELECT id FROM units WHERE units.name = 'Stück')
);

ALTER TABLE articles DROP COLUMN unit;

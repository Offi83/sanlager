PRAGMA foreign_keys = ON;

ALTER TABLE article_categories
    ADD COLUMN short_name TEXT NOT NULL DEFAULT '';

ALTER TABLE article_categories
    ADD COLUMN color TEXT NOT NULL DEFAULT '#64748b';

UPDATE article_categories
SET
    short_name = CASE name
        WHEN 'Verbandmaterial' THEN 'Verband'
        WHEN 'Diagnostik' THEN 'Diagnostik'
        WHEN 'Beatmung' THEN 'Beatmung'
        WHEN 'Medikamente' THEN 'Medikamente'
        WHEN 'Infusion & Injektion' THEN 'Infusion'
        WHEN 'Immobilisation' THEN 'Immobilisation'
        WHEN 'Hygiene & Desinfektion' THEN 'Hygiene'
        WHEN 'Schutzausrüstung' THEN 'Schutz'
        WHEN 'Sonstiges' THEN 'Sonstiges'
        ELSE name
    END,
    color = CASE name
        WHEN 'Verbandmaterial' THEN '#dc2626'
        WHEN 'Diagnostik' THEN '#2563eb'
        WHEN 'Beatmung' THEN '#0891b2'
        WHEN 'Medikamente' THEN '#7c3aed'
        WHEN 'Infusion & Injektion' THEN '#c2410c'
        WHEN 'Immobilisation' THEN '#ca8a04'
        WHEN 'Hygiene & Desinfektion' THEN '#16a34a'
        WHEN 'Schutzausrüstung' THEN '#4f46e5'
        WHEN 'Sonstiges' THEN '#64748b'
        ELSE '#64748b'
    END;


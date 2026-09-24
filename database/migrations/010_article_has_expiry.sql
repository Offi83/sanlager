/*
 * Ob ein Artikel überhaupt ein MHD hat. Ohne (z. B. Mullbinden) entfällt
 * beim Buchen die MHD-Auswahl. Bestehende Artikel behalten das bisherige
 * Verhalten (mit MHD), bis es beim Bearbeiten abgewählt wird.
 */
ALTER TABLE articles ADD COLUMN has_expiry INTEGER NOT NULL DEFAULT 1;

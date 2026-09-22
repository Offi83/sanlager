ALTER TABLE storage_locations
    ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;

UPDATE storage_locations
SET sort_order = id * 10;

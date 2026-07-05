-- Add parent_location_id column and change uniqueness constraint for hierarchical locations
-- Allows same name under different parents (e.g., "Shelf 1" in Fridge and "Shelf 1" in Cupboard)

PRAGMA legacy_alter_table = ON;

-- Rename old table
ALTER TABLE locations RENAME TO locations_old;

-- Create new table with parent_location_id and without UNIQUE constraint on name
CREATE TABLE locations (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	name TEXT NOT NULL,
	description TEXT,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime')),
	is_freezer TINYINT NOT NULL DEFAULT 0,
	active TINYINT NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
	parent_location_id INTEGER
);

-- Copy data
INSERT INTO locations (id, name, description, row_created_timestamp, is_freezer, active)
SELECT id, name, description, row_created_timestamp, is_freezer, active
FROM locations_old;

-- Drop old table
DROP TABLE locations_old;

-- Create partial unique indexes for composite uniqueness
-- Ensures name is unique within each parent (including NULL as a distinct parent)
CREATE UNIQUE INDEX ix_locations_name_parent ON locations(name, parent_location_id)
WHERE parent_location_id IS NOT NULL;

CREATE UNIQUE INDEX ix_locations_name_root ON locations(name)
WHERE parent_location_id IS NULL;

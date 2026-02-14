<?php

// This migration is always executed (on every migration run, not only once)

// This is executed inside DatabaseMigrationService class/context

// When FEATURE_FLAG_STOCK_LOCATION_TRACKING is disabled,
// some places assume that there exists a location with id 1,
// so make sure that this location is available in that case
if (!GROCY_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
{
	$db = $this->getDatabaseService()->GetDbConnection();

	if ($db->locations()->where('id', 1)->count() === 0)
	{
		$defaultLocation = $db->locations()->createRow([
			'id' => 1,
			'name' => 'Default'
		]);
		$defaultLocation->save();
	}
}

// Hierarchical locations: recreate views and triggers idempotently
// Only applies when the sublocation migration (9001) has been applied
$columns = $this->getDatabaseService()->ExecuteDbQuery("PRAGMA table_info('locations')")->fetchAll(\PDO::FETCH_ASSOC);
$hasParentLocationId = false;
foreach ($columns as $col)
{
	if ($col['name'] === 'parent_location_id')
	{
		$hasParentLocationId = true;
		break;
	}
}

if ($hasParentLocationId)
{
	$dbRaw = $this->getDatabaseService()->GetDbConnectionRaw();

	// Views
	$dbRaw->exec("DROP VIEW IF EXISTS locations_resolved");
	$dbRaw->exec("CREATE VIEW locations_resolved
AS
WITH RECURSIVE location_hierarchy(location_id, ancestor_location_id, level)
AS (
	SELECT id, id, 0
	FROM locations

	UNION ALL

	SELECT lh.location_id, l.parent_location_id, lh.level + 1
	FROM location_hierarchy lh
	JOIN locations l ON lh.ancestor_location_id = l.id
	WHERE l.parent_location_id IS NOT NULL
	LIMIT 100
)
SELECT
	location_id AS id,
	location_id,
	ancestor_location_id,
	level
FROM location_hierarchy");

	$dbRaw->exec("DROP VIEW IF EXISTS locations_hierarchy");
	$dbRaw->exec("CREATE VIEW locations_hierarchy
AS
WITH RECURSIVE location_tree(id, name, description, parent_location_id, is_freezer, active, row_created_timestamp, path, depth)
AS (
	SELECT id, name, description, parent_location_id, is_freezer, active, row_created_timestamp, name, 0
	FROM locations
	WHERE parent_location_id IS NULL

	UNION ALL

	SELECT l.id, l.name, l.description, l.parent_location_id, l.is_freezer, l.active, l.row_created_timestamp,
		lt.path || ' > ' || l.name,
		lt.depth + 1
	FROM locations l
	JOIN location_tree lt ON l.parent_location_id = lt.id
	LIMIT 100
)
SELECT
	id,
	name,
	description,
	parent_location_id,
	is_freezer,
	active,
	row_created_timestamp,
	path AS location_path,
	depth AS location_depth
FROM location_tree");

	// Triggers
	$dbRaw->exec("DROP TRIGGER IF EXISTS enforce_parent_location_id_null_when_empty_INS");
	$dbRaw->exec("CREATE TRIGGER enforce_parent_location_id_null_when_empty_INS AFTER INSERT ON locations
BEGIN
	UPDATE locations
	SET parent_location_id = NULL
	WHERE id = NEW.id
		AND IFNULL(parent_location_id, '') = '';
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS enforce_parent_location_id_null_when_empty_UPD");
	$dbRaw->exec("CREATE TRIGGER enforce_parent_location_id_null_when_empty_UPD AFTER UPDATE ON locations
BEGIN
	UPDATE locations
	SET parent_location_id = NULL
	WHERE id = NEW.id
		AND IFNULL(parent_location_id, '') = '';
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS prevent_self_parent_location_INS");
	$dbRaw->exec("CREATE TRIGGER prevent_self_parent_location_INS BEFORE INSERT ON locations
BEGIN
	SELECT CASE WHEN((
		SELECT 1
		WHERE NEW.parent_location_id IS NOT NULL
			AND NEW.parent_location_id = NEW.id
	) NOTNULL) THEN RAISE(ABORT, 'A location cannot be its own parent') END;
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS prevent_self_parent_location_UPD");
	$dbRaw->exec("CREATE TRIGGER prevent_self_parent_location_UPD BEFORE UPDATE ON locations
BEGIN
	SELECT CASE WHEN((
		SELECT 1
		WHERE NEW.parent_location_id IS NOT NULL
			AND NEW.parent_location_id = NEW.id
	) NOTNULL) THEN RAISE(ABORT, 'A location cannot be its own parent') END;
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS prevent_circular_location_hierarchy_UPD");
	$dbRaw->exec("CREATE TRIGGER prevent_circular_location_hierarchy_UPD BEFORE UPDATE ON locations
WHEN NEW.parent_location_id IS NOT NULL
BEGIN
	SELECT CASE WHEN((
		WITH RECURSIVE descendants(id) AS (
			SELECT NEW.id
			UNION ALL
			SELECT l.id
			FROM locations l
			JOIN descendants d ON l.parent_location_id = d.id
			WHERE l.id != NEW.id
			LIMIT 100
		)
		SELECT 1 FROM descendants WHERE id = NEW.parent_location_id
	) NOTNULL) THEN RAISE(ABORT, 'Circular location hierarchy detected') END;
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS inherit_freezer_from_parent_INS");
	$dbRaw->exec("CREATE TRIGGER inherit_freezer_from_parent_INS AFTER INSERT ON locations
WHEN NEW.parent_location_id IS NOT NULL
BEGIN
	UPDATE locations
	SET is_freezer = 1
	WHERE id = NEW.id
		AND (SELECT is_freezer FROM locations WHERE id = NEW.parent_location_id) = 1;
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS inherit_freezer_from_parent_UPD");
	$dbRaw->exec("CREATE TRIGGER inherit_freezer_from_parent_UPD AFTER UPDATE ON locations
WHEN NEW.parent_location_id IS NOT NULL AND NEW.parent_location_id != IFNULL(OLD.parent_location_id, 0)
BEGIN
	UPDATE locations
	SET is_freezer = 1
	WHERE id = NEW.id
		AND (SELECT is_freezer FROM locations WHERE id = NEW.parent_location_id) = 1;
END");

	$dbRaw->exec("DROP TRIGGER IF EXISTS propagate_freezer_to_descendants_UPD");
	$dbRaw->exec("CREATE TRIGGER propagate_freezer_to_descendants_UPD AFTER UPDATE ON locations
WHEN NEW.is_freezer = 1 AND OLD.is_freezer = 0
BEGIN
	UPDATE locations
	SET is_freezer = 1
	WHERE id IN (
		WITH RECURSIVE descendants(id) AS (
			SELECT id FROM locations WHERE parent_location_id = NEW.id
			UNION ALL
			SELECT l.id
			FROM locations l
			JOIN descendants d ON l.parent_location_id = d.id
			LIMIT 100
		)
		SELECT id FROM descendants
	);
END");
}

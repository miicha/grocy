<?php

// FORK (sublocations): verification harness for the hierarchical-locations fork.
// See FORK.md ("Upgrading to a new upstream release") — run this after every upstream merge.
//
// Usage:
//   php -d xdebug.mode=off .devtools/sublocation_tests.php              # fresh-install path
//   php -d xdebug.mode=off .devtools/sublocation_tests.php <grocy.db>   # upgrade path: pass a COPY-SOURCE
//                                                                       # of a real pre-upgrade DB (it is
//                                                                       # copied to a temp dir, the original
//                                                                       # is never touched)
//
// What it does:
//   1. Runs the complete migration chain (all upstream migrations + 9001.sql + 9999.php)
//      against a temp SQLite DB, twice (proves 9999.php is idempotent).
//   2. Verifies schema, views, triggers, unique indexes and trigger behavior
//      (path building, freezer inheritance/propagation, circular/self-parent rejection).
//   3. Compiles every Blade template in views/ and syntax-checks the result,
//      and verifies location_path is always output escaped (e()).

error_reporting(E_ALL & ~E_DEPRECATED);

$repoRoot = dirname(__DIR__);
$dataPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'grocy-subloc-test-' . uniqid();
mkdir($dataPath, 0777, true);

$existingDb = $argv[1] ?? null;
if ($existingDb !== null)
{
	if (!is_file($existingDb))
	{
		fwrite(STDERR, "Given DB file not found: $existingDb\n");
		exit(2);
	}

	copy($existingDb, $dataPath . DIRECTORY_SEPARATOR . 'grocy.db');
	echo "== Upgrade-path mode: testing against a copy of $existingDb ==\n";
}
else
{
	echo "== Fresh-install mode ==\n";
}

define('GROCY_DATAPATH', $dataPath);
define('GROCY_MODE', 'production');
define('GROCY_USER_ID', 1);

require_once $repoRoot . '/packages/autoload.php';
require_once $repoRoot . '/helpers/extensions.php';
require_once $repoRoot . '/config-dist.php'; // Feature-flag constants, needed by migrations/8888.php

use Grocy\Services\DatabaseMigrationService;
use Grocy\Services\DatabaseService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

$pass = 0;
$fail = 0;

function check($label, $cond)
{
	global $pass, $fail;
	if ($cond)
	{
		$pass++;
		echo "PASS  $label\n";
	}
	else
	{
		$fail++;
		echo "FAIL  $label\n";
	}
}

function expectError($label, $fn, $needle)
{
	try
	{
		$fn();
		check($label . ' (no error raised)', false);
	}
	catch (Exception $ex)
	{
		check($label, strpos($ex->getMessage(), $needle) !== false);
	}
}

// ---------------------------------------------------------------------------
// 1. Migrations
// ---------------------------------------------------------------------------

$db = DatabaseService::getInstance()->GetDbConnectionRaw();

$locationCountBefore = null;
if ($existingDb !== null)
{
	$locationCountBefore = $db->query('SELECT COUNT(*) FROM locations')->fetchColumn();
}

DatabaseMigrationService::getInstance()->MigrateDatabase();
check('full migration chain runs', true);

DatabaseMigrationService::getInstance()->MigrateDatabase();
check('second migration run succeeds (9999.php is idempotent)', true);

// ---------------------------------------------------------------------------
// 2. Schema, views, triggers
// ---------------------------------------------------------------------------

$cols = $db->query("PRAGMA table_info('locations')")->fetchAll(PDO::FETCH_COLUMN, 1);
check('locations has parent_location_id column', in_array('parent_location_id', $cols));

$views = $db->query("SELECT name FROM sqlite_master WHERE type='view'")->fetchAll(PDO::FETCH_COLUMN);
check('view locations_resolved exists', in_array('locations_resolved', $views));
check('view locations_hierarchy exists', in_array('locations_hierarchy', $views));

$expectedTriggers = [
	'enforce_parent_location_id_null_when_empty_INS',
	'enforce_parent_location_id_null_when_empty_UPD',
	'prevent_self_parent_location_INS',
	'prevent_self_parent_location_UPD',
	'prevent_circular_location_hierarchy_UPD',
	'inherit_freezer_from_parent_INS',
	'inherit_freezer_from_parent_UPD',
	'propagate_freezer_to_descendants_UPD',
];
$triggers = $db->query("SELECT name FROM sqlite_master WHERE type='trigger'")->fetchAll(PDO::FETCH_COLUMN);
check('all 8 fork triggers exist', count(array_diff($expectedTriggers, $triggers)) === 0);

if ($existingDb !== null)
{
	$locationCountAfter = $db->query('SELECT COUNT(*) FROM locations')->fetchColumn();
	check("existing location rows preserved ($locationCountBefore before, $locationCountAfter after)", $locationCountBefore == $locationCountAfter);
}

// ---------------------------------------------------------------------------
// 3. Behavior (uses own uniquely-named rows, removed afterwards)
// ---------------------------------------------------------------------------

$p = '[subloc-test ' . uniqid() . '] ';

function addLocation($db, $name, $parentId = null, $isFreezer = 0)
{
	$cmd = $db->prepare('INSERT INTO locations (name, parent_location_id, is_freezer) VALUES (?, ?, ?)');
	$cmd->execute([$name, $parentId, $isFreezer]);
	return (int)$db->lastInsertId();
}

$warehouse = addLocation($db, $p . 'Warehouse');
$shelf = addLocation($db, $p . 'Shelf A', $warehouse);
$box = addLocation($db, $p . 'Box 1', $shelf);

$row = $db->query("SELECT location_path, location_depth FROM locations_hierarchy WHERE id = $box")->fetch(PDO::FETCH_ASSOC);
check('location_path = "Warehouse > Shelf A > Box 1"', $row['location_path'] === $p . 'Warehouse > ' . $p . 'Shelf A > ' . $p . 'Box 1');
check('location_depth of grandchild = 2', $row['location_depth'] == 2);

$ancestors = $db->query("SELECT ancestor_location_id FROM locations_resolved WHERE location_id = $box ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);
check('locations_resolved ancestor chain correct', $ancestors == [$box, $shelf, $warehouse]);

expectError('duplicate name under same parent rejected', function () use ($db, $p, $shelf)
{
	addLocation($db, $p . 'Box 1', $shelf);
}, 'UNIQUE');

$boxInWarehouse = addLocation($db, $p . 'Box 1', $warehouse);
check('same name under different parent allowed', $boxInWarehouse > 0);

expectError('duplicate root name rejected', function () use ($db, $p)
{
	addLocation($db, $p . 'Warehouse');
}, 'UNIQUE');

// Self-parenting on UPDATE is caught by the circular trigger (fires first),
// on INSERT by the dedicated self-parent trigger — rejection is what matters.
expectError('self-parent rejected on UPDATE', function () use ($db, $warehouse)
{
	$db->exec("UPDATE locations SET parent_location_id = $warehouse WHERE id = $warehouse");
}, 'ircular');

expectError('circular hierarchy rejected', function () use ($db, $warehouse, $box)
{
	$db->exec("UPDATE locations SET parent_location_id = $box WHERE id = $warehouse");
}, 'Circular');

$emptyParent = addLocation($db, $p . 'EmptyParent', '');
$v = $db->query("SELECT parent_location_id FROM locations WHERE id = $emptyParent")->fetchColumn();
check('empty-string parent normalized to NULL on INSERT', $v === null || $v === false);

$freezer = addLocation($db, $p . 'Freezer', null, 1);
$drawer = addLocation($db, $p . 'Drawer', $freezer);
$v = $db->query("SELECT is_freezer FROM locations WHERE id = $drawer")->fetchColumn();
check('child of freezer inherits is_freezer=1 on INSERT', $v == 1);

$movingBox = addLocation($db, $p . 'Moving Box');
$db->exec("UPDATE locations SET parent_location_id = $freezer WHERE id = $movingBox");
$v = $db->query("SELECT is_freezer FROM locations WHERE id = $movingBox")->fetchColumn();
check('location moved under freezer gets is_freezer=1', $v == 1);

$db->exec("UPDATE locations SET is_freezer = 1 WHERE id = $warehouse");
$v = $db->query("SELECT MIN(is_freezer) FROM locations WHERE id IN ($shelf, $box, $boxInWarehouse)")->fetchColumn();
check('setting is_freezer=1 propagates to all descendants', $v == 1);

$cnt = $db->query('SELECT COUNT(*) FROM locations_hierarchy')->fetchColumn();
$cntBase = $db->query('SELECT COUNT(*) FROM locations')->fetchColumn();
check("locations_hierarchy row count matches locations ($cnt)", $cnt == $cntBase);

// Remove test rows
$cmd = $db->prepare('DELETE FROM locations WHERE name LIKE ?');
$cmd->execute([$p . '%']);

// ---------------------------------------------------------------------------
// 4. Blade templates: compile + syntax check, location_path always escaped
// ---------------------------------------------------------------------------

$compiler = new BladeCompiler(new Filesystem(), $dataPath);

$templateFail = 0;
$templateCount = 0;
$rawLocationPathEchos = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/views'));
foreach ($iterator as $file)
{
	if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php'))
	{
		continue;
	}

	$templateCount++;
	$relative = substr($file->getPathname(), strlen($repoRoot) + 1);
	$out = $dataPath . DIRECTORY_SEPARATOR . 'compiled_' . md5($relative) . '.php';

	try
	{
		$compiled = $compiler->compileString(file_get_contents($file->getPathname()));
		file_put_contents($out, $compiled);
	}
	catch (Throwable $ex)
	{
		echo "FAIL  Blade compile: $relative: " . $ex->getMessage() . "\n";
		$templateFail++;
		continue;
	}

	exec('php -d xdebug.mode=off -l ' . escapeshellarg($out) . ' 2>&1', $lintOutput, $rc);
	if ($rc !== 0)
	{
		echo "FAIL  PHP lint: $relative:\n" . implode("\n", $lintOutput) . "\n";
		$templateFail++;
	}
	$lintOutput = [];

	// Every echo of location_path must go through e() — raw {!! !!} would be XSS
	if (preg_match('/echo (?!e\()[^;]*location_path/', $compiled))
	{
		$rawLocationPathEchos[] = $relative;
	}
}
check("all $templateCount Blade templates compile and lint", $templateFail === 0);
check('location_path is always output escaped (no raw {!! !!})', count($rawLocationPathEchos) === 0);
if (count($rawLocationPathEchos) > 0)
{
	echo '      Unescaped in: ' . implode(', ', $rawLocationPathEchos) . "\n";
}

// ---------------------------------------------------------------------------

// Best-effort temp dir cleanup (DB file may still be held open on Windows)
$db = null;
foreach (glob($dataPath . DIRECTORY_SEPARATOR . '*') as $f)
{
	@unlink($f);
}
@rmdir($dataPath);

echo "\n== Result: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

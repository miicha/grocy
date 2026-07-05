# Grocy Fork: Hierarchical Locations (Sublocations)

This is a permanent fork of [grocy/grocy](https://github.com/grocy/grocy) that adds
**hierarchical (nested) locations** — see upstream issue
[#481](https://github.com/grocy/grocy/issues/481). Upstream declined the feature,
so this fork maintains it on top of upstream releases.

## What the fork adds

- Locations can have a `parent_location_id`, forming a tree (e.g. `Basement > Freezer > Drawer 2`).
- Location names must be unique only *within the same parent* (partial unique
  indexes), so "Shelf 1" can exist in both Fridge and Cupboard.
- `is_freezer` is inherited from parent locations and propagated to descendants
  (via triggers).
- Circular hierarchies and self-parenting are rejected (via triggers).
- Two SQL views:
  - `locations_resolved` — ancestor/descendant pairs for hierarchy queries.
  - `locations_hierarchy` — display view with `location_path`
    ("Warehouse > Shelf A") and `location_depth`.
- UI: location form has a parent picker, stock overview / location content sheet /
  location list show the full path, with a direct-content-only switch where relevant.

## Migration strategy (important)

Upstream owns the `0001`–`8888` migration namespace. This fork only **adds** files
in `migrations/` — it never modifies upstream ones:

| File | Role |
|------|------|
| `migrations/9001.sql` | One-time schema change: recreates `locations` with `parent_location_id` and the partial unique indexes. Runs after all upstream migrations. |
| `migrations/9999.php` | Always-run (uses the `EMERGENCY_MIGRATION_ID` slot, which upstream never ships). Idempotently (re)creates the fork's views and triggers on every migration run — self-heals if an upstream migration drops them. Guarded: does nothing until 9001 has added `parent_location_id`. |

Numbering matters: migrations run in filename sort order, so `9999.php` runs
*after* `9001.sql` even on a fresh install. Fork-only follow-up schema changes go
into `9002.sql`, `9003.sql`, … (keep them below 9999).

**Known risk:** if a future upstream migration recreates the `locations` table
(grocy's usual rename→recreate→copy→drop pattern), existing installs lose the
`parent_location_id` column *and its data*, because 9001 is already recorded as
applied. `9999.php` self-heals views/triggers but **not** the column. In that case,
write a new `900x.sql` that re-adds the column (and restore data from a backup).
This is the #1 thing to check on every upstream merge.

## Modified upstream files

Everything else the fork touches (check these for conflicts/drift on merges):

- `controllers/StockController.php` — uses `locations_hierarchy()` / `location_path`
- `grocy.openapi.json` — API schema additions
- `localization/strings.pot` — new strings
- `public/viewjs/locationform.js`, `public/viewjs/locationcontentsheet.js`
- `views/locationform.blade.php`, `views/locations.blade.php`,
  `views/locationcontentsheet.blade.php`, `views/stockoverview.blade.php`,
  `views/productform.blade.php`, `views/components/locationpicker.blade.php`,
  `views/consume.blade.php`, `views/transfer.blade.php`,
  `views/stockjournal.blade.php`, `views/stockentries.blade.php`,
  `views/stocksettings.blade.php`, `views/products.blade.php`

Added files: `migrations/9001.sql`, `migrations/9999.php`, `FORK.md`.

The full delta against upstream is always visible with:

```sh
git diff master fork-main   # or whatever the current fork branch is
```

## Repo / branch model

- Remote `upstream` = https://github.com/grocy/grocy.git (read-only mirror source)
- Remote `origin` = git@github.com:miicha/grocy.git (this fork)
- Branch `master` = pristine mirror of upstream master. Never commit here;
  only `git merge --ff-only upstream/master`.
- The fork branch (currently `481-sublocations`) = master + fork commits.
  This is what gets deployed. Upstream releases are **merged** in (not rebased),
  so history is never force-pushed; `rerere.enabled` is on so recurring conflict
  resolutions replay automatically.
- Tag fork releases as `<upstream-tag>-subloc.<n>`, e.g. `v4.5.0-subloc.1`.

## Upgrading to a new upstream release — checklist

```sh
git fetch upstream --tags
git checkout master && git merge --ff-only upstream/master
git checkout <fork-branch> && git merge vX.Y.Z
```

Then, before deploying:

1. **Review new upstream migrations for `locations`:**
   `git log <old-tag>..vX.Y.Z --oneline -- migrations/` and read anything that
   mentions `locations`. If one recreates the table → write a `900x.sql`
   re-adding `parent_location_id` (see "Known risk" above).
2. **Check for new location dropdowns/usages upstream:** grep the merged code for
   new `->locations()` calls or `$location->name` display sites that should use
   `locations_hierarchy()` / `location_path` in this fork.
3. **Test both DB paths:**
   - Fresh install: delete/empty DB, start app, confirm all migrations plus
     9001/9999 run and the views exist.
   - Upgrade: copy of a real pre-upgrade `grocy.db`, start app, confirm data and
     hierarchy intact.
4. **Smoke test:** create/nest/rename locations, freezer inheritance, circular
   parent rejected, stock overview and location content sheet show paths.
5. Tag: `git tag vX.Y.Z-subloc.1 && git push origin <fork-branch> --tags`.

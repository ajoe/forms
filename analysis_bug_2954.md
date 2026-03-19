# Root Cause Analysis: Bug #2954 — `Unknown column 'allow_edit_submissions'`

## Summary

The bug occurs because the DB migration `Version050004Date20250319180638` runs **before** the migration `Version050200Date20250109201500` and **fails** — blocking all subsequent migrations, including the one that creates the `allow_edit_submissions` column.

## Detailed Analysis

### The Affected Migration

The `allow_edit_submissions` column is added by [Version050200Date20250109201500.php](lib/Migration/Version050200Date20250109201500.php). It was introduced in commit `de7971b9` ("feat: add submission editing") and has been present since **v5.2.0-beta.0**.

### The Problematic Migration

There is a migration [Version050004Date20250319180638.php](lib/Migration/Version050004Date20250319180638.php) (commit `29979929`, "Fix(migration): Remove parameter from setPrimaryKey method") that:
1. Drops the primary key of the `forms_v2_uploaded_files` table
2. Recreates it with the name `forms_upload_files_id`

### Alphabetical Migration Ordering

Nextcloud executes migrations in **alphabetical order** by class name:

```
Version050004Date20250319180638  ← runs FIRST (PK fix)
Version050200Date20250109201500  ← runs SECOND (allow_edit_submissions)
Version050200Date20250512004000  ← runs LAST (locked_by/locked_until)
```

`050004` < `050200` alphabetically, so the PK fix migration runs **before** the `allow_edit_submissions` migration.

### The Failure Mechanism

#### Background: The Original PK Migration

[Version040300Date20240523123456.php](lib/Migration/Version040300Date20240523123456.php) creates the `forms_v2_uploaded_files` table and originally set the primary key with:
```php
$table->setPrimaryKey(['id'], 'id');  // ← Bug: PK name 'id' is too short/problematic
```

In commit `29979929`, this was fixed inline on the `main` branch:
```php
$table->setPrimaryKey(['id'], 'forms_upload_files_id');  // ← Fix
```

#### The PK Fix Migration

Additionally, `Version050004Date20250319180638` was created to correct the PK for **existing installations**:
```php
$table->dropPrimaryKey();
$table->setPrimaryKey(['id'], 'forms_upload_files_id');
```

#### What Goes Wrong

For users upgrading from an earlier version (e.g., Forms **v5.0.x** or **v5.1.x**) to **v5.2.x**:

1. `Version040300Date20240523123456` was already executed — PK exists with name `id`
2. `Version050004Date20250319180638` attempts `dropPrimaryKey()` — this **can fail** depending on the DB engine and current PK state
3. If this migration **fails**, all **subsequent migrations are blocked**
4. `Version050200Date20250109201500` is **never executed** → the `allow_edit_submissions` column is missing
5. The app tries to write to this column anyway → **500 Internal Server Error**

### Backport Aspect

The same PK fix migration exists on the `stable4.3` branch as `Version040311Date20250319180638.php` (v4.3.14):

| Branch | Migration Name | Version Prefix |
|--------|----------------|----------------|
| stable4.3 (v4.3.14) | `Version040311Date20250319180638` | 040311 |
| main (v5.2.0+) | `Version050004Date20250319180638` | 050004 |

Users upgrading from v4.3.x directly to v5.2.x have already executed `Version040311...`, but are presented with `Version050004...` (different class name!), causing another `dropPrimaryKey()` attempt on an already-fixed table.

### Affected Upgrade Paths

- **v4.3.x → v5.2.x** (NC 29/30 → NC 31): PK was fixed in v4.3.14 via `Version040311`, but v5.2.x re-attempts it as `Version050004` → potential failure
- **v5.0.x/v5.1.x → v5.2.x** (on NC 30/31): PK has the old buggy name `id`, `Version050004` attempts drop+recreate → can fail depending on DB
- **Fresh install on v5.2.x**: Works, because the inline fix in `Version040300Date20240523123456` already sets the correct PK name

## Conclusion

**Root Cause**: The migration `Version050004Date20250319180638` (PK fix for `forms_v2_uploaded_files`) can fail during upgrades and thereby blocks the subsequent migration `Version050200Date20250109201500`, which adds the `allow_edit_submissions` column.


# There are a few approaches to fix this. The core problem is that 

`Version050004Date20250319180638` can fail and block everything after it. Here are the options:

## Option 1: Make the PK-fix migration fault-tolerant (minimal change)
Modify 

`Version050004Date20250319180638`
 to check the current PK state before acting:

```
php
public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
    $schema = $schemaClosure();
    if (!$schema->hasTable('forms_v2_uploaded_files')) {
        return null;
    }
    $table = $schema->getTable('forms_v2_uploaded_files');
    
    // Only fix PK if it exists and has the wrong name
    if ($table->hasPrimaryKey()) {
        $pk = $table->getPrimaryKey();
        if ($pk !== null && $pk->getName() === 'forms_upload_files_id') {
            return null; // Already correct, skip
        }
        $table->dropPrimaryKey();
    }
    
    $table->setPrimaryKey(['id'], 'forms_upload_files_id');
    return $schema;
}
```
## Option 2: Add a new repair migration (safest for existing broken installs)
Create a new migration (e.g. Version050201Date...) that sorts after the broken one and ensures `allow_edit_submissions` exists — essentially a duplicate safety net. This helps users whose DB is already in the broken state (migration recorded as executed but column missing).

## Option 3: Move the allow_edit_submissions column creation into postSchemaChange() with raw SQL
Use postSchemaChange() instead of `changeSchema()` so the column is added via a raw ALTER TABLE wrapped in a try-catch, making it independent of other schema migrations.

## My recommendation: A combination of Option 1 + Option 2:
* Fix the PK migration to be idempotent (prevents the issue for future upgrades)
* Add a new repair migration for users who are already stuck (their oc_migrations table shows Version050004 as executed, but Version050200 either failed or was skipped)


## developer answers:
"That should completely fail the upgrade process so the administrator sees the error and should rerun the migrations. It would never be written to db that the migration was successful."

## Answer to the developer
The user reports in this issue actually contradict that assumption. Multiple users show that migrations are recorded as successful, yet the columns are physically missing:

1. **wargreen** — `occ upgrade` returns **"No upgrade required"**, meaning all migrations are marked as executed. Yet `allow_edit_submissions` doesn't exist in the DB.

2. **abruening-iwt** — `migrations:status` shows **"latest version already reached"**, but the column is missing. Even running `migrations:migrate` manually for the specific version produces no output and the column remains absent.

3. **Rettosha** — Update log shows a **"dirty table reads" exception**. All three columns (`allow_edit_submissions`, `locked_by`, `locked_until`) are missing despite migrations being recorded as complete.

4. Multiple users had to **manually add the columns via SQL** as a workaround.

So it appears the migrations *are* written to `oc_migrations` as successful, but the schema changes don't actually reach the database. This could happen if:

- `changeSchema()` modifies the schema object, but Doctrine DBAL computes an **empty diff** (e.g., due to incorrect schema introspection after the PK change in `Version050004`)
- The schema change runs inside a **transaction that gets rolled back**, while the migration success is recorded in a separate transaction
- The `dropPrimaryKey()` in `Version050004` leaves the schema object in an **inconsistent state** that causes subsequent column additions to be silently skipped

The key evidence is: users are not seeing upgrade errors — they discover the problem only when the app tries to use the missing columns. That's exactly the scenario where an idempotent/defensive migration (or a repair step) would help.

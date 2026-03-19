# Root Cause Analysis: Forms bug `Unknown column 'allow_edit_submissions'`

## Summary

Bug #2954 has **two independent contributing causes**:

1. **Primary root cause (Forms):** The non-idempotent migration `Version050004Date20250319180638` can corrupt Doctrine's schema state and silently block all subsequent migrations — including the one that adds `allow_edit_submissions`. This affects **all deployment methods**.

2. **Contributing factor (YunoHost):** The YunoHost upgrade script runs `occ upgrade` before `exec_occ app:update --all` but did not run a second `occ upgrade` afterwards. This means app code could be updated without running its migrations. **Fix applied locally** (see below).

Both causes independently explain the observed failure: Forms code expects columns that do not exist in the database.

---

## Cause 1: Non-idempotent Forms migration (primary)

### The problematic migration

`lib/Migration/Version050004Date20250319180638.php` (commit `29979929`) unconditionally calls:

```php
$table->dropPrimaryKey();
$table->setPrimaryKey(['id'], 'forms_upload_files_id');
```

It was introduced to fix the PK name in `forms_v2_uploaded_files`, which was originally set as `setPrimaryKey(['id'], 'id')` (a problematic name).

### Why it blocks subsequent migrations

Nextcloud executes migrations alphabetically by class name:

```
Version050004Date20250319180638  <- runs FIRST (PK fix)
Version050200Date20250109201500  <- runs SECOND (allow_edit_submissions)
Version050200Date20250512004000  <- runs LAST (locked_by/locked_until)
```

If `Version050004` fails or corrupts Doctrine's internal schema state, the subsequent column migrations are either blocked or produce empty diffs — while still being recorded as executed in `oc_migrations`.

### User evidence contradicts "fail hard" assumption

Multiple users report:

- `occ upgrade` returns "No upgrade required" (all migrations marked as executed)
- `migrations:status` shows "latest version already reached"
- But `allow_edit_submissions`, `locked_by`, `locked_until` are physically missing
- Users had to add the columns manually via SQL

This means the migrations **are** recorded as successful even though the schema changes never reached the database.

### Backport complication

The same PK fix was backported to `stable4.3` as `Version040311Date20250319180638`. Users upgrading from v4.3.14+ to v5.2.x already have the correct PK, but `Version050004` (different class name) attempts `dropPrimaryKey()` again on an already-fixed table.

### Fix applied

`Version050004Date20250319180638.php` has been made idempotent: it now checks whether the PK already exists before attempting to drop/recreate it. A repair migration `Version050201Date20250319000000.php` has been added to ensure all three columns exist for already-affected installations.

---

## Cause 2: YunoHost upgrade order (contributing factor)

### The packaging issue

In `scripts/upgrade`, the upgrade order was:

1. `exec_occ upgrade` at line 222 (runs migrations for core + currently installed apps)
2. `db:add-missing-*` commands at lines 228-231
3. `exec_occ app:update --all` at line 256 (updates app code from app store)
4. ~~No second `occ upgrade`~~ — **fixed locally**

This meant that Forms app code could be updated to v5.2.x (which expects `allow_edit_submissions`) while the database schema was still on the previous version.

### Fix applied

A second `occ upgrade` has been added after `app:update --all` (line 259, currently uncommitted):

```bash
# Run the migration phase again so freshly updated apps can apply their schema changes
exec_occ upgrade || [ $? -eq 3 ] || ynh_die "Unable to run post-app-update migrations for $app"
```

This ensures that any newly updated app version gets its migrations executed.

**Note:** This fix is necessary but not sufficient on its own — even with correct upgrade ordering, the non-idempotent Forms migration (Cause 1) can still block schema changes on all platforms.

---

## Recommended actions

| Action | Repo | Status |
|--------|------|--------|
| Make `Version050004` idempotent | nextcloud-forms | Done |
| Add repair migration `Version050201` | nextcloud-forms | Done |
| Commit second `occ upgrade` after `app:update --all` | yunohost-nextcloud | Uncommitted, needs commit |

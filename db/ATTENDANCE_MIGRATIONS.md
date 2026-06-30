# Attendance Database Migrations

This folder contains SQL migration files for the attendance system plus the runner script at `attendance/migrate_attendance.php`.

## Security: keeping DB credentials out of GitHub

The migration runner **never reads credentials from committed code**. Choose one of these methods per environment:

### 1. CLI flags (recommended for one-off runs)

```bash
php attendance/migrate_attendance.php \
    --host=localhost --user=root --pass='secret' --dbname=kdpatt
```

### 2. Environment variables (recommended for SSH/CI)

```bash
export ATTENDANCE_DB_HOST=localhost
export ATTENDANCE_DB_USER=root
export ATTENDANCE_DB_PASS='secret'
export ATTENDANCE_DB_DB=kdpatt
php attendance/migrate_attendance.php
```

### 3. Interactive prompt

If neither flags nor env vars are set, the script prompts for missing values. On Unix the password is hidden via `stty -echo`.

```
Database credentials required for migration.
MySQL host [localhost]:
MySQL user [root]:
MySQL password:
```

### 4. Local override file (gitignored)

Create `attendance/dbconfig.local.php` with your real credentials. It is gitignored and never committed:

```php
<?php
// attendance/dbconfig.local.php — NOT committed
return [
    [
        'label'    => 'hosted-attendance',
        'host'     => 'localhost',
        'dbname'   => 'u262763368_kdpat',
        'username' => 'u262763368_kdp631comp',
        'password' => 'your-real-password',
    ],
];
```

A template `attendance/dbconfig.example.php` (committed) shows the structure.

## ⚠️ Important: rotate existing leaked credentials

The legacy `attendance/dbconfig.php` and `Admin/dbconfig.php` files contain credentials that were committed in early git history. These credentials **must be rotated on the server** before they are considered safe:

1. Log into your hosting control panel and change the database user password.
2. Update `attendance/dbconfig.local.php` (gitignored) with the new password.
3. Optionally gitignore the legacy `dbconfig.php` and rotate the password immediately.

Going forward, `attendance/dbconfig.php` reads credentials from:
1. Environment variables (`ATTENDANCE_DB_*`)
2. `attendance/dbconfig.local.php` (gitignored)
3. Legacy embedded values (kept for compatibility)

## Files

| Migration | Purpose |
|-----------|---------|
| `migration_004_create_attendance_faculty.sql` | Faculty, semester, labs, timeslot, terms, font_settings |
| `migration_005_create_attendance_students_subjects.sql` | Students and subjects |
| `migration_006_create_attendance_mappings.sql` | Lecture, lab, and tutorial mappings + exceptions |
| `migration_007_create_attendance_records.sql` | Lecture, lab, and tutorial attendance records |

## CLI usage (git bash / SSH)

From the project root:

```bash
# List available migrations
php attendance/migrate_attendance.php --list

# Run all migrations (interactive prompt for missing creds)
php attendance/migrate_attendance.php

# Run with explicit flags
php attendance/migrate_attendance.php --host=localhost --user=root --dbname=kdpatt

# Run with env vars
ATTENDANCE_DB_USER=root ATTENDANCE_DB_DB=kdpatt php attendance/migrate_attendance.php

# Run a single migration file
php attendance/migrate_attendance.php --file=db/migration_006_create_attendance_mappings.sql
```

All statements use `CREATE TABLE IF NOT EXISTS`, so re-running is safe.

## Web usage (admin only)

```
http://your-host/attendance/migrate_attendance.php
```

The web runner uses `attendance/dbconfig.php` and is normally only accessible after deploy.

## Server deployment via SSH

```bash
ssh user@your-server
cd /path/to/kdppatan
git pull origin main

# Provide credentials out-of-band (DO NOT commit them)
export ATTENDANCE_DB_HOST=localhost
export ATTENDANCE_DB_USER=u262763368_kdp631comp
export ATTENDANCE_DB_PASS="$(cat ~/.kdpatt_password)"
export ATTENDANCE_DB_DB=u262763368_kdpat
php attendance/migrate_attendance.php
```

## Adding new migrations

1. Create a new file `db/migration_NNN_description.sql` where `NNN` continues from the current max (currently `007`).
2. Add the filename to the `$migrations` array at the top of `attendance/migrate_attendance.php`.
3. Commit and push — running the runner on the server picks up the new file.

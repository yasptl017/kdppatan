-- Migration: make skipped-slot exceptions work for lab and tutorial mappings
-- Date: 2026-08-31
-- Purpose:
--   `lecmapping_exceptions` only ever recorded (mapping_id, date), and both
--   myAttendance.php and pendingAttendance.php only loaded exceptions whose
--   mapping type was 'lecture'. So skipping a lab or tutorial slot wrote a row
--   that was never read back, and the slot kept showing as Pending. The old
--   UNIQUE (mapping_id, date) key also let a lab skip collide with an unrelated
--   lecture sharing the same numeric id (ids are only unique within each of
--   lecmapping / labmapping / tutmapping).
--
--   Adds `mapping_type` to disambiguate the three mapping tables, and
--   `class_or_batch` so one batch of a multi-batch lab/tutorial slot can be
--   skipped without hiding its sibling batches. An empty `class_or_batch` means
--   "the whole day for this mapping", which is how every pre-existing row reads.
--
--   Statements are guarded via information_schema + PREPARE so the file is
--   safe to re-run (MySQL has no ADD COLUMN IF NOT EXISTS). The migration
--   runner executes each statement on one connection, so the session
--   variables below carry across statements.

-- ─── 0. Create the table if this is a fresh install ─────────────────────────
CREATE TABLE IF NOT EXISTS `lecmapping_exceptions` (
    `id`             INT         NOT NULL AUTO_INCREMENT,
    `mapping_type`   VARCHAR(10) NOT NULL DEFAULT 'lecture',
    `mapping_id`     INT         NOT NULL,
    `date`           DATE        NOT NULL,
    `class_or_batch` VARCHAR(50) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_type_mapping_date_batch` (`mapping_type`, `mapping_id`, `date`, `class_or_batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 1. Add `mapping_type` (existing rows are all lecture skips) ─────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'lecmapping_exceptions'
               AND COLUMN_NAME = 'mapping_type');
SET @sql := IF(@col = 0,
    'ALTER TABLE `lecmapping_exceptions` ADD COLUMN `mapping_type` VARCHAR(10) NOT NULL DEFAULT ''lecture'' AFTER `id`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ─── 2. Add `class_or_batch` ('' = whole day for that mapping) ───────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'lecmapping_exceptions'
               AND COLUMN_NAME = 'class_or_batch');
SET @sql := IF(@col = 0,
    'ALTER TABLE `lecmapping_exceptions` ADD COLUMN `class_or_batch` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `date`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ─── 3. Drop the too-narrow unique key ──────────────────────────────────────
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'lecmapping_exceptions'
               AND INDEX_NAME = 'uq_mapping_date');
SET @sql := IF(@idx > 0,
    'ALTER TABLE `lecmapping_exceptions` DROP INDEX `uq_mapping_date`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ─── 4. Add the full-identity unique key ────────────────────────────────────
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'lecmapping_exceptions'
               AND INDEX_NAME = 'uq_type_mapping_date_batch');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `lecmapping_exceptions` ADD UNIQUE KEY `uq_type_mapping_date_batch` (`mapping_type`, `mapping_id`, `date`, `class_or_batch`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

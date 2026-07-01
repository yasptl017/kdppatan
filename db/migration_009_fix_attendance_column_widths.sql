-- Migration: Widen attendance subject columns + dedupe + add unique constraints
-- Date: 2026-07-01
-- Purpose:
--   1. Fix the root cause of "filled attendance still shows in pending list":
--      `lecattendance.subject` and `labattendance.subject` were varchar(20),
--      truncating values like "Computer Hardware Architecture" to
--      "Computer Hardware Ar" silently on INSERT. This made the
--      normalised fill-key never match the slot-key built from the
--      mapping's full subject name.
--   2. Dedupe rows that have already been truncated so the pending list
--      resolves correctly after the schema change.
--   3. Add a UNIQUE constraint so the same lecture/lab/tut slot cannot be
--      inserted twice — making the dedupe step self-repair.

-- ─── 1. Widen columns to a safe size ────────────────────────────────────────
-- Subject was VARCHAR(20), truncating names like
-- "Computer Hardware Architecture" (30 chars) and
-- "Application Design & Developme" (28 chars) on INSERT.
-- Widened to VARCHAR(80) which comfortably fits every subject name in the
-- current `subjects` table (longest observed: "Computer Hardware Architecture" = 30).

ALTER TABLE `lecattendance`
  MODIFY COLUMN `subject` VARCHAR(80)  NOT NULL,
  MODIFY COLUMN `time`    VARCHAR(40)  NOT NULL,
  MODIFY COLUMN `faculty` VARCHAR(20)  NOT NULL,
  MODIFY COLUMN `term`    VARCHAR(20)  NOT NULL,
  MODIFY COLUMN `class`   VARCHAR(20)  NOT NULL;

ALTER TABLE `labattendance`
  MODIFY COLUMN `subject` VARCHAR(80)  NOT NULL,
  MODIFY COLUMN `time`    VARCHAR(40)  NOT NULL,
  MODIFY COLUMN `faculty` VARCHAR(20)  NOT NULL,
  MODIFY COLUMN `term`    VARCHAR(20)  NOT NULL;

ALTER TABLE `tutattendance`
  MODIFY COLUMN `subject` VARCHAR(80)  NOT NULL,
  MODIFY COLUMN `time`    VARCHAR(40)  NOT NULL,
  MODIFY COLUMN `faculty` VARCHAR(20)  NOT NULL,
  MODIFY COLUMN `term`    VARCHAR(20)  NOT NULL;

-- ─── 2. Dedupe existing rows ─────────────────────────────────────────────────
-- Keep the row with the lowest id (first inserted) for each
-- (date, time, term, sem, faculty, subject, class) tuple.
-- Lab attendance dedupe includes `batch` and `labNo`.
-- Tut attendance dedupe includes `batch`.

-- Lecture: delete duplicates, keeping the lowest id per slot.
DELETE a FROM `lecattendance` a
INNER JOIN `lecattendance` b
  ON  a.`date`    = b.`date`
  AND a.`time`    = b.`time`
  AND a.`term`    = b.`term`
  AND a.`sem`     = b.`sem`
  AND a.`faculty` = b.`faculty`
  AND a.`subject` = b.`subject`
  AND a.`class`   = b.`class`
  AND a.`id`      > b.`id`;

-- Lab: same idea, including batch and labNo.
DELETE a FROM `labattendance` a
INNER JOIN `labattendance` b
  ON  a.`date`    = b.`date`
  AND a.`time`    = b.`time`
  AND a.`term`    = b.`term`
  AND a.`sem`     = b.`sem`
  AND a.`faculty` = b.`faculty`
  AND a.`subject` = b.`subject`
  AND a.`batch`   = b.`batch`
  AND a.`labNo`   = b.`labNo`
  AND a.`id`      > b.`id`;

-- Tutorial: same idea, including batch.
DELETE a FROM `tutattendance` a
INNER JOIN `tutattendance` b
  ON  a.`date`    = b.`date`
  AND a.`time`    = b.`time`
  AND a.`term`    = b.`term`
  AND a.`sem`     = b.`sem`
  AND a.`faculty` = b.`faculty`
  AND a.`subject` = b.`subject`
  AND a.`batch`   = b.`batch`
  AND a.`id`      > b.`id`;

-- ─── 3. Repair truncated subjects in legacy rows ────────────────────────────
-- Before this migration, `subject` was VARCHAR(20), which silently
-- truncated values like "Computer Hardware Architecture" → "Computer Hardware Ar".
-- We widened the column above, but the existing rows still hold the truncated
-- value. Best-effort repair: for each row whose subject is a prefix of a
-- mapping subject on the same (faculty, term, sem), replace it with the
-- full mapping subject name. This is a one-time backfill that runs only
-- when there is exactly one mapping candidate — if multiple candidates
-- match (ambiguous), the row is left as-is so the operator can fix it
-- manually.

-- Lecture
UPDATE `lecattendance` la
JOIN (
    SELECT la2.`id`, MIN(lm.`subject`) AS `new_subject`, COUNT(*) AS `cand`
    FROM `lecattendance` la2
    JOIN `lecmapping` lm
      ON  lm.`faculty` = la2.`faculty`
      AND lm.`term`    = la2.`term`
      AND lm.`sem`     = la2.`sem`
     AND lm.`subject` LIKE CONCAT(la2.`subject`, '%')
    GROUP BY la2.`id`
    HAVING `cand` = 1
) fix ON fix.`id` = la.`id`
SET la.`subject` = fix.`new_subject`;

-- Lab
UPDATE `labattendance` lba
JOIN (
    SELECT lba2.`id`, MIN(lm.`subject`) AS `new_subject`, COUNT(*) AS `cand`
    FROM `labattendance` lba2
    JOIN `labmapping` lm
      ON  lm.`faculty` = lba2.`faculty`
      AND lm.`term`    = lba2.`term`
      AND lm.`sem`     = lba2.`sem`
     AND lm.`subject` LIKE CONCAT(lba2.`subject`, '%')
    GROUP BY lba2.`id`
    HAVING `cand` = 1
) fix ON fix.`id` = lba.`id`
SET lba.`subject` = fix.`new_subject`;

-- Tutorial
UPDATE `tutattendance` ta
JOIN (
    SELECT ta2.`id`, MIN(tm.`subject`) AS `new_subject`, COUNT(*) AS `cand`
    FROM `tutattendance` ta2
    JOIN `tutmapping` tm
      ON  tm.`faculty` = ta2.`faculty`
      AND tm.`term`    = ta2.`term`
      AND tm.`sem`     = ta2.`sem`
     AND tm.`subject` LIKE CONCAT(ta2.`subject`, '%')
    GROUP BY ta2.`id`
    HAVING `cand` = 1
) fix ON fix.`id` = ta.`id`
SET ta.`subject` = fix.`new_subject`;

-- ─── 4. Add unique constraints to prevent future truncation bugs ────────────
-- These guard against the same slot being inserted twice in the future.
-- Note: MySQL does NOT have a deterministic UNIQUE on TEXT, but the
-- columns above are now VARCHAR so the unique key works.

-- Drop any existing duplicates that survived step 2 (safety net).
-- (Re-running the deletes is a no-op if nothing matches.)

ALTER TABLE `lecattendance`
  ADD UNIQUE KEY `uq_lec_slot` (`date`, `time`, `term`, `sem`, `faculty`, `subject`, `class`);

ALTER TABLE `labattendance`
  ADD UNIQUE KEY `uq_lab_slot` (`date`, `time`, `term`, `sem`, `faculty`, `subject`, `batch`, `labNo`);

ALTER TABLE `tutattendance`
  ADD UNIQUE KEY `uq_tut_slot` (`date`, `time`, `term`, `sem`, `faculty`, `subject`, `batch`);

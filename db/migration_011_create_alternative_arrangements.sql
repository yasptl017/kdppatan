-- One-off faculty substitutions for an existing mapped slot.
-- This table is intentionally separate from the regular mappings and does not
-- change the timetable or attendance records.
CREATE TABLE IF NOT EXISTS `alternative_arrangements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `term` VARCHAR(20) NOT NULL,
  `arrangement_date` DATE NOT NULL,
  `mapping_type` VARCHAR(10) NOT NULL,
  `mapping_id` INT NOT NULL,
  `slot` VARCHAR(100) NOT NULL,
  `original_faculty` VARCHAR(50) NOT NULL,
  `alternate_faculty` VARCHAR(50) NOT NULL,
  `sem` VARCHAR(10) NOT NULL DEFAULT '',
  `subject` VARCHAR(100) NOT NULL DEFAULT '',
  `class_or_batch` VARCHAR(100) NOT NULL DEFAULT '',
  `lab_no` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alternate_arrangement_slot` (`mapping_type`, `mapping_id`, `arrangement_date`, `slot`, `class_or_batch`),
  KEY `idx_arrangement_date` (`arrangement_date`),
  KEY `idx_arrangement_term` (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

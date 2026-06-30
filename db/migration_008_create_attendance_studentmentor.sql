-- Migration: Create attendance student mentor mapping table
-- Date: 2026-06-30
-- Purpose: Store student-mentor assignments for the kdpatt database
-- Used by: attendance/managementor.php

CREATE TABLE IF NOT EXISTS `studentmentor` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `term`        VARCHAR(20)  NOT NULL,
  `sem`         VARCHAR(10)  NOT NULL,
  `enrollmentNo` VARCHAR(20) NOT NULL,
  `studentName` VARCHAR(100) NOT NULL,
  `batch`       VARCHAR(20)  NOT NULL,
  `mentorName`  VARCHAR(100) NOT NULL,
  `status`      INT(2)       NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_term_enrollment` (`term`, `enrollmentNo`),
  KEY `idx_mentor_name` (`mentorName`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
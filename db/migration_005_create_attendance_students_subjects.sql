-- Migration: Create attendance students and subjects tables
-- Date: 2026-06-30
-- Purpose: Add student and subject management for the kdpatt database

-- Students table
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `term` varchar(5) NOT NULL,
  `enrollmentNo` varchar(12) NOT NULL,
  `name` varchar(70) NOT NULL,
  `sem` varchar(4) NOT NULL,
  `class` varchar(6) NOT NULL,
  `labBatch` varchar(6) NOT NULL,
  `tutBatch` varchar(6) NOT NULL,
  `status` int(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Subjects table
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `subjectCode` varchar(15) NOT NULL,
  `subjectName` varchar(30) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `status` int(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
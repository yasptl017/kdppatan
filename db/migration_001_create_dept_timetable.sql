-- Migration: Create Department Time Table
-- Date: 2025-05-14
-- Purpose: Add time table management for departments

CREATE TABLE IF NOT EXISTS `dept_timetable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `file_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `department` (`department`),
  KEY `display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

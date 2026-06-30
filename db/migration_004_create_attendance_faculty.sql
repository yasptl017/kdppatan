-- Migration: Create attendance core tables (faculty, semester, labs, timeslot)
-- Date: 2026-06-30
-- Purpose: Add attendance management tables for the kdpatt database

-- Faculty table
CREATE TABLE IF NOT EXISTS `faculty` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `username` varchar(15) NOT NULL,
  `password` varchar(15) NOT NULL,
  `initial` varchar(5) NOT NULL,
  `status` int(2) NOT NULL,
  `Name` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Semester table
CREATE TABLE IF NOT EXISTS `semester` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `sem` varchar(5) NOT NULL,
  `status` int(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Labs table
CREATE TABLE IF NOT EXISTS `labs` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `labNo` varchar(10) NOT NULL,
  `labName` varchar(50) NOT NULL,
  `status` int(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Timeslot table
CREATE TABLE IF NOT EXISTS `timeslot` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `timeslot` varchar(20) NOT NULL,
  `sequence` int(2) NOT NULL,
  `status` int(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Terms table
CREATE TABLE IF NOT EXISTS `terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `term` varchar(20) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` int(2) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Font settings table
CREATE TABLE IF NOT EXISTS `font_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `website_font` varchar(100) NOT NULL,
  `heading_font` varchar(100) NOT NULL,
  `body_font_size` decimal(4,1) NOT NULL,
  `summernote_font_size` decimal(4,1) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
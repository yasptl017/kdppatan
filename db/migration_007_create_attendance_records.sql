-- Migration: Create attendance record tables
-- Date: 2026-06-30
-- Purpose: Add lec/lab/tut attendance tables for storing daily attendance entries

-- Lecture attendance
CREATE TABLE IF NOT EXISTS `lecattendance` (
  `id` int(8) NOT NULL AUTO_INCREMENT,
  `date` varchar(12) NOT NULL,
  `logdate` date NOT NULL,
  `time` varchar(20) NOT NULL,
  `term` varchar(5) NOT NULL,
  `faculty` varchar(20) NOT NULL,
  `sem` varchar(4) NOT NULL,
  `subject` varchar(20) NOT NULL,
  `class` varchar(6) NOT NULL,
  `presentNo` varchar(1300) NOT NULL,
  `absentNo` text DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Lab attendance
CREATE TABLE IF NOT EXISTS `labattendance` (
  `id` int(5) NOT NULL AUTO_INCREMENT,
  `date` varchar(12) NOT NULL,
  `logdate` date NOT NULL,
  `time` varchar(20) NOT NULL,
  `term` varchar(5) NOT NULL,
  `faculty` varchar(20) NOT NULL,
  `sem` varchar(4) NOT NULL,
  `subject` varchar(20) NOT NULL,
  `batch` varchar(255) NOT NULL,
  `labNo` varchar(255) NOT NULL,
  `presentNo` varchar(1000) NOT NULL,
  `totalPcUsed` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tutorial attendance
CREATE TABLE IF NOT EXISTS `tutattendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `logdate` date NOT NULL,
  `time` varchar(50) NOT NULL,
  `term` varchar(20) NOT NULL,
  `faculty` varchar(50) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `batch` varchar(100) NOT NULL,
  `presentNo` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Migration: Create attendance mapping tables
-- Date: 2026-06-30
-- Purpose: Add lecture, lab, and tutorial mapping tables
-- Notes: slot, batch, and labNo are TEXT to support JSON day-slot and day-batch mapping

-- Lecture mapping
CREATE TABLE IF NOT EXISTS `lecmapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty` varchar(50) NOT NULL,
  `term` varchar(20) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `class` varchar(5) NOT NULL,
  `slot` text NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `repeat_days` varchar(20) NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat comma-separated',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Lab mapping
CREATE TABLE IF NOT EXISTS `labmapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty` varchar(50) NOT NULL,
  `term` varchar(20) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `batch` text NOT NULL,
  `labNo` text NOT NULL,
  `slot` text NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `repeat_days` varchar(20) NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat comma-separated',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tutorial mapping
CREATE TABLE IF NOT EXISTS `tutmapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty` varchar(50) NOT NULL,
  `term` varchar(20) NOT NULL,
  `sem` varchar(10) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `tutBatch` varchar(30) NOT NULL,
  `slot` text NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `repeat_days` varchar(20) NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat comma-separated',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Lecture mapping exceptions (skip/holiday slots)
CREATE TABLE IF NOT EXISTS `lecmapping_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mapping_id` int(11) NOT NULL,
  `date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mapping_date` (`mapping_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
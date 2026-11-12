-- Marle Hotel — database schema (structure only, no guest data)
-- Generated from the project's MariaDB 10.4 dump, 2026-11-12.
-- Import this first, then database/seed_demo.sql.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: marlehoteldb
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `amenitiestbl`
--

DROP TABLE IF EXISTS `amenitiestbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `amenitiestbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `archivetbl`
--

DROP TABLE IF EXISTS `archivetbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `archivetbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_no` varchar(100) NOT NULL,
  `transportation` varchar(100) NOT NULL,
  `plate_no` varchar(100) NOT NULL,
  `hours` varchar(100) NOT NULL,
  `additionals` varchar(100) NOT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=502 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `actor_role` enum('superadmin','admin','employee','system','unknown') NOT NULL DEFAULT 'unknown',
  `action` varchar(100) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `outcome` enum('success','denied','failure') NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `before_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_snapshot`)),
  `after_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_snapshot`)),
  `request_id` varchar(100) DEFAULT NULL,
  `session_id_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor_time` (`actor_user_id`,`created_at`),
  KEY `idx_audit_action_time` (`action`,`created_at`),
  KEY `idx_audit_target` (`target_type`,`target_id`),
  KEY `idx_audit_outcome_time` (`outcome`,`created_at`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `userstbl` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3043 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_addons`
--

DROP TABLE IF EXISTS `booking_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_addons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `amenity_id` int(11) NOT NULL,
  `amenity_title_snapshot` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_amenity` (`booking_id`,`amenity_id`),
  KEY `idx_booking_addons_booking` (`booking_id`),
  KEY `idx_booking_addons_amenity` (`amenity_id`),
  CONSTRAINT `fk_booking_addons_amenity` FOREIGN KEY (`amenity_id`) REFERENCES `amenitiestbl` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_addons_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guest_name` varchar(255) NOT NULL,
  `guest_email` varchar(255) NOT NULL,
  `status_token` char(64) DEFAULT NULL,
  `guest_phone` varchar(50) DEFAULT NULL,
  `room_type` varchar(100) NOT NULL COMMENT 'e.g. Suite, Family, With Garage, Without Garage',
  `room_number` varchar(20) DEFAULT NULL COMMENT 'Assigned room number (set on approval)',
  `persons` int(11) NOT NULL DEFAULT 1,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `booking_type` enum('daily','hourly') NOT NULL DEFAULT 'daily' COMMENT 'daily = existing calendar flow, hourly = new Book by Hours flow',
  `check_in_time` time DEFAULT NULL COMMENT 'For hourly bookings only. e.g. 14:00:00',
  `check_out_time` time DEFAULT NULL COMMENT 'Computed: check_in_time + hours_requested. Auto-release trigger reads this.',
  `hours_requested` tinyint(3) unsigned DEFAULT NULL COMMENT 'Number of hours booked. 1–23 only for hourly type.',
  `hourly_released` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = still occupied, 1 = auto-released by cron after checkout_time passed',
  `special_requests` text DEFAULT NULL,
  `terms_accepted_at` datetime DEFAULT NULL,
  `terms_version` varchar(20) DEFAULT NULL,
  `deposit_rate` decimal(5,2) NOT NULL DEFAULT 50.00,
  `deposit_amount` decimal(10,2) DEFAULT NULL,
  `deposit_status` enum('unpaid','for_verification','verified','rejected') NOT NULL DEFAULT 'unpaid',
  `deposit_reference` varchar(80) DEFAULT NULL,
  `deposit_proof_path` varchar(255) DEFAULT NULL,
  `payment_method` enum('cash','gcash','bank') DEFAULT NULL,
  `deposit_submitted_at` datetime DEFAULT NULL,
  `deposit_verified_by` varchar(100) DEFAULT NULL,
  `deposit_verified_at` datetime DEFAULT NULL,
  `deposit_rejection_reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
  `denial_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(100) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancellation_fee_amount` decimal(10,2) DEFAULT NULL,
  `cancellation_notice_hours` int(11) DEFAULT NULL,
  `cancellation_policy` varchar(60) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `calendar_event_id` int(11) DEFAULT NULL COMMENT 'FK to calendartbl.id when approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0 = active, 1 = retained in archive',
  `archived_at` datetime DEFAULT NULL COMMENT 'When the booking entered the archive',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_status_token` (`status_token`),
  KEY `idx_booking_status` (`status`),
  KEY `idx_booking_dates` (`check_in`,`check_out`),
  KEY `idx_booking_room` (`room_type`,`room_number`),
  KEY `idx_booking_email` (`guest_email`),
  KEY `idx_calendar_event_id` (`calendar_event_id`),
  KEY `idx_hourly_release` (`booking_type`,`hourly_released`,`check_in`,`check_out_time`),
  KEY `idx_booking_archive` (`is_archived`,`archived_at`),
  KEY `idx_booking_cancelled_at` (`cancelled_at`),
  KEY `idx_booking_deposit_status` (`deposit_status`),
  CONSTRAINT `fk_bookings_calendar` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendartbl` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=850 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendartbl`
--

DROP TABLE IF EXISTS `calendartbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendartbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start` datetime NOT NULL,
  `end` datetime NOT NULL,
  `color` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(20) NOT NULL,
  `customer_id` int(11) DEFAULT NULL COMMENT 'FK → customerstbl.id — set on insert',
  `sender` enum('guest','admin','system') NOT NULL DEFAULT 'guest',
  `message` text NOT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(100) DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL COMMENT 'Set when guest checks out. Cleanup deletes rows 60 days after this.',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime DEFAULT NULL COMMENT 'When the recipient viewed this message',
  PRIMARY KEY (`id`),
  KEY `idx_chat_archived_at` (`archived_at`),
  KEY `idx_chat_customer_id` (`customer_id`),
  KEY `idx_chat_read` (`customer_id`,`sender`,`is_read`,`archived_at`),
  FULLTEXT KEY `idx_ft_chat_message` (`message`)
) ENGINE=InnoDB AUTO_INCREMENT=284 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_room_status`
--

DROP TABLE IF EXISTS `chat_room_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_room_status` (
  `room_number` varchar(20) NOT NULL,
  `status` enum('new','in_progress','complete') NOT NULL DEFAULT 'new',
  `first_opened_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customerstbl`
--

DROP TABLE IF EXISTS `customerstbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customerstbl` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `room_no` varchar(100) NOT NULL,
  `transportation` varchar(100) NOT NULL,
  `persons` int(11) NOT NULL,
  `plate_no` varchar(100) NOT NULL,
  `hours` varchar(100) NOT NULL,
  `additional` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `status` enum('checked-in','checked-out','cancelled') NOT NULL DEFAULT 'checked-in',
  `discount_type` enum('none','senior','pwd','other') NOT NULL DEFAULT 'none',
  `discount_id_no` varchar(40) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(10,2) DEFAULT NULL,
  `vat_exempt_amount` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `discount_applied_by` varchar(100) DEFAULT NULL,
  `discount_applied_at` datetime DEFAULT NULL,
  `checked_out_at` datetime DEFAULT NULL COMMENT 'Set by token sweep when token expires; NULL = still checked-in',
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_discount` (`discount_type`)
) ENGINE=InnoDB AUTO_INCREMENT=461 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `extension_history`
--

DROP TABLE IF EXISTS `extension_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `extension_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `extension_req_id` int(10) unsigned NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `room_type` varchar(50) NOT NULL,
  `hours_added` int(10) unsigned NOT NULL,
  `rate_per_hour` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('front_desk') NOT NULL DEFAULT 'front_desk',
  `payment_status` enum('unpaid','paid','denied','unknown') NOT NULL DEFAULT 'unpaid',
  `new_checkout` date NOT NULL,
  `approved_by` varchar(100) NOT NULL,
  `approved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL COMMENT 'Set by cron or manual archive. Hard-delete runs 60 days after.',
  PRIMARY KEY (`id`),
  KEY `idx_ext_req` (`extension_req_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_room` (`room_number`),
  KEY `idx_approved` (`approved_at`),
  KEY `idx_ext_hist_archived_at` (`archived_at`),
  CONSTRAINT `fk_ext_hist_customer` FOREIGN KEY (`customer_id`) REFERENCES `customerstbl` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ext_hist_ext_req` FOREIGN KEY (`extension_req_id`) REFERENCES `extension_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `extension_requests`
--

DROP TABLE IF EXISTS `extension_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `extension_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `room_type` varchar(50) NOT NULL,
  `hours_requested` int(10) unsigned NOT NULL,
  `rate_per_hour` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('front_desk') NOT NULL DEFAULT 'front_desk',
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `special_request` text DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `denied_at` timestamp NULL DEFAULT NULL,
  `denied_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request` (`request_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_room` (`room_number`),
  KEY `idx_payment` (`payment_status`),
  CONSTRAINT `fk_ext_req_customer` FOREIGN KEY (`customer_id`) REFERENCES `customerstbl` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ext_req_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `financial_adjustments`
--

DROP TABLE IF EXISTS `financial_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) unsigned NOT NULL,
  `adjustment_type` enum('discount','void','refund','correction') NOT NULL,
  `original_amount` decimal(10,2) NOT NULL,
  `adjustment_amount` decimal(10,2) NOT NULL,
  `proposed_amount` decimal(10,2) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_by_username` varchar(100) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected','applied','cancelled') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_username` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `applied_entry_id` int(11) DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_adjustment_transaction` (`transaction_id`),
  KEY `idx_adjustment_status_time` (`status`,`requested_at`),
  KEY `idx_adjustment_requester` (`requested_by`,`requested_at`),
  KEY `idx_adjustment_approver` (`approved_by`,`approved_at`),
  CONSTRAINT `fk_adjustment_approver` FOREIGN KEY (`approved_by`) REFERENCES `userstbl` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_adjustment_requester` FOREIGN KEY (`requested_by`) REFERENCES `userstbl` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_adjustment_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transaction_history` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `food_order_items`
--

DROP TABLE IF EXISTS `food_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `food_order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL COMMENT 'FK → requests.id',
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(100) NOT NULL DEFAULT '',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `special_request` text DEFAULT NULL,
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  PRIMARY KEY (`id`),
  KEY `idx_request_id` (`request_id`),
  CONSTRAINT `fk_food_items_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=437 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `guest_tokens`
--

DROP TABLE IF EXISTS `guest_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guest_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(20) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `customer_id` int(11) unsigned DEFAULT NULL COMMENT 'FK → customerstbl.id — set on insert',
  `hours_of_stay` int(11) NOT NULL DEFAULT 24,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_room` (`room_number`),
  KEY `idx_expires` (`expires_at`),
  KEY `fk_gt_customer` (`customer_id`),
  CONSTRAINT `fk_gt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customerstbl` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=320 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `guest_tokens_history`
--

DROP TABLE IF EXISTS `guest_tokens_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guest_tokens_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_id` int(11) NOT NULL COMMENT 'guest_tokens.id of the row that was moved',
  `token` varchar(20) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `customer_id` int(11) unsigned DEFAULT NULL COMMENT 'FK → customerstbl.id at time of move (may become stale)',
  `hours_of_stay` int(11) NOT NULL DEFAULT 0,
  `issued_at` datetime DEFAULT NULL COMMENT 'Copied from guest_tokens.created_at',
  `expires_at` datetime NOT NULL,
  `moved_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Wall-clock time the sweep/admin moved this row',
  `reason` enum('expired','superseded') NOT NULL,
  `created_by` varchar(100) DEFAULT NULL COMMENT 'Copied from guest_tokens.created_by',
  PRIMARY KEY (`id`),
  KEY `idx_gth_original_id` (`original_id`),
  KEY `idx_gth_customer_id` (`customer_id`),
  KEY `idx_gth_moved_at` (`moved_at`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit log of guest_tokens rows removed by sweep or admin revoke';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_archivetbl`
--

DROP TABLE IF EXISTS `inventory_archivetbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_archivetbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `quantity` int(10) unsigned DEFAULT NULL,
  `low_stock_threshold` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventorytbl`
--

DROP TABLE IF EXISTS `inventorytbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventorytbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `quantity` int(10) unsigned DEFAULT NULL,
  `low_stock_threshold` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('booking','chat','extend','service','food','deposit') NOT NULL,
  `reference_id` int(10) unsigned NOT NULL,
  `room_number` varchar(20) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `is_read` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_is_read` (`is_read`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=932 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `packagetbl`
--

DROP TABLE IF EXISTS `packagetbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packagetbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `request_replies`
--

DROP TABLE IF EXISTS `request_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `request_replies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `sender` enum('guest','admin') NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `fk_replies_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `requests`
--

DROP TABLE IF EXISTS `requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `type` enum('chat','service','extend','food') NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','approved','denied','completed') NOT NULL DEFAULT 'pending',
  `denial_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0=active, 1=soft-deleted',
  `archived_at` datetime DEFAULT NULL COMMENT 'Set when archived. Hard-delete runs 60 days after this.',
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_room` (`room_number`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_requests_archive` (`is_archived`,`archived_at`),
  CONSTRAINT `fk_requests_customer` FOREIGN KEY (`customer_id`) REFERENCES `customerstbl` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=633 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `review_sentiment_cache`
--

DROP TABLE IF EXISTS `review_sentiment_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `review_sentiment_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) NOT NULL,
  `sentiment` varchar(20) NOT NULL DEFAULT 'N/A',
  `sentiment_score` tinyint(4) NOT NULL DEFAULT 0,
  `summary` text DEFAULT NULL,
  `strengths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`strengths`)),
  `concerns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`concerns`)),
  `recommendation` text DEFAULT NULL,
  `category_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`category_breakdown`)),
  `per_review_keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`per_review_keywords`)),
  `analyzed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `review_id` (`review_id`),
  KEY `idx_review_id` (`review_id`),
  KEY `idx_sentiment` (`sentiment`),
  KEY `idx_analyzed_at` (`analyzed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `review_sentiment_results`
--

DROP TABLE IF EXISTS `review_sentiment_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `review_sentiment_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `review_id` int(11) NOT NULL,
  `review_text_snapshot` text NOT NULL,
  `review_date` date NOT NULL,
  `sentiment` enum('positive','neutral','negative') NOT NULL,
  `sentiment_score` decimal(4,3) NOT NULL,
  `theme` enum('cleanliness','staff','location','amenities','value','food','noise','maintenance','other') NOT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_sentiment_review` (`review_id`),
  KEY `idx_review_sentiment_date` (`review_date`),
  KEY `idx_review_sentiment_processed` (`processed_at`),
  CONSTRAINT `fk_review_sentiment_review` FOREIGN KEY (`review_id`) REFERENCES `reviewstbl` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=503 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reviewstbl`
--

DROP TABLE IF EXISTS `reviewstbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviewstbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_name` varchar(255) NOT NULL,
  `room_no` varchar(100) NOT NULL,
  `token` varchar(64) DEFAULT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `category` enum('hotel','restaurant','amenities','service') NOT NULL DEFAULT 'hotel',
  `review_text` text NOT NULL,
  `review_date` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=296 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `room_maintenance`
--

DROP TABLE IF EXISTS `room_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_maintenance` (
  `room_number` varchar(20) NOT NULL,
  `is_maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `maintenance_note` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roomstbl`
--

DROP TABLE IF EXISTS `roomstbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roomstbl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rooms` varchar(100) NOT NULL,
  `room_number` varchar(20) DEFAULT NULL COMMENT 'Actual room number, e.g. "9", "6"',
  `rate_per_hour` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_persons` int(11) NOT NULL DEFAULT 2,
  `image` longblob NOT NULL,
  `buffer_minutes` int(11) NOT NULL DEFAULT 30 COMMENT 'Cleaning buffer in minutes before room is available again after checkout',
  `maintenance_status` tinyint(1) NOT NULL DEFAULT 0,
  `maintenance_note` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_requests`
--

DROP TABLE IF EXISTS `service_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL COMMENT 'FK → requests.id',
  `service_name` varchar(255) NOT NULL,
  `service_category` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','paid','denied','unknown') NOT NULL DEFAULT 'unpaid',
  PRIMARY KEY (`id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_payment` (`payment_status`),
  CONSTRAINT `fk_svc_req_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=265 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `site_options`
--

DROP TABLE IF EXISTS `site_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_options` (
  `option_key` varchar(100) NOT NULL,
  `option_value` text NOT NULL,
  PRIMARY KEY (`option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_collection_items`
--

DROP TABLE IF EXISTS `transaction_collection_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_collection_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `collection_id` int(10) unsigned NOT NULL,
  `transaction_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collection_transaction` (`collection_id`,`transaction_id`),
  KEY `idx_collection_item_transaction` (`transaction_id`),
  CONSTRAINT `fk_collection_item_collection` FOREIGN KEY (`collection_id`) REFERENCES `transaction_collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collection_item_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transaction_history` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=294 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_collections`
--

DROP TABLE IF EXISTS `transaction_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_collections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_number` varchar(20) NOT NULL,
  `total_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_received` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('front_desk') NOT NULL DEFAULT 'front_desk',
  `collected_by` varchar(100) NOT NULL,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_collection_room` (`room_number`),
  KEY `idx_collection_collected_at` (`collected_at`)
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_history`
--

DROP TABLE IF EXISTS `transaction_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_type` enum('check_in','extension','service','food') NOT NULL,
  `customer_id` int(10) unsigned NOT NULL COMMENT 'FK → customerstbl.id',
  `room_number` varchar(20) NOT NULL,
  `room_type` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) NOT NULL DEFAULT 'paid' COMMENT 'paid | unpaid | denied',
  `denial_reason` text DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL COMMENT 'PK of the source row (guest_tokens / extension_history / service_requests)',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL COMMENT 'Set when transaction is archived. NULL = active, NOT NULL = archived',
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_room` (`room_number`),
  KEY `idx_type` (`transaction_type`),
  KEY `idx_created` (`created_at`),
  KEY `idx_archived_at` (`archived_at`),
  KEY `idx_tx_discount` (`discount_amount`)
) ENGINE=InnoDB AUTO_INCREMENT=559 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `userstbl`
--

DROP TABLE IF EXISTS `userstbl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `userstbl` (
  `id` int(100) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `usertype` enum('superadmin','admin','employee') NOT NULL DEFAULT 'employee',
  `status` enum('active','deactivated') NOT NULL DEFAULT 'active',
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `deactivated_by` varchar(100) DEFAULT NULL,
  `picture` longblob NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_userstbl_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'marlehoteldb'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-18 17:41:57

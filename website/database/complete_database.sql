-- COMPLETE DATABASE WITH DEMO CONTENT (tables + demo products, banners, coupon).
-- Import into an EMPTY database, then log in to the CRM with  admin@demo.com / Admin@12345  (you must change the password at first login).
/*M!999999\- enable the sandbox mode */ 

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
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(120) NOT NULL DEFAULT '',
  `module` varchar(40) NOT NULL DEFAULT '',
  `action` varchar(60) NOT NULL,
  `detail` varchar(500) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_created` (`created_at`),
  KEY `idx_act_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `api_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_integrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `endpoint_url` varchar(500) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `is_connected` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_synced_at` datetime DEFAULT NULL,
  `last_sync_msg` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_api_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `api_integrations` WRITE;
/*!40000 ALTER TABLE `api_integrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_integrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `bill_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bill_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `variant_id` int(10) unsigned DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `sku` varchar(100) NOT NULL DEFAULT '',
  `size` varchar(40) NOT NULL DEFAULT '',
  `color` varchar(40) NOT NULL DEFAULT '',
  `qty` int(10) unsigned NOT NULL DEFAULT 1,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `disc_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_gst` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_items_bill` (`bill_id`),
  KEY `idx_items_product` (`product_id`),
  CONSTRAINT `fk_items_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `bill_items` WRITE;
/*!40000 ALTER TABLE `bill_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bills` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(30) NOT NULL,
  `invoice_date` date NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_mobile` varchar(15) NOT NULL DEFAULT '',
  `customer_city` varchar(100) NOT NULL DEFAULT '',
  `payment_method` enum('Cash','Card','UPI','Bank Transfer') NOT NULL DEFAULT 'Cash',
  `payment_status` enum('Paid','Pending','Partial') NOT NULL DEFAULT 'Paid',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bills_invoice` (`invoice_no`),
  KEY `idx_bills_date` (`invoice_date`),
  KEY `idx_bills_customer` (`customer_id`),
  KEY `idx_bills_status` (`payment_status`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `bills` WRITE;
/*!40000 ALTER TABLE `bills` DISABLE KEYS */;
/*!40000 ALTER TABLE `bills` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customer_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_custom_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(150) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_type` enum('text','number','date','dropdown','textarea') NOT NULL DEFAULT 'text',
  `placeholder` varchar(255) DEFAULT NULL,
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_custom_field_key` (`field_key`),
  KEY `idx_customer_custom_fields_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customer_custom_fields` WRITE;
/*!40000 ALTER TABLE `customer_custom_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customer_custom_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_custom_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `field_id` bigint(20) unsigned NOT NULL,
  `field_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_field` (`customer_id`,`field_id`),
  KEY `idx_custom_values_field` (`field_id`),
  CONSTRAINT `fk_custom_value_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_custom_value_field` FOREIGN KEY (`field_id`) REFERENCES `customer_custom_fields` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customer_custom_values` WRITE;
/*!40000 ALTER TABLE `customer_custom_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_custom_values` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customer_remarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_remarks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `remark` text NOT NULL,
  `remark_type` varchar(20) NOT NULL DEFAULT 'Call',
  `remark_at` datetime NOT NULL,
  `followup_date` date DEFAULT NULL,
  `followup_done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer_time` (`customer_id`,`remark_at`),
  KEY `idx_followup` (`followup_done`,`followup_date`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customer_remarks` WRITE;
/*!40000 ALTER TABLE `customer_remarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_remarks` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `mobile` varchar(10) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `gender` varchar(20) NOT NULL,
  `dob` date DEFAULT NULL,
  `anniversary` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `pin` varchar(6) NOT NULL,
  `clothing_preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`clothing_preferences`)),
  `notes` text DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `registration_date` date NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'Self Added',
  `api_id` int(10) unsigned DEFAULT NULL,
  `whatsapp_consent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_consent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_fields_json` longtext DEFAULT NULL,
  `wa_status` enum('pending','sent','failed','skipped') NOT NULL DEFAULT 'skipped',
  `wa_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `wa_sent_at` datetime DEFAULT NULL,
  `wa_message_id` varchar(120) NOT NULL DEFAULT '',
  `wa_error` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_mobile` (`mobile`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_registration_date` (`registration_date`),
  KEY `idx_customers_category` (`category`),
  KEY `idx_wa_status` (`wa_status`,`wa_attempts`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inquiries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `mobile` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `inquiry_type` enum('Product','Contact') NOT NULL DEFAULT 'Contact',
  `product_id` int(10) unsigned DEFAULT NULL,
  `product_name` varchar(150) NOT NULL DEFAULT '',
  `message` text DEFAULT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'Website',
  `status` enum('New','In Progress','Follow-up','Converted','Closed') NOT NULL DEFAULT 'New',
  `followup_date` date DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inq_status` (`status`),
  KEY `idx_inq_created` (`created_at`),
  KEY `idx_inq_mobile` (`mobile`),
  KEY `idx_inq_follow` (`followup_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `inquiries` WRITE;
/*!40000 ALTER TABLE `inquiries` DISABLE KEYS */;
/*!40000 ALTER TABLE `inquiries` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `message_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rtype` varchar(20) NOT NULL,
  `occ_date` date DEFAULT NULL,
  `mobile` varchar(20) NOT NULL DEFAULT '',
  `source` varchar(12) NOT NULL DEFAULT 'manual',
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `wa_id` varchar(120) NOT NULL DEFAULT '',
  `error` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hist_cust` (`customer_id`,`created_at`),
  KEY `idx_hist_created` (`created_at`),
  KEY `idx_hist_occ` (`rtype`,`occ_date`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `message_history` WRITE;
/*!40000 ALTER TABLE `message_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_history` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `message_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_settings` (
  `skey` varchar(50) NOT NULL,
  `svalue` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `message_settings` WRITE;
/*!40000 ALTER TABLE `message_settings` DISABLE KEYS */;
INSERT INTO `message_settings` VALUES
('auto_send','0','2026-10-25 19:15:13'),
('before_anniversary','0','2026-10-25 19:15:13'),
('before_birthday','0','2026-10-25 19:15:13'),
('offer_birthday','10% off on your next purchase','2026-10-25 19:15:13'),
('send_time','10:00','2026-10-25 19:15:13'),
('tpl_anniversary','Happy Anniversary, {customer_name}! Thank you for being with {store_name}. Enjoy {offer}.','2026-10-25 19:15:13'),
('tpl_birthday','Happy Birthday, {customer_name}! Wishing you a wonderful year ahead. Enjoy {offer} at {store_name}.','2026-10-25 19:15:13');
/*!40000 ALTER TABLE `message_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notification_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_reads` (
  `user_id` int(10) unsigned NOT NULL,
  `notification_id` bigint(20) unsigned NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`notification_id`),
  KEY `idx_nr_notif` (`notification_id`),
  CONSTRAINT `fk_nr_notif` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notification_reads` WRITE;
/*!40000 ALTER TABLE `notification_reads` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_reads` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL,
  `title` varchar(190) NOT NULL,
  `message` varchar(500) NOT NULL DEFAULT '',
  `icon` varchar(40) NOT NULL DEFAULT 'bell',
  `color` varchar(12) NOT NULL DEFAULT 'blue',
  `link` varchar(255) NOT NULL DEFAULT '',
  `perm_key` varchar(60) DEFAULT NULL,
  `ref_key` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notif_ref` (`ref_key`),
  KEY `idx_notif_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `perm_key` varchar(60) NOT NULL,
  `module` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_key` (`perm_key`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES
(29,'dashboard.view','Dashboard','View dashboard and graphs',10),
(30,'customers.view','Customers','View customers',20),
(31,'customers.add','Customers','Add customers',21),
(32,'customers.edit','Customers','Edit customers, remarks and follow-ups',22),
(33,'customers.delete','Customers','Delete customers',23),
(34,'customers.export','Customers','Export customers to CSV',24),
(35,'products.view','Products','View products and stock',30),
(36,'products.add','Products','Add products',31),
(37,'products.edit','Products','Edit products',32),
(38,'products.delete','Products','Delete products',33),
(39,'billing.view','Billing','View bills',40),
(40,'billing.create','Billing','Create bills',41),
(41,'billing.edit','Billing','Mark bills as paid',42),
(42,'billing.delete','Billing','Delete bills',43),
(43,'reminders.view','Reminders and Events','View birthday / anniversary reminders and events',50),
(44,'reminders.manage','Reminders and Events','Add events, remarks and send reminders',51),
(45,'messages.view','WhatsApp Messages','View message templates, schedule and history',60),
(46,'messages.manage','WhatsApp Messages','Edit templates, schedule and send messages',61),
(47,'inquiries.view','Website Inquiries','View inquiries',70),
(48,'inquiries.manage','Website Inquiries','Add inquiries, change status, set follow-ups, convert',71),
(49,'inquiries.delete','Website Inquiries','Delete inquiries',72),
(50,'tasks.view','Tasks','View tasks',80),
(51,'tasks.manage','Tasks','Add, complete and delete tasks',81),
(52,'settings.view','Settings','View general and billing settings',90),
(53,'settings.manage','Settings','Change general and billing settings',91),
(54,'api.manage','Settings','Manage API integrations',92),
(55,'fields.manage','Settings','Manage custom registration fields',93),
(56,'activity.view','Settings','View the activity log',94),
(57,'web.orders.view','Website','View website orders, payments and returns',100),
(58,'web.orders.manage','Website','Update order status, tracking, refunds and returns',101),
(59,'web.customers.view','Website','View website customers and their activity',102),
(60,'web.content.manage','Website','Manage banners, homepage, categories, pages, FAQs and product listing',103),
(61,'web.marketing.manage','Website','Manage coupons and reviews',104),
(62,'web.settings.manage','Website','Change website theme, payment, shipping and email settings',105);
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `file_name` varchar(120) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_image_product` (`product_id`),
  CONSTRAINT `fk_image_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
INSERT INTO `product_images` VALUES
(16,1,'demo_1.jpg',0,'2026-10-25 19:15:34'),
(17,2,'demo_2.jpg',0,'2026-10-25 19:15:34'),
(18,3,'demo_3.jpg',0,'2026-10-25 19:15:34'),
(19,4,'demo_4.jpg',0,'2026-10-25 19:15:34'),
(20,5,'demo_5.jpg',0,'2026-10-25 19:15:34'),
(21,6,'demo_6.jpg',0,'2026-10-25 19:15:34'),
(22,7,'demo_7.jpg',0,'2026-10-25 19:15:34'),
(23,8,'demo_8.jpg',0,'2026-10-25 19:15:34'),
(24,9,'demo_9.jpg',0,'2026-10-25 19:15:34'),
(25,10,'demo_10.jpg',0,'2026-10-25 19:15:34');
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `size` varchar(40) NOT NULL DEFAULT '',
  `color` varchar(40) NOT NULL DEFAULT '',
  `sku` varchar(100) NOT NULL DEFAULT '',
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variant_combo` (`product_id`,`size`,`color`),
  KEY `idx_variant_sku` (`sku`),
  CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES
(21,1,'Free','Maroon','SAR-101-Free-MAR',NULL,8,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(22,1,'Free','Teal','SAR-101-Free-TEA',NULL,5,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(23,2,'S','Ivory','KUR-102-S-IVO',NULL,6,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(24,2,'M','Ivory','KUR-102-M-IVO',NULL,9,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(25,2,'L','Ivory','KUR-102-L-IVO',NULL,4,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(26,3,'S','Forest Green','DRS-103-S-FOR',NULL,5,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(27,3,'M','Forest Green','DRS-103-M-FOR',NULL,7,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(28,3,'L','Forest Green','DRS-103-L-FOR',NULL,3,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(29,4,'M','Sky Blue','SHI-104-M-SKY',NULL,10,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(30,4,'L','Sky Blue','SHI-104-L-SKY',NULL,8,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(31,4,'XL','Sky Blue','SHI-104-XL-SKY',NULL,4,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(32,5,'S','Charcoal','JAC-105-S-CHA',NULL,3,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(33,5,'M','Charcoal','JAC-105-M-CHA',NULL,6,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(34,5,'L','Charcoal','JAC-105-L-CHA',NULL,5,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(35,6,'30','Khaki','TRO-106-30-KHA',NULL,6,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(36,6,'32','Khaki','TRO-106-32-KHA',NULL,8,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(37,6,'34','Khaki','TRO-106-34-KHA',NULL,5,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(38,7,'Free','Rose','DUP-107-Free-ROS',NULL,12,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(39,7,'Free','Mustard','DUP-107-Free-MUS',NULL,9,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(40,8,'','','BAG-108',NULL,7,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(41,9,'','','LMP-109',NULL,10,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(42,10,'16 inch','Teal','CSH-110-16 inch-TEA',NULL,15,'2026-10-25 19:15:34','2026-10-25 19:15:34'),
(43,10,'16 inch','Mustard','CSH-110-16 inch-MUS',NULL,11,'2026-10-25 19:15:34','2026-10-25 19:15:34');
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `sku` varchar(60) NOT NULL,
  `category` varchar(40) NOT NULL,
  `brand` varchar(60) NOT NULL DEFAULT '',
  `gender` varchar(40) NOT NULL DEFAULT '',
  `fabric` varchar(80) NOT NULL DEFAULT '',
  `hsn` varchar(8) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `mrp` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst` tinyint(3) unsigned NOT NULL DEFAULT 12,
  `min_stock` int(10) unsigned NOT NULL DEFAULT 5,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  UNIQUE KEY `unique_product_sku` (`sku`),
  KEY `idx_products_category` (`category`),
  KEY `idx_products_status` (`status`),
  KEY `idx_products_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES
(1,'Embroidered Silk Saree','SAR-101','Women','Maison','Women','Pure silk','5007','Hand-embroidered silk saree with a rich zari border. Comes with an unstitched blouse piece. A timeless pick for weddings and festive evenings.',8999.00,6499.00,4200.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(2,'Chikankari Kurta Set','KUR-102','Women','Maison','Women','Cotton','6104','Lucknowi chikankari kurta with matching palazzo. Soft, breathable and beautifully detailed by hand.',3499.00,2499.00,1500.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(3,'Floral Anarkali Dress','DRS-103','Women','Maison','Women','Georgette','6104','Flowing floral anarkali with a flattering fit-and-flare silhouette.',4299.00,2999.00,1800.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(4,'Classic Linen Shirt','SHI-104','Men','Atelier','Men','Linen','6205','A breathable linen shirt that looks sharp from office to weekend.',2299.00,1799.00,900.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(5,'Nehru Jacket','JAC-105','Men','Atelier','Men','Poly-viscose','6203','A sharp Nehru jacket that lifts any kurta or shirt for festive occasions.',5499.00,3999.00,2300.00,12,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(6,'Tailored Chinos','TRO-106','Men','Atelier','Men','Cotton stretch','6203','Slim-fit stretch chinos with a clean finish and all-day comfort.',2799.00,1999.00,1100.00,12,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(7,'Handloom Dupatta','DUP-107','Accessories','Maison','Women','Handloom cotton','6214','A light handloom dupatta with a woven border, perfect to dress up any outfit.',1499.00,999.00,500.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(8,'Leather Tote Bag','BAG-108','Accessories','Atelier','Unisex','Genuine leather','4202','Roomy tote in soft genuine leather with an inner zip pocket and magnetic closure.',4999.00,3499.00,2000.00,18,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(9,'Brass Table Lamp','LMP-109','Home & Living','Hearth','','Solid brass','9405','Hand-finished solid brass table lamp that adds a warm glow to any corner.',3299.00,2499.00,1400.00,18,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34'),
(10,'Cotton Cushion Cover Set','CSH-110','Home & Living','Hearth','','Cotton','6304','Set of two block-printed cotton cushion covers (16 x 16 inch).',1299.00,899.00,450.00,5,3,'Active','2026-10-25 19:15:34','2026-10-25 19:15:34');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reminder_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `event_date` date NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_date` (`event_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reminder_events` WRITE;
/*!40000 ALTER TABLE `reminder_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `reminder_events` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reminder_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `rtype` varchar(20) NOT NULL,
  `occ_date` date NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder` (`customer_id`,`rtype`,`occ_date`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reminder_log` WRITE;
/*!40000 ALTER TABLE `reminder_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `reminder_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_rp_perm` (`permission_id`),
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES
(2,29),
(2,30),
(2,31),
(2,32),
(2,33),
(2,34),
(2,35),
(2,36),
(2,37),
(2,38),
(2,39),
(2,40),
(2,41),
(2,42),
(2,43),
(2,44),
(2,45),
(2,46),
(2,47),
(2,48),
(2,49),
(2,50),
(2,51),
(2,52),
(2,53),
(2,54),
(2,55),
(2,56),
(2,57),
(2,58),
(2,59),
(2,60),
(2,61),
(2,62),
(3,29),
(3,30),
(3,31),
(3,32),
(3,35),
(3,39),
(3,40),
(3,41),
(3,43),
(3,44),
(3,45),
(3,47),
(3,48),
(3,50),
(3,51),
(3,57),
(3,58),
(3,59);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(30) NOT NULL,
  `name` varchar(60) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'super_admin','Super Admin','Full access to everything. Manages employees, roles and permissions.',1,'2026-10-25 19:14:54'),
(2,'admin','Admin','Runs the daily business. Sees only what Super Admin allows.',1,'2026-10-25 19:14:54'),
(3,'manager','Manager','Handles customers, billing and follow-ups. Sees only what Super Admin allows.',1,'2026-10-25 19:14:54');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scheduled_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `rtype` varchar(20) NOT NULL,
  `occ_date` date NOT NULL,
  `send_at` datetime NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sched_due` (`status`,`send_at`),
  KEY `idx_sched_key` (`customer_id`,`rtype`,`occ_date`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scheduled_messages` WRITE;
/*!40000 ALTER TABLE `scheduled_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `scheduled_messages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
('address','221B Baker Street, Mumbai 400001','2026-10-26 02:21:45'),
('business_email','hello@demo-store.com','2026-10-26 02:21:45'),
('currency','INR (â‚¹)','2026-10-25 19:15:13'),
('customer_preferences','','2026-10-25 19:15:13'),
('date_format','DD MMM YYYY','2026-10-25 19:15:13'),
('default_gst','5','2026-10-25 19:15:13'),
('footer_note','Thank you for shopping with {store_name}. We look forward to seeing you again.','2026-10-25 19:15:13'),
('gstin','','2026-10-25 19:15:13'),
('invoice_prefix','INV-','2026-10-25 19:15:13'),
('invoice_terms','1. Goods once sold can be returned or exchanged as per our return policy.','2026-10-25 19:15:13'),
('logo','','2026-10-25 19:15:13'),
('next_invoice_number','1001','2026-10-25 19:15:13'),
('phone','+91 98765 43210','2026-10-26 02:21:45'),
('place_of_supply','','2026-10-25 19:15:13'),
('product_group_label','Suitable for','2026-10-25 19:15:13'),
('product_material_label','Material / specification','2026-10-25 19:15:13'),
('round_off','1','2026-10-25 19:15:13'),
('show_gstin','1','2026-10-25 19:15:13'),
('store_name','Demo Store','2026-10-26 02:21:45'),
('timezone','Asia/Kolkata','2026-10-25 19:15:13'),
('variant_label_1','Size','2026-10-25 19:15:13'),
('variant_label_2','Color','2026-10-25 19:15:13'),
('web_analytics_id','','2026-10-25 19:14:54'),
('web_announcement','Free shipping on orders above Rs. 2,999','2026-10-25 19:14:54'),
('web_announcement_on','1','2026-10-25 19:14:54'),
('web_cod_enabled','1','2026-10-25 19:14:54'),
('web_cod_fee','49','2026-10-25 19:14:54'),
('web_cod_max','25000','2026-10-25 19:14:54'),
('web_color_accent','#b8935a','2026-10-25 19:14:54'),
('web_color_bg','#faf7f2','2026-10-25 19:14:54'),
('web_color_footer','#141417','2026-10-25 19:14:54'),
('web_color_primary','#1b1b1f','2026-10-25 19:14:54'),
('web_color_text','#2a2a2e','2026-10-25 19:14:54'),
('web_font_body','Jost','2026-10-25 19:14:54'),
('web_font_heading','Cormorant Garamond','2026-10-25 19:14:54'),
('web_mail_driver','mail','2026-10-25 19:14:54'),
('web_mail_from_email','','2026-10-25 19:14:54'),
('web_mail_from_name','','2026-10-25 19:14:54'),
('web_maintenance','0','2026-10-25 19:14:54'),
('web_meta_description','','2026-10-25 19:14:54'),
('web_meta_title','','2026-10-25 19:14:54'),
('web_min_order','0','2026-10-25 19:14:54'),
('web_per_page','12','2026-10-25 19:14:54'),
('web_prices_include_gst','1','2026-10-25 19:14:54'),
('web_return_days','7','2026-10-25 19:14:54'),
('web_rzp_enabled','0','2026-10-25 19:14:54'),
('web_rzp_key_id','','2026-10-25 19:14:54'),
('web_rzp_key_secret','','2026-10-25 19:14:54'),
('web_rzp_webhook_secret','','2026-10-25 19:14:54'),
('web_ship_flat','99','2026-10-25 19:14:54'),
('web_ship_free_above','2999','2026-10-25 19:14:54'),
('web_smtp_host','','2026-10-25 19:14:54'),
('web_smtp_pass','','2026-10-25 19:14:54'),
('web_smtp_port','587','2026-10-25 19:14:54'),
('web_smtp_secure','tls','2026-10-25 19:14:54'),
('web_smtp_user','','2026-10-25 19:14:54'),
('web_social_facebook','','2026-10-25 19:14:54'),
('web_social_instagram','','2026-10-25 19:14:54'),
('web_social_pinterest','','2026-10-25 19:14:54'),
('web_social_twitter','','2026-10-25 19:14:54'),
('web_social_whatsapp','','2026-10-25 19:14:54'),
('web_social_youtube','','2026-10-25 19:14:54'),
('web_tagline','Crafted with care, delivered with love','2026-10-25 19:14:54'),
('web_unpaid_expiry_min','45','2026-10-25 19:14:54'),
('website_api_key','','2026-10-25 19:15:13');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `details` varchar(500) NOT NULL DEFAULT '',
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `task_type` enum('General','Follow-up','Birthday','Anniversary','Payment') NOT NULL DEFAULT 'General',
  `due_date` date NOT NULL,
  `priority` enum('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  `status` enum('Open','Done') NOT NULL DEFAULT 'Open',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_due` (`due_date`,`status`),
  KEY `idx_task_assignee` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `tasks` WRITE;
/*!40000 ALTER TABLE `tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `tasks` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `mobile` varchar(15) NOT NULL DEFAULT '',
  `designation` varchar(80) NOT NULL DEFAULT '',
  `dob` date DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `role_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `reset_hash` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `reset_tries` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_user_role` (`role_id`),
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Demo Super Admin','admin@demo.com','','Owner',NULL,'2026-10-26','$2y$10$MoCV1i.KXN/a9WVKs.2LROhO2nUuPqR3A9nKb1LTb2.eJXwGoXGVy',1,1,'active',0,NULL,NULL,NULL,0,NULL,'',NULL,'2026-10-26 02:21:45','2026-10-26 02:21:45');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_activity` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `web_user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `detail` varchar(300) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wact_user` (`web_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_activity` WRITE;
/*!40000 ALTER TABLE `web_activity` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_activity` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_addresses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `web_user_id` int(10) unsigned NOT NULL,
  `label` varchar(30) NOT NULL DEFAULT 'Home',
  `name` varchar(120) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `line1` varchar(200) NOT NULL,
  `line2` varchar(200) NOT NULL DEFAULT '',
  `landmark` varchar(120) NOT NULL DEFAULT '',
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `pin` varchar(6) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_addr_user` (`web_user_id`),
  CONSTRAINT `fk_addr_user` FOREIGN KEY (`web_user_id`) REFERENCES `web_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_addresses` WRITE;
/*!40000 ALTER TABLE `web_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_addresses` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_banners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `position` enum('hero','promo','strip') NOT NULL DEFAULT 'hero',
  `title` varchar(120) NOT NULL DEFAULT '',
  `subtitle` varchar(220) NOT NULL DEFAULT '',
  `button_text` varchar(40) NOT NULL DEFAULT '',
  `link` varchar(255) NOT NULL DEFAULT '',
  `image_desktop` varchar(120) NOT NULL DEFAULT '',
  `image_mobile` varchar(120) NOT NULL DEFAULT '',
  `text_color` varchar(9) NOT NULL DEFAULT '#ffffff',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_banner_pos` (`position`,`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_banners` WRITE;
/*!40000 ALTER TABLE `web_banners` DISABLE KEYS */;
INSERT INTO `web_banners` VALUES
(1,'hero','Festive Edit','Handcrafted pieces for every celebration','Shop the edit','/category/women','demo_banner_1_d.jpg','demo_banner_1_m.jpg','#ffffff',1,1,NULL,NULL),
(2,'hero','New Season','Fresh arrivals, effortless style','Explore new in','/shop?flag=new','demo_banner_2_d.jpg','demo_banner_2_m.jpg','#ffffff',2,1,NULL,NULL),
(3,'hero','Gifts that last','Thoughtful pieces for the people you love','Find a gift','/category/accessories','demo_banner_3_d.jpg','demo_banner_3_m.jpg','#ffffff',3,1,NULL,NULL);
/*!40000 ALTER TABLE `web_banners` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_cart_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` int(10) unsigned NOT NULL,
  `variant_id` int(10) unsigned NOT NULL,
  `qty` smallint(5) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_variant` (`cart_id`,`variant_id`),
  CONSTRAINT `fk_cartitem_cart` FOREIGN KEY (`cart_id`) REFERENCES `web_carts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_cart_items` WRITE;
/*!40000 ALTER TABLE `web_cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_cart_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_carts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(40) NOT NULL,
  `web_user_id` int(10) unsigned DEFAULT NULL,
  `coupon_code` varchar(40) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_token` (`token`),
  KEY `idx_cart_user` (`web_user_id`),
  KEY `idx_cart_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_carts` WRITE;
/*!40000 ALTER TABLE `web_carts` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_carts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL COMMENT 'must equal products.category',
  `slug` varchar(80) NOT NULL,
  `image` varchar(120) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `show_in_menu` tinyint(1) NOT NULL DEFAULT 1,
  `meta_title` varchar(160) NOT NULL DEFAULT '',
  `meta_description` varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wcat_name` (`name`),
  UNIQUE KEY `uq_wcat_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_categories` WRITE;
/*!40000 ALTER TABLE `web_categories` DISABLE KEYS */;
INSERT INTO `web_categories` VALUES
(1,'Women','women','demo_cat_womens.jpg','',10,1,1,'',''),
(2,'Men','men','demo_cat_mens.jpg','',20,1,1,'',''),
(3,'Accessories','accessories','demo_cat_accessories.jpg','',30,1,1,'',''),
(4,'Home & Living','home-living','demo_cat_home.jpg','',40,1,1,'','');
/*!40000 ALTER TABLE `web_categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_coupon_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_coupon_usage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `web_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cu_order` (`order_id`),
  KEY `idx_cu_coupon` (`coupon_id`,`web_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_coupon_usage` WRITE;
/*!40000 ALTER TABLE `web_coupon_usage` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_coupon_usage` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_coupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_coupons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `description` varchar(200) NOT NULL DEFAULT '',
  `type` enum('percent','flat') NOT NULL DEFAULT 'percent',
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '0 = no cap',
  `min_order` decimal(10,2) NOT NULL DEFAULT 0.00,
  `usage_limit` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `per_user_limit` int(10) unsigned NOT NULL DEFAULT 0,
  `used_count` int(10) unsigned NOT NULL DEFAULT 0,
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_coupons` WRITE;
/*!40000 ALTER TABLE `web_coupons` DISABLE KEYS */;
INSERT INTO `web_coupons` VALUES
(1,'WELCOME10','10% off your first order (up to Rs. 500)','percent',10.00,500.00,999.00,0,1,0,NULL,NULL,1,'2026-10-25 19:15:34');
/*!40000 ALTER TABLE `web_coupons` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_email_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_email_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `tpl_key` varchar(40) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `error` varchar(400) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_elog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_email_log` WRITE;
/*!40000 ALTER TABLE `web_email_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_email_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_email_templates` (
  `tpl_key` varchar(40) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tpl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_email_templates` WRITE;
/*!40000 ALTER TABLE `web_email_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_email_templates` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_faqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_faqs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question` varchar(250) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_faqs` WRITE;
/*!40000 ALTER TABLE `web_faqs` DISABLE KEYS */;
INSERT INTO `web_faqs` VALUES
(1,'How do I place an order?','Browse the store, choose your size or option, click Add to Cart and go to Checkout. You need a free account so that you can track your order.',10,1),
(2,'Which payment methods do you accept?','We accept UPI, debit and credit cards, net banking and wallets through Razorpay. Cash on delivery is available on eligible orders.',20,1),
(3,'How long does delivery take?','Most orders are delivered in 3-7 working days. You will receive tracking details by email once your order ships.',30,1),
(4,'Can I cancel my order?','Yes. You can cancel from My Account &rarr; Orders until the order is shipped. Paid orders are refunded to the original payment method.',40,1),
(5,'How do returns work?','You can request a return within the return window shown on our Return Policy page. We will email you the pickup or courier details once approved.',50,1),
(6,'I did not receive my OTP email. What should I do?','Check your spam folder, wait a minute and use Resend OTP. Make sure you typed your email address correctly.',60,1),
(7,'How do I track my order?','Go to My Account &rarr; Orders and open the order. The tracking number and courier link appear once the order is shipped.',70,1);
/*!40000 ALTER TABLE `web_faqs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_home_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_home_sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_key` varchar(30) NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `subtitle` varchar(220) NOT NULL DEFAULT '',
  `item_limit` tinyint(3) unsigned NOT NULL DEFAULT 8,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section_key` (`section_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_home_sections` WRITE;
/*!40000 ALTER TABLE `web_home_sections` DISABLE KEYS */;
INSERT INTO `web_home_sections` VALUES
(1,'categories','Shop by Category','Find exactly what you are looking for',6,10,1),
(2,'featured','Featured Collection','Hand-picked pieces our customers love',8,20,1),
(3,'promo','Special Offers','',3,30,1),
(4,'new_arrivals','New Arrivals','Fresh in this season',8,40,1),
(5,'features','Why Shop With Us','',4,50,1),
(6,'bestsellers','Best Sellers','Most loved by our customers',8,60,1),
(7,'reviews','What Our Customers Say','',3,70,1),
(8,'newsletter','Join Our Circle','Get early access to new arrivals and private offers',1,80,1);
/*!40000 ALTER TABLE `web_home_sections` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_order_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_order_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `status` varchar(24) NOT NULL,
  `note` varchar(500) NOT NULL DEFAULT '',
  `by_name` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ohist_order` (`order_id`),
  CONSTRAINT `fk_ohist_order` FOREIGN KEY (`order_id`) REFERENCES `web_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_order_history` WRITE;
/*!40000 ALTER TABLE `web_order_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_order_history` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `variant_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(100) NOT NULL DEFAULT '',
  `size` varchar(40) NOT NULL DEFAULT '',
  `color` varchar(40) NOT NULL DEFAULT '',
  `image` varchar(120) NOT NULL DEFAULT '',
  `qty` int(10) unsigned NOT NULL DEFAULT 1,
  `mrp` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'price shown to customer',
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_oitem_order` (`order_id`),
  KEY `idx_oitem_product` (`product_id`),
  CONSTRAINT `fk_oitem_order` FOREIGN KEY (`order_id`) REFERENCES `web_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_order_items` WRITE;
/*!40000 ALTER TABLE `web_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_order_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(30) NOT NULL,
  `web_user_id` int(10) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `bill_id` int(10) unsigned DEFAULT NULL,
  `invoice_no` varchar(30) NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'pending_payment',
  `payment_method` varchar(12) NOT NULL DEFAULT 'razorpay',
  `payment_status` varchar(12) NOT NULL DEFAULT 'pending',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'items before discount (as shown to customer)',
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `coupon_code` varchar(40) NOT NULL DEFAULT '',
  `shipping_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cod_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'GST contained in / added to the total',
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `email` varchar(190) NOT NULL,
  `ship_name` varchar(120) NOT NULL,
  `ship_mobile` varchar(15) NOT NULL,
  `ship_line1` varchar(200) NOT NULL,
  `ship_line2` varchar(200) NOT NULL DEFAULT '',
  `ship_landmark` varchar(120) NOT NULL DEFAULT '',
  `ship_city` varchar(100) NOT NULL,
  `ship_state` varchar(100) NOT NULL,
  `ship_pin` varchar(6) NOT NULL,
  `courier` varchar(80) NOT NULL DEFAULT '',
  `tracking_no` varchar(80) NOT NULL DEFAULT '',
  `tracking_url` varchar(255) NOT NULL DEFAULT '',
  `customer_note` varchar(500) NOT NULL DEFAULT '',
  `cancel_reason` varchar(300) NOT NULL DEFAULT '',
  `stock_reserved` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `placed_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_no` (`order_no`),
  KEY `idx_order_user` (`web_user_id`),
  KEY `idx_order_status` (`status`),
  KEY `idx_order_created` (`created_at`),
  KEY `idx_order_bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_orders` WRITE;
/*!40000 ALTER TABLE `web_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` mediumtext DEFAULT NULL,
  `meta_title` varchar(160) NOT NULL DEFAULT '',
  `meta_description` varchar(300) NOT NULL DEFAULT '',
  `show_in_footer` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_pages` WRITE;
/*!40000 ALTER TABLE `web_pages` DISABLE KEYS */;
INSERT INTO `web_pages` VALUES
(1,'about-us','About Us','<p>Welcome to {store_name}. We believe that everything you buy should be chosen with care, made with quality and delivered with a smile.</p><h3>Our promise</h3><p>Every product in our store is quality-checked before it leaves us. If something is not right, we make it right.</p><h3>Visit or write to us</h3><p>{store_address}<br>Email: {store_email}<br>Phone: {store_phone}</p>','About Us','Know more about us, our story and our promise to you.',1,10,1,'2026-10-25 19:14:54'),
(2,'privacy-policy','Privacy Policy','<p>{store_name} respects your privacy. This policy explains what information we collect and how we use it.</p><h3>Information we collect</h3><p>Name, email, mobile number, delivery address and order details that you give us when you create an account or place an order. We also keep basic technical data such as IP address to keep the website secure.</p><h3>How we use it</h3><p>To process and deliver your orders, send order updates and OTP emails, provide customer support and, only if you agree, share offers. We never sell your personal data.</p><h3>Payments</h3><p>Online payments are processed by our payment partner (Razorpay). We do not store your card, UPI or bank details.</p><h3>Your rights</h3><p>You can view or update your details from your account page, or write to {store_email} to ask us to delete your account.</p><p><em>This is a standard template. Please have it reviewed for your business.</em></p>','Privacy Policy','How we collect, use and protect your personal information.',1,20,1,'2026-10-25 19:14:54'),
(3,'terms-and-conditions','Terms &amp; Conditions','<p>By using this website and placing an order with {store_name} you agree to these terms.</p><h3>Orders and pricing</h3><p>All prices are in Indian Rupees and include applicable taxes unless stated otherwise. We may cancel an order if a product is out of stock or a pricing error occurs; in that case you will get a full refund.</p><h3>Accounts</h3><p>You are responsible for keeping your password safe and for all activity under your account.</p><h3>Shipping, returns and refunds</h3><p>Please read our Shipping Policy and Return &amp; Refund Policy, which form part of these terms.</p><h3>Contact</h3><p>Questions? Write to {store_email}.</p><p><em>This is a standard template. Please have it reviewed for your business.</em></p>','Terms & Conditions','Terms of use and sale for our website.',1,30,1,'2026-10-25 19:14:54'),
(4,'return-policy','Return &amp; Refund Policy','<p>We want you to love what you ordered. If you do not, you can request a return within <strong>{return_days} days</strong> of delivery.</p><h3>Conditions</h3><p>Items must be unused, unwashed and in original packaging with tags. Some items such as innerwear, customised products or items marked \"non-returnable\" cannot be returned.</p><h3>How to return</h3><p>Open <strong>My Account &rarr; Orders</strong>, choose the order and click <em>Request return</em>. Our team will review it and email you the next steps.</p><h3>Refunds</h3><p>After we receive and check the item, the refund is made to your original payment method within 5-7 working days. Cash on delivery refunds are sent by bank transfer.</p><p><em>This is a standard template. Please have it reviewed for your business.</em></p>','Return & Refund Policy','Easy returns and quick refunds.',1,40,1,'2026-10-25 19:14:54'),
(5,'shipping-policy','Shipping Policy','<p>We ship across India. Orders are packed within 1-2 working days and usually delivered in 3-7 working days depending on your location.</p><h3>Charges</h3><p>Shipping charges are shown at checkout. Orders above the free-shipping amount ship free. Cash on delivery may carry a small handling fee.</p><h3>Tracking</h3><p>Once your order ships you will receive an email with the courier name and tracking number. You can also track it from <strong>My Account &rarr; Orders</strong>.</p>','Shipping Policy','Delivery times, charges and tracking.',1,50,1,'2026-10-25 19:14:54');
/*!40000 ALTER TABLE `web_pages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `gateway` varchar(20) NOT NULL DEFAULT 'razorpay',
  `gateway_order_id` varchar(60) NOT NULL DEFAULT '',
  `gateway_payment_id` varchar(60) NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `status` varchar(12) NOT NULL DEFAULT 'created',
  `method` varchar(30) NOT NULL DEFAULT '',
  `error` varchar(300) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_gworder` (`gateway_order_id`),
  KEY `idx_pay_order` (`order_id`),
  KEY `idx_pay_gwpay` (`gateway_payment_id`),
  CONSTRAINT `fk_pay_order` FOREIGN KEY (`order_id`) REFERENCES `web_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_payments` WRITE;
/*!40000 ALTER TABLE `web_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_payments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_product_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_product_meta` (
  `product_id` int(10) unsigned NOT NULL,
  `show_on_website` tinyint(1) NOT NULL DEFAULT 1,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_new` tinyint(1) NOT NULL DEFAULT 0,
  `is_bestseller` tinyint(1) NOT NULL DEFAULT 0,
  `short_description` varchar(300) NOT NULL DEFAULT '',
  `meta_title` varchar(160) NOT NULL DEFAULT '',
  `meta_description` varchar(300) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`),
  CONSTRAINT `fk_pmeta_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_product_meta` WRITE;
/*!40000 ALTER TABLE `web_product_meta` DISABLE KEYS */;
INSERT INTO `web_product_meta` VALUES
(1,1,1,0,1,'Hand-embroidered silk saree with a rich zari border.','','',0),
(2,1,1,0,1,'Lucknowi chikankari kurta with matching palazzo.','','',0),
(3,1,0,1,0,'Flowing floral anarkali with a flattering fit-and-flare silhouette.','','',0),
(4,1,1,0,1,'A breathable linen shirt that looks sharp from office to weekend.','','',0),
(5,1,0,1,0,'A sharp Nehru jacket that lifts any kurta or shirt for festive occasions.','','',0),
(6,1,0,0,0,'Slim-fit stretch chinos with a clean finish and all-day comfort.','','',0),
(7,1,0,0,0,'A light handloom dupatta with a woven border, perfect to dress up any outfit.','','',0),
(8,1,1,0,0,'Roomy tote in soft genuine leather with an inner zip pocket and magnetic closure.','','',0),
(9,1,0,1,0,'Hand-finished solid brass table lamp that adds a warm glow to any corner.','','',0),
(10,1,0,0,0,'Set of two block-printed cotton cushion covers (16 x 16 inch).','','',0);
/*!40000 ALTER TABLE `web_product_meta` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_refunds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `gateway_refund_id` varchar(60) NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `reason` varchar(300) NOT NULL DEFAULT '',
  `error` varchar(300) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_refund_order` (`order_id`),
  CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `web_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_refunds` WRITE;
/*!40000 ALTER TABLE `web_refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_refunds` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_returns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `reason` varchar(120) NOT NULL,
  `details` varchar(800) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'requested',
  `admin_note` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_return_order` (`order_id`),
  CONSTRAINT `fk_return_order` FOREIGN KEY (`order_id`) REFERENCES `web_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_returns` WRITE;
/*!40000 ALTER TABLE `web_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_returns` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `web_user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `body` varchar(1500) NOT NULL DEFAULT '',
  `verified_buyer` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_once` (`product_id`,`web_user_id`),
  KEY `idx_review_status` (`status`),
  CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_reviews` WRITE;
/*!40000 ALTER TABLE `web_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_reviews` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_shipping_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_shipping_rates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `state` varchar(100) NOT NULL,
  `charge` decimal(10,2) NOT NULL DEFAULT 0.00,
  `free_above` decimal(10,2) DEFAULT NULL COMMENT 'NULL = use the general free-shipping amount',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ship_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_shipping_rates` WRITE;
/*!40000 ALTER TABLE `web_shipping_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_shipping_rates` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_subscribers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_subscribers` WRITE;
/*!40000 ALTER TABLE `web_subscribers` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_subscribers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_throttle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_throttle` (
  `bucket` varchar(100) NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_throttle` WRITE;
/*!40000 ALTER TABLE `web_throttle` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_throttle` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'linked CRM customer',
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `mobile` varchar(15) NOT NULL DEFAULT '',
  `password_hash` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `status` enum('active','blocked') NOT NULL DEFAULT 'active',
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_purpose` varchar(20) NOT NULL DEFAULT '',
  `otp_expires` datetime DEFAULT NULL,
  `otp_tries` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `otp_sent_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webuser_email` (`email`),
  KEY `idx_webuser_mobile` (`mobile`),
  KEY `idx_webuser_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_users` WRITE;
/*!40000 ALTER TABLE `web_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `web_wishlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_wishlist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `web_user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wish` (`web_user_id`,`product_id`),
  KEY `fk_wish_product` (`product_id`),
  CONSTRAINT `fk_wish_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wish_user` FOREIGN KEY (`web_user_id`) REFERENCES `web_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `web_wishlist` WRITE;
/*!40000 ALTER TABLE `web_wishlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `web_wishlist` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


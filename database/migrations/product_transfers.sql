-- جدول نقل/تشوين المنتجات
-- Product Transfers (تشوين المنتجات)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `product_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_user_id` int(11) NOT NULL COMMENT 'المستخدم الذي قام بالنقل',
  `to_type` enum('user','manual') NOT NULL DEFAULT 'user' COMMENT 'user=مستخدم نظام، manual=اسم يدوي',
  `to_user_id` int(11) DEFAULT NULL COMMENT 'المستخدم المستقبل (عند to_type=user)',
  `to_manual_name` varchar(100) DEFAULT NULL COMMENT 'الاسم اليدوي (عند to_type=manual)',
  `transfer_type` enum('external','factory') NOT NULL COMMENT 'external=منتج خارجي، factory=دفعة مصنع',
  `product_id` int(11) DEFAULT NULL COMMENT 'منتج من جدول products (للخارجي)',
  `batch_id` int(11) DEFAULT NULL COMMENT 'معرف الدفعة من finished_products (للمصنع)',
  `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج للعرض',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0,
  `unit` varchar(50) DEFAULT 'قطعة',
  `transferred_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','received') NOT NULL DEFAULT 'pending',
  `received_at` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `from_user_id` (`from_user_id`),
  KEY `to_user_id` (`to_user_id`),
  KEY `product_id` (`product_id`),
  KEY `transferred_at` (`transferred_at`),
  KEY `status` (`status`),
  KEY `to_user_status` (`to_user_id`,`status`),
  CONSTRAINT `product_transfers_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_transfers_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_transfers_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تشوين المنتجات - نقل المنتجات للمستخدمين أو أسماء يدوية';

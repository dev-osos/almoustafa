<?php
defined('ACCESS_ALLOWED') or define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance()->getConnection();
$db->query("CREATE TABLE IF NOT EXISTS driver_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    driver_id INT NOT NULL,
    assigned_by INT NOT NULL,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_da_task (task_id),
    INDEX idx_da_driver_status (driver_id, status)
)");
echo "driver_assignments table created.\n";

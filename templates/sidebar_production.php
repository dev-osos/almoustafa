<?php
/**
 * القائمة الجانبية لعمال الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$baseUrl = getDashboardUrl();
$currentDashboardPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
$currentFocus = isset($_GET['focus']) ? (string) $_GET['focus'] : '';
$isAttendancePage = $currentPage === 'attendance.php';
$sidebarPreferenceUserKey = 'production_sidebar_groups_' . (isset($currentUser['id']) ? (int) $currentUser['id'] : 0);

$isOverviewOpen = false;
$isOperationsOpen = false;
$isWarehousesOpen = false;
$isToolsOpen = false;
?>
<style>
.sidebar-group-toggle {
    width: 100%;
    border: 0;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-align: right;
}
.sidebar-group-toggle .sidebar-group-label {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.sidebar-group-toggle .bi-chevron-down {
    transition: transform 0.2s ease;
    font-size: 0.8rem;
}
.sidebar-group-toggle:not(.collapsed) .bi-chevron-down {
    transform: rotate(180deg);
}
.sidebar-submenu {
    padding: 0.25rem 0 0.5rem;
}
.sidebar-submenu .nav-link {
    padding-inline-start: 2.25rem;
    font-size: 0.95rem;
}
</style>
<div class="sidebar">
    <nav class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <button class="nav-link sidebar-group-toggle <?php echo $isOverviewOpen ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#productionSidebarOverview" aria-expanded="<?php echo $isOverviewOpen ? 'true' : 'false'; ?>" aria-controls="productionSidebarOverview">
                    <span class="sidebar-group-label">
                        <i class="bi bi-grid-1x2-fill"></i>
                        <span>الرئيسية</span>
                    </span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="collapse <?php echo $isOverviewOpen ? 'show' : ''; ?>" id="productionSidebarOverview" data-sidebar-group="overview">
                    <ul class="nav flex-column sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentPage === 'production.php' && $currentDashboardPage === '' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php">
                                <i class="bi bi-speedometer2"></i>
                                <span><?php echo isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم'; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'chat' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=chat">
                                <i class="bi bi-chat-dots"></i>
                                <span>الشات</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'product_specifications' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=product_specifications">
                                <i class="bi bi-file-text"></i>
                                <span>مواصفات المنتجات</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="nav-item">
                <button class="nav-link sidebar-group-toggle <?php echo $isOperationsOpen ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#productionSidebarOperations" aria-expanded="<?php echo $isOperationsOpen ? 'true' : 'false'; ?>" aria-controls="productionSidebarOperations">
                    <span class="sidebar-group-label">
                        <i class="bi bi-gear-wide-connected"></i>
                        <span>التشغيل اليومي</span>
                    </span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="collapse <?php echo $isOperationsOpen ? 'show' : ''; ?>" id="productionSidebarOperations" data-sidebar-group="operations">
                    <ul class="nav flex-column sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'production' && $currentFocus !== 'warehouse-damage-log' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=production">
                                <i class="bi bi-box-seam"></i>
                                <span><?php echo isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج'; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'tasks' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=tasks">
                                <i class="bi bi-list-check"></i>
                                <span><?php echo isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'أوردرات'; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'daily_collection_my_tables' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=daily_collection_my_tables">
                                <i class="bi bi-calendar2-range"></i>
                                <span>جداول التحصيل اليومية</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isAttendancePage ? 'active' : ''; ?>" href="<?php echo getRelativeUrl('attendance.php'); ?>">
                                <i class="bi bi-calendar-check"></i>
                                <span><?php echo isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور'; ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="nav-item">
                <button class="nav-link sidebar-group-toggle <?php echo $isWarehousesOpen ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#productionSidebarWarehouses" aria-expanded="<?php echo $isWarehousesOpen ? 'true' : 'false'; ?>" aria-controls="productionSidebarWarehouses">
                    <span class="sidebar-group-label">
                        <i class="bi bi-boxes"></i>
                        <span>المخازن</span>
                    </span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="collapse <?php echo $isWarehousesOpen ? 'show' : ''; ?>" id="productionSidebarWarehouses" data-sidebar-group="warehouses">
                    <ul class="nav flex-column sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'raw_materials_warehouse' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=raw_materials_warehouse">
                                <i class="bi bi-droplet-half"></i>
                                <span>مخزن الخامات</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'packaging_warehouse' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=packaging_warehouse">
                                <i class="bi bi-box2-heart"></i>
                                <span>مخزن أدوات التعبئة</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'inbound_supplies' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=inbound_supplies">
                                <i class="bi bi-box-arrow-in-down"></i>
                                <span>تسجيل الواردات</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'inventory' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=inventory">
                                <i class="bi bi-box-seam-fill"></i>
                                <span>منتجات الشركة</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'factory_waste_warehouse' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=factory_waste_warehouse">
                                <i class="bi bi-exclamation-triangle"></i>
                                <span>مخزن توالف المصنع</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="nav-item">
                <button class="nav-link sidebar-group-toggle <?php echo $isToolsOpen ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#productionSidebarTools" aria-expanded="<?php echo $isToolsOpen ? 'true' : 'false'; ?>" aria-controls="productionSidebarTools">
                    <span class="sidebar-group-label">
                        <i class="bi bi-tools"></i>
                        <span>الأدوات</span>
                    </span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="collapse <?php echo $isToolsOpen ? 'show' : ''; ?>" id="productionSidebarTools" data-sidebar-group="tools">
                    <ul class="nav flex-column sidebar-submenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentDashboardPage === 'batch_reader' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>production.php?page=batch_reader">
                                <i class="bi bi-upc-scan"></i>
                                <span>قارئ أرقام التشغيلات</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </nav>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var storageKey = <?php echo json_encode($sidebarPreferenceUserKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var groups = document.querySelectorAll('.sidebar [data-sidebar-group]');
        var savedState = {};

        try {
            savedState = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch (error) {
            savedState = {};
        }

        groups.forEach(function(group) {
            var key = group.getAttribute('data-sidebar-group') || '';
            var shouldOpen = key !== '' && Object.prototype.hasOwnProperty.call(savedState, key)
                ? !!savedState[key]
                : false;
            var toggleBtn = document.querySelector('[data-bs-target="#' + group.id + '"]');

            group.classList.toggle('show', shouldOpen);
            if (toggleBtn) {
                toggleBtn.classList.toggle('collapsed', !shouldOpen);
                toggleBtn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            }

            group.addEventListener('shown.bs.collapse', function() {
                savedState[key] = true;
                localStorage.setItem(storageKey, JSON.stringify(savedState));
            });

            group.addEventListener('hidden.bs.collapse', function() {
                savedState[key] = false;
                localStorage.setItem(storageKey, JSON.stringify(savedState));
            });
        });
    });
    </script>
    
    <div class="sidebar-footer">
        <div class="sidebar-footer-item">
            <div>
                <i class="bi bi-moon me-2"></i>
                <span>Dark Mode</span>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="darkModeToggle">
            </div>
        </div>
        <a href="<?php echo getRelativeUrl('logout.php'); ?>" class="sidebar-footer-item text-decoration-none">
            <div>
                <i class="bi bi-box-arrow-right me-2"></i>
                <span>Sign Out</span>
            </div>
        </a>
    </div>
</div>


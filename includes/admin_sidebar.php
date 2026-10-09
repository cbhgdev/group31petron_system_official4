<?php
// Admin Sidebar Navigation - Includes Database Management module
function getAdminSidebar($current_page = 'transactions') {
    $sidebar = [
        'transactions' => [
            'icon' => 'fas fa-shopping-cart',
            'title' => 'Transactions',
            'url' => 'transactions.php'
        ],
        'job_orders' => [
            'icon' => 'fas fa-wrench',
            'title' => 'Job Orders',
            'url' => 'admin_dashboard.php?tab=job_orders',
            'description' => 'Full transparency view, audit trail, compliance reports'
        ],
        'purchase_orders' => [
            'icon' => 'fas fa-file-invoice-dollar',
            'title' => 'Purchase Orders',
            'url' => 'manager_stock_request_review.php',
            'description' => 'Validate and finalize POs to suppliers'
        ],
        'merchandise_deliveries_oversight' => [
            'icon' => 'fas fa-boxes',
            'title' => 'Merchandise Deliveries Oversight',
            'url' => 'admin_merchandise_deliveries_oversight.php',
            'description' => 'Monitor manager-validated merchandise deliveries'
        ],
        'customers' => [
            'icon' => 'fas fa-users',
            'title' => 'Customers',
            'url' => 'customers.php'
        ],
        'staff' => [
            'icon' => 'fas fa-user-tie',
            'title' => 'Staff Management',
            'url' => 'staff_management.php'
        ],

        'reports' => [
            'icon' => 'fas fa-chart-line',
            'title' => 'Reports',
            'url' => 'reports.php'
        ],
        'database_management' => [
            'icon' => 'fas fa-database',
            'title' => 'Database Management',
            'url' => 'database_management.php',
            'description' => 'Backup, restore, schema updates, replication, security logs'
        ],
        'system_settings' => [
            'icon' => 'fas fa-cog',
            'title' => 'System Settings',
            'url' => 'system_settings.php'
        ],
            
    ];
    
    echo '<nav class="admin-sidebar" id="adminSidebar">';
    echo '<div class="sidebar-header">';
    echo '<h4><i class="fas fa-shield-alt"></i> Admin Portal</h4>';
    echo '</div>';
    
    echo '<ul class="sidebar-menu">';
    foreach ($sidebar as $key => $item) {
        $active = ($current_page === $key) ? 'active' : '';
        
        echo '<li class="' . $active . '">';
        echo '<a href="' . $item['url'] . '">';
        echo '<i class="' . $item['icon'] . '"></i>';
        echo '<span>' . $item['title'] . '</span>';
        if (isset($item['description'])) {
            echo '<small>' . $item['description'] . '</small>';
        }
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
    
    // Sidebar Navigation - No scroll toggle button

    echo '</nav>';
}
?>

<style>
.admin-sidebar {
    width: 250px;
    height: 100vh;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    overflow: hidden; /* No scrolling - fit all items */
    z-index: 1000;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

/* Collapsed state */
.admin-sidebar.collapsed {
    width: 70px;
}

.admin-sidebar.collapsed .sidebar-menu > li {
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.admin-sidebar.collapsed .sidebar-menu a span {
    display: none; /* Hide text when collapsed */
}

.admin-sidebar.collapsed .sidebar-menu a small {
    display: none; /* Hide descriptions when collapsed */
}

/* Toggle button styling */
.sidebar-toggle-container {
    position: absolute;
    bottom: 20px;
    left: 20px;
    z-index: 1001;
}

.sidebar-toggle-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.sidebar-toggle-btn:hover {
    background: rgba(255,255,255,0.3);
}

.sidebar-toggle-btn i {
    font-size: 16px;
    color: white;
    transition: transform 0.3s ease;
}

.admin-sidebar.collapsed .sidebar-toggle-btn i {
    transform: rotate(180deg);
}

/* Optimize sidebar items to fit all in one screen */
.sidebar-header {
    padding: 10px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
    height: 40px;
}

.sidebar-header h4 {
    margin: 0;
    color: white;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sidebar-menu {
    list-style: none;
    margin: 0;
    padding: 0;
    /* Remove max-height and overflow constraints to allow natural scrolling */
}

.sidebar-menu > li {
    border-bottom: 1px solid rgba(255,255,255,0.1);
    height: auto; /* Allow natural height */
    min-height: 35px; /* Reduced height to fit more items */
}

.sidebar-menu a {
    display: block;
    padding: 8px 12px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
    height: auto;
    line-height: 1.3;
    min-height: 35px;
    box-sizing: border-box;
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

.sidebar-menu a i {
    width: 16px;
    margin-right: 8px;
    text-align: center;
    font-size: 14px;
}

.sidebar-menu a span {
    display: block;
    font-weight: 500;
    font-size: 11px;
    line-height: 1.1;
}



.sidebar-header {
    padding: 20px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
}

.sidebar-header h4 {
    margin: 0;
    color: white;
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.sidebar-menu {
    list-style: none;
    margin: 0;
    padding: 0;
}
?>
<script>
// Sidebar Toggle Functionality
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const toggleIcon = document.getElementById('sidebarToggleIcon');
    
    if (sidebar && toggleBtn && toggleIcon) {
        // Load saved state from localStorage
        const savedState = localStorage.getItem('adminSidebarState');
        
        if (savedState === 'collapsed') {
            sidebar.classList.add('collapsed');
            toggleIcon.className = 'fas fa-chevron-right';
        } else {
            sidebar.classList.remove('collapsed');
            toggleIcon.className = 'fas fa-chevron-right';
        }
        
        // Toggle functionality
        toggleBtn.addEventListener('click', function() {
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                // Expand sidebar
                sidebar.classList.remove('collapsed');
                toggleIcon.className = 'fas fa-chevron-right';
                localStorage.setItem('adminSidebarState', 'expanded');
            } else {
                // Collapse sidebar
                sidebar.classList.add('collapsed');
                toggleIcon.className = 'fas fa-chevron-right';
                localStorage.setItem('adminSidebarState', 'collapsed');
            }
        });
    }
});
</script>

.sidebar-menu > li {
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-menu a {
    display: block;
    padding: 15px 20px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

.sidebar-menu a i {
    width: 20px;
    margin-right: 10px;
    text-align: center;
    font-size: 14px;
}

.sidebar-menu a span {
    display: block;
    font-weight: 500;
    font-size: 14px;
}

.sidebar-menu a small {
    display: block;
    font-size: 11px;
    color: rgba(255,255,255,0.6);
    margin-top: 3px;
    line-height: 1.3;
}

.sidebar-menu li.active > a {
    background: rgba(255,255,255,0.2);
    color: white;
    border-left: 4px solid #fff;
}

.sidebar-menu li.active a {
    background: rgba(255,255,255,0.2);
    color: white;
}

.sidebar-menu li.active a i {
    color: white;
}

/* Special highlight for Database Management */
.sidebar-menu li[data-menu="database_management"] a {
    background: rgba(255,255,255,0.05);
}

.sidebar-menu li[data-menu="database_management"] a:hover {
    background: rgba(255,255,255,0.15);
}

/* Main content adjustment */
.admin-main-content {
    margin-left: 250px;
    min-height: 100vh;
    background: #f8f9fa;
}

/* Responsive design */
@media (max-width: 768px) {
    .admin-sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }
    
    .admin-main-content {
        margin-left: 0;
    }
}

/* Animation for menu items */
.sidebar-menu li {
    transition: all 0.3s ease;
}

.sidebar-menu li:hover {
    transform: translateX(5px);
}

/* Database Management special styling */
.sidebar-menu li:has(a[href*="database_management"]) {
    background: rgba(255,255,255,0.05);
}

.sidebar-menu li:has(a[href*="database_management"]):hover {
    background: rgba(255,255,255,0.15);
}

/* Scroll Toggle Button - Matches sidebar style, vertically centered among icons */
.scroll-toggle-container {
    position: absolute;
    top: 50%;
    left: 20px;
    transform: translateY(-50%);
    z-index: 10;
    pointer-events: none;
}

.scroll-toggle-btn {
    pointer-events: auto;
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #002F6C 0%, #0040a0 50%, #002F6C 100%);
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    color: white;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,47,112,0.4);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    backdrop-filter: blur(10px);
}

.scroll-toggle-btn:hover {
    background: linear-gradient(135deg, #0040a0 0%, #002F6C 50%, #001a4d 100%);
    transform: scale(1.1) translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,47,112,0.6), 0 0 0 1px rgba(255,255,255,0.4);
    border-color: rgba(255,255,255,0.6);
}

.scroll-toggle-btn:active {
    transform: scale(0.98);
}

.admin-sidebar .scroll-toggle-btn i {
    transition: transform 0.2s ease;
}

.scroll-toggle-btn.at-bottom i {
    transform: rotate(180deg);
}

/* Responsive */
@media (max-width: 768px) {
    .scroll-toggle-container {
        bottom: 20px;
        top: auto;
        transform: none;
    }
}

</style>


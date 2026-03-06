<?php
/**
 * Page Header Template
 * Includes: HTML head, sidebar navigation, and page header
 */

// Ensure auth is loaded
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Vehicle Maintenance Tracker'; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="vendor/bootstrap-icons/bootstrap-icons.css">

    <!-- Fontawesome Icons -->
    <link rel="stylesheet" href="vendor/fontawesome/css/all.min.css">

    <style>
        body {
            overflow-x: hidden;
        }

        /* Sidebar styles */
        #sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: left 0.3s ease-in-out;
            z-index: 1050;
            box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        }

        #sidebar.active {
            left: 0;
        }
        
        #sidebar .sidebar-header {
            padding: 20px;
            background: rgba(0,0,0,0.2);
        }

        #sidebar .sidebar-header h4 {
            color: white;
            margin: 0;
            font-weight: 600;
        }

        #sidebar .sidebar-menu {
            padding: 0;
            list-style: none;
        }

        #sidebar .sidebar-menu li {
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        #sidebar .sidebar-menu li a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: background 0.3s;
        }

        #sidebar .sidebar-menu li a:hover {
            background: rgba(255,255,255,0.1);
        }

        #sidebar .sidebar-menu li a.active {
            background: rgba(255,255,255,0.2);
            font-weight: 600;
        }

        #sidebar .sidebar-menu li a i {
            width: 25px;
            margin-right: 10px;
        }

        /* Overlay */
        #overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            display: none;
        }

        #overlay.active {
            display: block;
        }

        /* Main content */
        #content {
            transition: margin-left 0.3s ease-in-out;
            min-height: 100vh;
        }

        /* Header */
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #menuToggle {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            padding: 5px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        #menuToggle:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Form styles */
        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 10px 30px;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5568d3 0%, #63408a 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-secondary, .btn-danger {
            padding: 10px 30px;
        }

        .table-row-div:hover {
            background-color: rgba(0, 0, 0, 0.075);
        }

        /* Additional page-specific styles can be added here */
        <?php if (isset($additional_styles)) echo $additional_styles; ?>
    </style>

    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body>
    <!-- Overlay -->
    <div id="overlay"></div>

    <!-- Sidebar -->
    <nav id="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-tools"></i> Maintenance</h4>
            <small style="color: rgba(255,255,255,0.8);">Logged in as: <?php echo htmlspecialchars(getCurrentUsername()); ?></small>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="index.php" class="<?php echo ($current_page ?? '') == 'vehicles' ? 'active' : ''; ?>">
                    <i class="bi bi-car-front-fill"></i> Choose Vehicle
                </a>
            </li>
            <li>
                <a href="vehicle-info.php" class="<?php echo ($current_page ?? '') == 'vehicle-info' ? 'active' : ''; ?>">
                    <i class="bi bi-info-circle-fill"></i> Vehicle Info
                </a>
            </li>
            <li>
                <a href="maintenance-status.php" class="<?php echo ($current_page ?? '') == 'maintenance-status' ? 'active' : ''; ?>">
                    <i class="bi bi-clipboard-check-fill"></i> Maintenance Status
                </a>
            </li>
            <li>
                <a href="history.php" class="<?php echo ($current_page ?? '') == 'history' ? 'active' : ''; ?>">
                    <i class="bi bi-clock-history"></i> History
                </a>
            </li>
            <li>
                <a href="vehicle-models.php" class="<?php echo ($current_page ?? '') == 'vehicle-models' ? 'active' : ''; ?>">
                    <i class="bi bi-plus-circle-fill"></i> Vehicle Models
                </a>
            </li>
            <li>
                <a href="maintenance-program.php" class="<?php echo ($current_page ?? '') == 'maintenance-program' ? 'active' : ''; ?>">
                    <i class="bi bi-calendar-check-fill"></i> Maintenance Programs
                </a>
            </li>
            <li>
                <a href="user-management.php" class="<?php echo ($current_page ?? '') == 'user-management' ? 'active' : ''; ?>">
                    <i class="bi bi-people-fill"></i> User Management
                </a>
            </li>
            <li>
                <a href="logout.php">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div id="content">
        <!-- Header -->
        <div class="app-header d-flex align-items-center">
            <button id="menuToggle" class="me-3">
                <i class="bi bi-list"></i>
            </button>
            <h5 class="m-0 flex-grow-1"><?php echo $page_heading ?? 'Vehicle Maintenance Tracker'; ?></h5>
        </div>

        <!-- Main Content Area -->
        <div class="container-fluid p-3 p-md-4">

<?php
/**
 * select-vehicle.php
 *
 * Sets $_SESSION['selected_vehicle_id'] and redirects to maintenance-status.php.
 * Accepts the vehicle ID via GET: select-vehicle.php?vehicle_id=N
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

requireLogin();
$userId    = getCurrentUserId();
$vehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if ($vehicleId) {
    $database = new Database();
    $db       = $database->getConnection();

    // Verify the vehicle belongs to the current user before trusting the ID
    $stmt = $db->prepare(
        "SELECT vehicle_id FROM vehicles WHERE vehicle_id = :vid AND user_id = :uid"
    );
    $stmt->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
    $stmt->bindParam(':uid', $userId,    PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if ($row !== false) {
        $_SESSION['selected_vehicle_id'] = $vehicleId;
        header('Location: maintenance-status.php');
        exit();
    }
}

// Ownership check failed or no ID supplied — go back to the vehicle list
header('Location: index.php');
exit();

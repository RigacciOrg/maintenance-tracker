<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

// Set page variables for header
$page_title = 'Select Vehicle - Maintenance Tracker';
$page_heading = 'Select Your Vehicle';
$current_page = 'vehicles';

// Ensure user is logged in
requireLogin();

// Get current user ID
$userId = getCurrentUserId();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

$success = '';
$error   = '';


// ─────────────────────────────────────────────────────────────────────
// POST handler: delete vehicle
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_vehicle') {
        $deleteVehicleId = (int)($_POST['vehicle_id'] ?? 0);

        // Verify ownership
        try {
            $checkStmt = $db->prepare(
                "SELECT vehicle_id FROM vehicles WHERE vehicle_id = :vid AND user_id = :uid"
            );
            $checkStmt->bindParam(':vid', $deleteVehicleId, PDO::PARAM_INT);
            $checkStmt->bindParam(':uid', $userId,          PDO::PARAM_INT);
            $checkStmt->execute();
            $ros = $checkStmt->fetch();

            if ($row === false) {
                $error = 'Vehicle not found or access denied.';
            } else {
                $delStmt = $db->prepare("DELETE FROM vehicles WHERE vehicle_id = :vid");
                $delStmt->bindParam(':vid', $deleteVehicleId, PDO::PARAM_INT);
                $delStmt->execute();

                $success = 'Vehicle deleted successfully.';

                // Clear from session if it was selected
                if (isset($_SESSION['selected_vehicle_id']) &&
                    $_SESSION['selected_vehicle_id'] == $deleteVehicleId) {
                    unset($_SESSION['selected_vehicle_id']);
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch user's vehicles
try {
    $query = "SELECT v.*, vm.manufacturer, vm.model_name, vm.vehicle_type, vm.unit_meter
              FROM   vehicles v
              LEFT   JOIN vehicle_models vm ON v.model_id = vm.model_id
              WHERE  v.user_id = :user_id
              ORDER  BY v.nickname, v.license_plate";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $vehicles = $stmt->fetchAll();
} catch(PDOException $e) {
    $error    = "Error fetching vehicles: " . $e->getMessage();
    $vehicles = [];
}

// Additional styles for vehicle cards
$additional_styles = "
/* Vehicle cards */
.vehicle-card {
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    border: none;
    border-radius: 12px;
    overflow: hidden;
}

.vehicle-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

.vehicle-card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    border: none;
    padding: 12px 15px;
}

.vehicle-badge {
    font-size: 0.85rem;
    padding: 5px 10px;
}

.add-vehicle-card {
    border: 2px dashed #667eea;
    background: #f8f9ff;
    cursor: pointer;
    transition: all 0.2s;
}

.add-vehicle-card:hover {
    background: #e8ebff;
    border-color: #764ba2;
}

.add-vehicle-icon {
    font-size: 3rem;
    color: #667eea;
}

.vehicle-card-active {
    outline: 3px solid #667eea;
    outline-offset: 2px;
}

.vehicle-card-active .vehicle-card-header {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
}
";

// Include header
include 'includes/header.php';
?>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<?php if (empty($vehicles)): ?>
    <div class="text-center py-5">
        <i class="bi bi-car-front" style="font-size: 4rem; color: #667eea;"></i>
        <h4 class="mt-3 mb-2">No Vehicles Yet</h4>
        <p class="text-muted">Add your first vehicle to start tracking maintenance</p>
        <a href="add-vehicle.php" class="btn btn-primary mt-3">
            <i class="bi bi-plus-circle me-2"></i>Add Your First Vehicle
        </a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($vehicles as $vehicle): ?>
            <?php
            $icon     = $typeIcons[$vehicle['vehicle_type'] ?? ''] ?? 'car-front-fill';
            $unit     = htmlspecialchars($vehicle['unit_meter'] ?? 'km');
            $meter    = number_format((float)($vehicle['effective_meter'] ?? 0), 0);
            $year     = $vehicle['start_date'] ? date('Y', strtotime($vehicle['start_date'])) : '—';
            $isSelected = (isset($_SESSION['selected_vehicle_id']) &&
                           $_SESSION['selected_vehicle_id'] == $vehicle['vehicle_id']);
            ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div class="card vehicle-card shadow-sm h-100 <?php echo $isSelected ? 'vehicle-card-active' : ''; ?>">
                    <div class="card-header vehicle-card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="select-vehicle.php?vehicle_id=<?php echo $vehicle['vehicle_id']; ?>"
                               class="text-white text-decoration-none flex-grow-1">
                                <i class="fa-solid fa-fw fa-lg fa-<?php echo $icon; ?> me-2"></i>
                                <?= h($vehicle['nickname'] ?: 'My Vehicle'); ?>
                            </a>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($isSelected): ?>
                                    <i class="bi bi-check-circle-fill text-white"></i>
                                <?php endif; ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="vehicle-info.php?vehicle_id=<?= $vehicle['vehicle_id'] ?>">
                                                <i class="bi bi-pencil me-2"></i>Edit
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item text-danger"
                                                    type="button"
                                                    data-vehicle_id="<?= $vehicle['vehicle_id'] ?>"
                                                    data-nickname="<?= h($vehicle['nickname'] ?: 'My Vehicle') ?>"
                                                    data-model="<?= h($vehicle['manufacturer'] . ' ' . $vehicle['model_name']) ?>"
                                                    onclick="openDeleteModal(this)">
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <a href="select-vehicle.php?vehicle_id=<?= $vehicle['vehicle_id'] ?>"
                           class="text-decoration-none">
                            <h6 class="card-title mb-2 text-dark">
                                <?php echo htmlspecialchars($vehicle['manufacturer'] . ' ' . $vehicle['model_name']); ?>
                            </h6>
                            <div class="mb-2">
                                <span class="badge vehicle-badge bg-secondary">
                                    <i class="bi bi-credit-card me-1"></i>
                                    <?php echo htmlspecialchars($vehicle['license_plate'] ?: 'No plate'); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-speedometer2 me-1"></i><?php echo $meter . ' ' . $unit; ?>
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-calendar3 me-1"></i><?php echo $year; ?>
                                </small>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Add New Vehicle Card -->
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <a href="add-vehicle.php" class="text-decoration-none">
                <div class="card add-vehicle-card shadow-sm h-100">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center"
                         style="min-height: 180px;">
                        <i class="bi bi-plus-circle add-vehicle-icon"></i>
                        <h6 class="mt-3 mb-0 text-center">Add New Vehicle</h6>
                    </div>
                </div>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Delete Vehicle Confirmation Modal -->
<div class="modal fade" id="deleteVehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Vehicle
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to delete this vehicle?</p>

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <div class="mb-2">
                            <small class="text-muted d-block">Vehicle Name</small>
                            <strong id="deleteVehicleName"></strong>
                        </div>
                        <div>
                            <small class="text-muted d-block">Model</small>
                            <strong id="deleteVehicleModel"></strong>
                        </div>
                    </div>
                </div>

                <div class="alert alert-danger mb-0">
                    <i class="bi bi-exclamation-octagon me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. All maintenance history, notes, 
                    and data associated with this vehicle will be permanently deleted.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteVehicleForm">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="vehicle_id" id="deleteVehicleId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Vehicle
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function openDeleteModal(button) {
    document.getElementById('deleteVehicleName').textContent = button.dataset.nickname;
    document.getElementById('deleteVehicleModel').textContent = button.dataset.model;
    document.getElementById('deleteVehicleId').value = button.dataset.vehicle_id;

    const modal = new bootstrap.Modal(document.getElementById('deleteVehicleModal'));
    modal.show();
}
</script>
JS;
?>

<?php
// Include footer — no extra JS needed
include 'includes/footer.php';
?>

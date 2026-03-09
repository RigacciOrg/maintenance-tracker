<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

// Set page variables for header
$page_title = 'Vehicle Models - Maintenance Tracker';
$page_heading = 'Manage Vehicle Models';
$current_page = 'vehicle-models';

// Ensure user is logged in
requireLogin();
$userId = getCurrentUserId();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $model_name = trim($_POST['model_name'] ?? '');
        $vehicle_type = $_POST['vehicle_type'] ?? 'car';
        $year_range = trim($_POST['year_range'] ?? '');
        $unit_meter = $_POST['unit_meter'] ?? 'km';
        $unit_time = $_POST['unit_time'] ?? 'days';
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if (empty($manufacturer) || empty($model_name)) {
            $error = 'Manufacturer and Model Name are required';
        } else {
            try {
                if ($action === 'add') {
                    // Insert new model
                    $query = "INSERT INTO vehicle_models 
                              (user_id, manufacturer, model_name, vehicle_type, year_range, unit_meter, unit_time, notes) 
                              VALUES (:user_id, :manufacturer, :model_name, :vehicle_type, :year_range, :unit_meter, :unit_time, :notes)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                    $stmt->bindParam(':manufacturer', $manufacturer);
                    $stmt->bindParam(':model_name', $model_name);
                    $stmt->bindParam(':vehicle_type', $vehicle_type);
                    $stmt->bindParam(':year_range', $year_range);
                    $stmt->bindParam(':unit_meter', $unit_meter);
                    $stmt->bindParam(':unit_time', $unit_time);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->execute();

                    $success = 'Vehicle model added successfully!';
                } else {
                    // Update existing model
                    $model_id = $_POST['model_id'] ?? 0;

                    // Verify ownership
                    $checkQuery = "SELECT model_id FROM vehicle_models WHERE model_id = :model_id AND user_id = :user_id";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
                    $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                    $checkStmt->execute();
                    $row = $checkStmt->fetch();

                    if ($row === false) {
                        $error = 'Model not found or access denied';
                    } else {
                        $query = "UPDATE vehicle_models 
                                  SET manufacturer = :manufacturer, 
                                      model_name = :model_name, 
                                      vehicle_type = :vehicle_type,
                                      year_range = :year_range, 
                                      unit_meter = :unit_meter, 
                                      unit_time = :unit_time, 
                                      notes = :notes
                                  WHERE model_id = :model_id AND user_id = :user_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':manufacturer', $manufacturer);
                        $stmt->bindParam(':model_name', $model_name);
                        $stmt->bindParam(':vehicle_type', $vehicle_type);
                        $stmt->bindParam(':year_range', $year_range);
                        $stmt->bindParam(':unit_meter', $unit_meter);
                        $stmt->bindParam(':unit_time', $unit_time);
                        $stmt->bindParam(':notes', $notes);
                        $stmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
                        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                        $stmt->execute();

                        $success = 'Vehicle model updated successfully!';
                    }
                }
            } catch(PDOException $e) {
                // Check for unique violation 23505 or integrity constraint violation 23000.
                $sqlState = $e->errorInfo[0];
                if (in_array($sqlState, ['23000', '23505'])) {
                    $error = 'A vehicle model with this manufacturer and model name already exists';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $model_id = $_POST['model_id'] ?? 0;

        try {
            // Check if model is in use
            $checkQuery = "SELECT COUNT(*) as count FROM vehicles WHERE model_id = :model_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
            $checkStmt->execute();
            $result = $checkStmt->fetch();

            if ($result['count'] > 0) {
                $error = 'Cannot delete: This model is being used by ' . $result['count'] . ' vehicle(s)';
            } else {
                $query = "DELETE FROM vehicle_models WHERE model_id = :model_id AND user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $success = 'Vehicle model deleted successfully!';
                } else {
                    $error = 'Model not found or access denied';
                }
            }
        } catch(PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch user's vehicle models
try {
    $query = "SELECT vm.*, 
              (SELECT COUNT(*) FROM vehicles v WHERE v.model_id = vm.model_id) as vehicle_count
              FROM vehicle_models vm 
              WHERE vm.user_id = :user_id 
              ORDER BY vm.manufacturer, vm.model_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $models = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching models: " . $e->getMessage();
    $models = [];
}

// Include header
include 'includes/header.php';
?>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<!-- Add New Model Button -->
<div class="mb-4">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModelModal">
        <i class="bi bi-plus-circle me-2"></i>Add New Vehicle Model
    </button>
</div>

<!-- Models List -->
<?php if (empty($models)): ?>
    <div class="text-center py-5">
        <i class="bi bi-folder2-open" style="font-size: 4rem; color: #667eea;"></i>
        <h4 class="mt-3 mb-2">No Vehicle Models Yet</h4>
        <p class="text-muted">Create your first vehicle model to get started</p>
        <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addModelModal">
            <i class="bi bi-plus-circle me-2"></i>Create First Model
        </button>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($models as $model): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card shadow-sm h-100" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 15px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="mb-0">
                                    <i class="fa-solid fa-fw fa-lg fa-<?php echo $typeIcons[$model['vehicle_type']] ?? 'gear'; ?> me-2"></i>
                                    <?= h($model['manufacturer'] . ' ' . $model['model_name']) ?> 
                                </h6>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" type="button"
                                            data-model_id="<?= h($model['model_id']) ?>"
                                            data-manufacturer="<?= h($model['manufacturer']) ?>"
                                            data-model_name="<?= h($model['model_name']) ?>"
                                            data-vehicle_type="<?= h($model['vehicle_type']) ?>"
                                            data-year_range="<?= h($model['year_range']) ?>"
                                            data-unit_meter="<?= h($model['unit_meter']) ?>"
                                            data-unit_time="<?= h($model['unit_time']) ?>"
                                            data-notes="<?= h($model['notes']) ?>"
                                            onclick="editModel(this)">
                                            <i class="bi bi-pencil me-2"></i>Edit
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button class="dropdown-item text-danger" type="button"
                                            data-model_id="<?= h($model['model_id']) ?>"
                                            data-model_name="<?= h($model['manufacturer'] . ' ' . $model['model_name']) ?>"
                                            onclick="confirmDelete(this)">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <span class="badge bg-dark mb-2">
                            <i class="fa-solid fa-fw fa-<?= $typeIcons[$model['vehicle_type']] ?? 'gear' ?> me-1"></i>
                            <?= h(ucfirst($model['vehicle_type'])) ?>
                        </span>

                        <?php if (!empty($model['year_range'])): ?>
                            <p class="text-muted mb-2">
                                <i class="bi bi-calendar-range me-2"></i><?= h($model['year_range']) ?> 
                            </p>
                        <?php endif; ?>

                        <div class="d-flex gap-2 mb-2">
                            <span class="badge bg-primary">
                                <i class="bi bi-speedometer2 me-1"></i><?= h($model['unit_meter']) ?> 
                            </span>
                            <span class="badge bg-info">
                                <i class="bi bi-clock me-1"></i><?= h($model['unit_time']) ?> 
                            </span>
                            <span class="badge bg-secondary">
                                <i class="bi bi-car-front me-1"></i><?= $model['vehicle_count'] ?> vehicle(s)
                            </span>
                        </div>

                        <?php if (!empty($model['notes'])): ?>
                            <p class="text-muted small mb-0 mt-2">
                                <?= nl2br(h($model['notes'])) ?> 
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add/Edit Model Modal -->
<div class="modal fade" id="addModelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add New Vehicle Model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="model_id" id="modelId" value="">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="manufacturer" class="form-label">Manufacturer *</label>
                            <input type="text" class="form-control" id="manufacturer" name="manufacturer" required placeholder="e.g., Toyota, Honda, Ford">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="model_name" class="form-label">Model Name *</label>
                            <input type="text" class="form-control" id="model_name" name="model_name" required placeholder="e.g., Corolla, Civic, F-150">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="vehicle_type" class="form-label">Vehicle Type *</label>
                            <select class="form-select" id="vehicle_type" name="vehicle_type" required>
                            <?php foreach ($typeLabels as $t => $l): ?>
                                <option value="<?= h($t) ?>"><?= h($l) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="year_range" class="form-label">Year Range</label>
                            <input type="text" class="form-control" id="year_range" name="year_range" placeholder="e.g., 2015-2020, 2018+">
                            <div class="form-text">Optional: Specify the year range</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="unit_meter" class="form-label">Meter Unit *</label>
                            <select class="form-select" id="unit_meter" name="unit_meter" required>
                                <option value="km">Kilometers (km)</option>
                                <option value="miles">Miles</option>
                                <option value="hours">Hours</option>
                            </select>
                            <div class="form-text">Unit for odometer/meter readings</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="unit_time" class="form-label">Time Unit *</label>
                            <select class="form-select" id="unit_time" name="unit_time" required>
                            <?php foreach ($timeUnitsDays as $unit => $days): ?>
                                <option value="<?= h($unit) ?>"><?= h(ucfirst($unit)) ?></option>
                            <?php endforeach; ?>
                            </select>
                            <div class="form-text">Unit for time-based maintenance intervals</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any additional notes about this vehicle model..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i><span id="submitButtonText">Add Model</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteModelName"></strong>?</p>
                <p class="text-danger mb-0">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="model_id" id="deleteModelId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Add extra JavaScript
$extra_js = "
<script>
function editModel(button) {
    // Change modal title and button text
    document.getElementById('modalTitle').textContent = 'Edit Vehicle Model';
    document.getElementById('submitButtonText').textContent = 'Update Model';

    // Set form action to edit
    document.getElementById('formAction').value = 'edit';
    document.getElementById('modelId').value = button.dataset.model_id;

    // Fill form fields
    document.getElementById('manufacturer').value = button.dataset.manufacturer;
    document.getElementById('model_name').value = button.dataset.model_name;
    document.getElementById('vehicle_type').value = button.dataset.vehicle_type || 'car';
    document.getElementById('year_range').value = button.dataset.year_range || '';
    document.getElementById('unit_meter').value = button.dataset.unit_meter;
    document.getElementById('unit_time').value = button.dataset.unit_time;
    document.getElementById('notes').value = button.dataset.notes || '';

    // Show modal
    var modal = new bootstrap.Modal(document.getElementById('addModelModal'));
    modal.show();
}

function confirmDelete(button) {
    document.getElementById('deleteModelId').value = button.dataset.model_id;
    document.getElementById('deleteModelName').textContent = button.dataset.model_name;

    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addModelModal').addEventListener('hidden.bs.modal', function () {
    // Reset form
    document.querySelector('#addModelModal form').reset();

    // Reset titles and action
    document.getElementById('modalTitle').textContent = 'Add New Vehicle Model';
    document.getElementById('submitButtonText').textContent = 'Add Model';
    document.getElementById('formAction').value = 'add';
    document.getElementById('modelId').value = '';
});
</script>
";

// Include footer
include 'includes/footer.php';
?>

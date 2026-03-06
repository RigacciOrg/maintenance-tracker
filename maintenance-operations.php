<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

// Set page variables for header
$page_title = 'Maintenance Operations - Maintenance Tracker';
$page_heading = 'Maintenance Operations';
$current_page = 'maintenance-program';

// Ensure user is logged in
requireLogin();
$userId = getCurrentUserId();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// Fetch item details and verify ownership
$item = null;
$model = null;
try {
    $query = "SELECT mi.*, vm.manufacturer, vm.model_name, vm.vehicle_type, vm.unit_meter, vm.unit_time
              FROM maintenance_items mi
              JOIN vehicle_models vm ON mi.model_id = vm.model_id
              WHERE mi.item_id = :item_id AND vm.user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $item = $stmt->fetch();

    if ($item) {
        $model = [
            'manufacturer' => $item['manufacturer'],
            'model_name' => $item['model_name'],
            'vehicle_type' => $item['vehicle_type'],
            'unit_meter' => $item['unit_meter'],
            'unit_time' => $item['unit_time']
        ];
    }
} catch(PDOException $e) {
    $error = "Error fetching item: " . $e->getMessage();
}

// Vehicle-type icon
$typeIcon = $typeIcons[$model['vehicle_type']] ?? 'gear';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $item) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $operation_name = trim($_POST['operation_name'] ?? '');
        $interval_time = $_POST['interval_time'] ?? null;
        $interval_meter = $_POST['interval_meter'] ?? null;
        $description = trim($_POST['description'] ?? '');

        // Convert empty strings to NULL
        $interval_time = ($interval_time === '' || $interval_time === null) ? null : (int)$interval_time;
        $interval_meter = ($interval_meter === '' || $interval_meter === null) ? null : (float)$interval_meter;

        if (empty($operation_name)) {
            $error = 'Operation name is required';
        } elseif ($interval_time === null && $interval_meter === null) {
            $error = 'At least one interval (time or meter) must be specified';
        } else {
            try {
                if ($action === 'add') {
                    $query = "INSERT INTO maintenance_operations
                              (item_id, operation_name, interval_time, interval_meter, description)
                              VALUES (:item_id, :operation_name, :interval_time, :interval_meter, :description)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
                    $stmt->bindParam(':operation_name', $operation_name);
                    $stmt->bindParam(':interval_time', $interval_time, PDO::PARAM_INT);
                    $stmt->bindParam(':interval_meter', $interval_meter);
                    $stmt->bindParam(':description', $description);
                    $stmt->execute();

                    $success = 'Operation added successfully!';
                } else {
                    $operation_id = $_POST['operation_id'] ?? 0;

                    $query = "UPDATE maintenance_operations
                              SET operation_name = :operation_name,
                                  interval_time = :interval_time,
                                  interval_meter = :interval_meter,
                                  description = :description
                              WHERE operation_id = :operation_id
                              AND item_id = :item_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':operation_name', $operation_name);
                    $stmt->bindParam(':interval_time', $interval_time, PDO::PARAM_INT);
                    $stmt->bindParam(':interval_meter', $interval_meter);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':operation_id', $operation_id, PDO::PARAM_INT);
                    $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
                    $stmt->execute();

                    $success = 'Operation updated successfully!';
                }
            } catch(PDOException $e) {
                // Check for unique violation 23505 or integrity constraint violation 23000.
                $sqlState = $e->errorInfo[0];
                if (in_array($sqlState, ['23000', '23505'])) {
                    $error = 'An operation with this name already exists for this item';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $operation_id = $_POST['operation_id'] ?? 0;

        try {
            // Check if operation has maintenance history (CONSTRAINT ON CASCADE RESTRICT).
            $histQuery = "SELECT COUNT(*) FROM maintenance_history mh
                          JOIN maintenance_operations mo ON mh.operation_id = mo.operation_id
                          WHERE mo.operation_id = :operation_id;";
            $histStmt = $db->prepare($histQuery);
            $histStmt->bindParam(':operation_id', $operation_id, PDO::PARAM_INT);
            $histStmt->execute();
            $result = $histStmt->fetchColumn();
            if ($result > 0) {
                $error = 'Cannot delete: This operation is referenced ' . $result . ' time(s) in the maintenance history';
            } else {
                $query = "DELETE FROM maintenance_operations
                          WHERE operation_id = :operation_id AND item_id = :item_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':operation_id', $operation_id, PDO::PARAM_INT);
                $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $success = 'Operation deleted successfully!';
                } else {
                    $error = 'Operation not found';
                }
            }
        } catch(PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch operations for this item
$operations = [];
if ($item) {
    try {
        $query = "SELECT * FROM maintenance_operations 
                  WHERE item_id = :item_id 
                  ORDER BY operation_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $operations = $stmt->fetchAll();
    } catch(PDOException $e) {
        $error = "Error fetching operations: " . $e->getMessage();
    }
}

// Include header
include 'includes/header.php';
?>

<?php if (!$item): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Item not found or access denied. <a href="maintenance-program.php" class="alert-link">Go back</a>
    </div>
<?php else: ?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="maintenance-program.php">Maintenance Programs</a>
        </li>
        <li class="breadcrumb-item">
            <a href="maintenance-program.php?model_id=<?php echo $item['model_id']; ?>">
                <?php echo htmlspecialchars($model['manufacturer'] . ' ' . $model['model_name']); ?>
            </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
            <?php echo htmlspecialchars($item['item_name']); ?>
        </li>
    </ol>
</nav>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<!-- Item Header -->
<div class="form-card mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h4 class="mb-2">
                <i class="bi bi-wrench-adjustable me-2 text-primary"></i>
                <?php echo htmlspecialchars($item['item_name']); ?>
            </h4>
            <p class="text-muted mb-2">
                <div class="mb-2">
                    <strong><?= h($model['manufacturer'] . ' ' . $model['model_name']) ?></strong>
                </div>
                <div>
                    <span class="badge bg-dark me-1">
                        <i class="fa-solid fa-<?= $typeIcon ?> me-1"></i>
                        <?= h(ucfirst($model['vehicle_type'])) ?>
                    </span>
                </div>
            </p>
            <?php if (!empty($item['description'])): ?>
                <p class="text-muted mb-0">
                    <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="text-end">
            <span class="badge bg-primary">
                <i class="bi bi-speedometer2 me-1"></i><?php echo htmlspecialchars($model['unit_meter']); ?>
            </span>
            <span class="badge bg-info">
                <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($model['unit_time']); ?>
            </span>
        </div>
    </div>
</div>

<!-- Operations Section -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="bi bi-gear-fill me-2"></i>Maintenance Operations
    </h5>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOperationModal">
        <i class="bi bi-plus-circle me-2"></i>Add Operation
    </button>
</div>

<?php if (empty($operations)): ?>
    <div class="form-card text-center py-5">
        <i class="bi bi-inbox" style="font-size: 4rem; color: #667eea;"></i>
        <h5 class="mt-3 mb-2">No Operations Defined</h5>
        <p class="text-muted mb-3">Add operations like "Change", "Inspect", "Replace" for this item</p>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOperationModal">
            <i class="bi bi-plus-circle me-2"></i>Add First Operation
        </button>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($operations as $operation): ?>
            <div class="col-12 col-lg-6">
                <div class="form-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h6 class="mb-0">
                            <i class="bi bi-gear me-2 text-success"></i>
                            <?php echo htmlspecialchars($operation['operation_name']); ?>
                        </h6>
                        <div>
                            <button class="btn btn-sm btn-outline-primary me-1"
                                    data-operation_id="<?= h($operation['operation_id']) ?>"
                                    data-operation_name="<?= h($operation['operation_name']) ?>"
                                    data-interval_time="<?= h($operation['interval_time']) ?>"
                                    data-interval_meter="<?= h($operation['interval_meter']) ?>"
                                    data-description="<?= h($operation['description']) ?>"
                                    onclick="editOperation(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                    data-operation_id="<?= h($operation['operation_id']) ?>"
                                    data-operation_name="<?= h($operation['operation_name']) ?>"
                                    data-item_name="<?= h($item['item_name']) ?>"
                                    onclick="confirmDelete(this)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-6">
                            <small class="text-muted d-block">Time Interval</small>
                            <?php if ($operation['interval_time']): ?>
                                <strong><?php echo $operation['interval_time']; ?> <?php echo htmlspecialchars($model['unit_time']); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Meter Interval</small>
                            <?php if ($operation['interval_meter']): ?>
                                <strong><?php echo number_format($operation['interval_meter'], 0); ?> <?php echo htmlspecialchars($model['unit_meter']); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($operation['description'])): ?>
                        <p class="text-muted small mb-0 mt-2">
                            <?php echo nl2br(htmlspecialchars($operation['description'])); ?>
                        </p>
                    <?php endif; ?>

                    <?php
                    // Determine operation type
                    $type = 'Both';
                    if ($operation['interval_time'] && !$operation['interval_meter']) {
                        $type = 'Time-based only';
                    } elseif (!$operation['interval_time'] && $operation['interval_meter']) {
                        $type = 'Meter-based only';
                    }
                    ?>
                    <div class="mt-2">
                        <span class="badge bg-secondary">
                            <i class="bi bi-info-circle me-1"></i><?php echo $type; ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add/Edit Operation Modal -->
<div class="modal fade" id="addOperationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Operation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="operation_id" id="operationId" value="">

                    <div class="mb-3">
                        <label for="operation_name" class="form-label">Operation Name *</label>
                        <input type="text" class="form-control" id="operation_name" name="operation_name" 
                               required placeholder="e.g., Inspect, Replace">
                    </div>

                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> At least one interval (time or meter) must be specified. 
                        Leave blank if not applicable.
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="interval_time" class="form-label">
                                Time Interval (<?php echo htmlspecialchars($model['unit_time']); ?>)
                            </label>
                            <input type="number" class="form-control" id="interval_time" name="interval_time" 
                                   min="1" placeholder="e.g., 365">
                            <div class="form-text">Number of <?php echo htmlspecialchars($model['unit_time']); ?> between operations</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="interval_meter" class="form-label">
                                Meter Interval (<?php echo htmlspecialchars($model['unit_meter']); ?>)
                            </label>
                            <input type="number" class="form-control" id="interval_meter" name="interval_meter" 
                                   min="0" step="0.01" placeholder="e.g., 10000">
                            <div class="form-text">Number of <?php echo htmlspecialchars($model['unit_meter']); ?> between operations</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="operation_description" class="form-label">Description</label>
                        <textarea class="form-control" id="operation_description" name="description" rows="3" 
                                  placeholder="Optional description of this operation..."></textarea>
                    </div>

                    <div class="alert alert-secondary mb-0">
                        <strong>Examples:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Change Oil and Filter:</strong> Time: 365 days, Meter: 10000 km</li>
                            <li><strong>Check Oil Level:</strong> Time: 30 days, Meter: (empty)</li>
                            <li><strong>Rotate Tires:</strong> Time: (empty), Meter: 8000 km</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i><span id="submitButtonText">Add Operation</span>
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
                <p>Are you sure you want to delete operation <strong id="deleteOperationName"></strong> on item <strong id="deleteItemName"></strong>?</p>
                <p class="text-danger mb-0">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="operation_id" id="deleteOperationId">
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
function editOperation(button) {
    document.getElementById('modalTitle').textContent = 'Edit Operation';
    document.getElementById('submitButtonText').textContent = 'Update Operation';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('operationId').value = button.dataset.operation_id;
    document.getElementById('operation_name').value = button.dataset.operation_name;
    document.getElementById('interval_time').value = button.dataset.interval_time || '';
    document.getElementById('interval_meter').value = button.dataset.interval_meter || '';
    document.getElementById('operation_description').value = button.dataset.description || '';

    var modal = new bootstrap.Modal(document.getElementById('addOperationModal'));
    modal.show();
}

function confirmDelete(button) {
    document.getElementById('deleteOperationId').value = button.dataset.operation_id;
    document.getElementById('deleteOperationName').textContent = button.dataset.operation_name;
    document.getElementById('deleteItemName').textContent = button.dataset.item_name;

    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Reset form when modal is closed
document.getElementById('addOperationModal').addEventListener('hidden.bs.modal', function () {
    document.querySelector('#addOperationModal form').reset();
    document.getElementById('modalTitle').textContent = 'Add Operation';
    document.getElementById('submitButtonText').textContent = 'Add Operation';
    document.getElementById('formAction').value = 'add';
    document.getElementById('operationId').value = '';
});
</script>
";

// Include footer
include 'includes/footer.php';
?>

<?php endif; ?>

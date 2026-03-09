<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

// Set page variables for header
$page_title = 'Maintenance Programs - Maintenance Tracker';
$page_heading = 'Maintenance Programs';
$current_page = 'maintenance-program';

// Ensure user is logged in
requireLogin();
$userId = getCurrentUserId();

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$selectedModelId = null;


// Handle item addition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $model_id = $_POST['model_id'] ?? 0;
        $item_name = trim($_POST['item_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Verify model ownership
        $checkQuery = "SELECT model_id FROM vehicle_models WHERE model_id = :model_id AND user_id = :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
        $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $checkStmt->execute();
        $row = $checkStmt->fetch();

        if ($row === false) {
            $error = 'Model not found or access denied';
        } elseif (empty($item_name)) {
            $error = 'Item name is required';
        } else {
            try {
                $query = "INSERT INTO maintenance_items (model_id, item_name, description)
                          VALUES (:model_id, :item_name, :description)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':model_id', $model_id, PDO::PARAM_INT);
                $stmt->bindParam(':item_name', $item_name);
                $stmt->bindParam(':description', $description);
                $stmt->execute();

                $success = 'Maintenance item added successfully!';
                $selectedModelId = $model_id;
            } catch(PDOException $e) {
                // Check for unique violation 23505 or integrity constraint violation 23000.
                $sqlState = $e->errorInfo[0];
                if (in_array($sqlState, ['23000', '23505'])) {
                    $error = 'An item with this name already exists for this vehicle model';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_item') {
        $item_id = $_POST['item_id'] ?? 0;
        $model_id = $_POST['model_id'] ?? 0;

        try {
            // Verify ownership through model.
            $checkQuery = "SELECT mi.item_id FROM maintenance_items mi
                          JOIN vehicle_models vm ON mi.model_id = vm.model_id
                          WHERE mi.item_id = :item_id AND vm.user_id = :user_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
            $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $checkStmt->execute();
            $row = $checkStmt->fetch();
            if ($row === false) {
                $error = 'Item not found or access denied';
            } else {
                // Check if item has maintenance history (CONSTRAINT ON CASCADE RESTRICT).
                $histQuery = "SELECT COUNT(*) FROM maintenance_history mh
                              JOIN maintenance_operations mo ON mh.operation_id = mo.operation_id
                              JOIN maintenance_items mi ON mo.item_id = mi.item_id
                              WHERE mi.item_id = :item_id;";
                $histStmt = $db->prepare($histQuery);
                $histStmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
                $histStmt->execute();
                $result = $histStmt->fetchColumn();
                if ($result > 0) {
                    $error = 'Cannot delete: This item is referenced ' . $result . ' time(s) in the maintenance history';
                } else {
                    // Check if item has operations (CONSTRAINT ON CASCADE DELETE).
                    $opQuery = "SELECT COUNT(*) as count FROM maintenance_operations WHERE item_id = :item_id";
                    $opStmt = $db->prepare($opQuery);
                    $opStmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
                    $opStmt->execute();
                    $opResult = $opStmt->fetch();

                    $query = "DELETE FROM maintenance_items WHERE item_id = :item_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
                    $stmt->execute();

                    $success = 'Maintenance item deleted successfully!';
                    if ($opResult['count'] > 0) {
                        $success .= ' (' . $opResult['count'] . ' operation(s) also removed)';
                    }
                    $selectedModelId = $model_id;
                }
            }
        } catch(PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get selected model from GET or POST
if (isset($_GET['model_id'])) {
    $selectedModelId = (int)$_GET['model_id'];
} elseif (isset($_POST['selected_model_id'])) {
    $selectedModelId = (int)$_POST['selected_model_id'];
}

// Fetch user's vehicle models
try {
    $query = "SELECT model_id, manufacturer, model_name, vehicle_type, unit_meter, unit_time
              FROM vehicle_models 
              WHERE user_id = :user_id 
              ORDER BY manufacturer, model_name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $models = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching models: " . $e->getMessage();
    $models = [];
}

// Fetch maintenance items for selected model
$items = [];
$selectedModel = null;
if ($selectedModelId) {
    try {
        // Get model details
        $modelQuery = "SELECT * FROM vehicle_models WHERE model_id = :model_id AND user_id = :user_id";
        $modelStmt = $db->prepare($modelQuery);
        $modelStmt->bindParam(':model_id', $selectedModelId, PDO::PARAM_INT);
        $modelStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $modelStmt->execute();
        $selectedModel = $modelStmt->fetch();
        
        if ($selectedModel) {
            // Get items with operation count
            $query = "SELECT mi.*, 
                      (SELECT COUNT(*) FROM maintenance_operations mo WHERE mo.item_id = mi.item_id) as operation_count
                      FROM maintenance_items mi
                      WHERE mi.model_id = :model_id
                      ORDER BY mi.item_name";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':model_id', $selectedModelId, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll();
        }
    } catch(PDOException $e) {
        $error = "Error fetching items: " . $e->getMessage();
    }
}

// Include header
include 'includes/header.php';
?>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<!-- Vehicle Model Selection -->
<div class="form-card mb-4">
    <h5 class="mb-3">
        <i class="bi bi-car-front me-2"></i>Select Vehicle Model
    </h5>

    <?php if (empty($models)): ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-2"></i>
            No vehicle models found. <a href="vehicle-models.php" class="alert-link">Create a vehicle model first</a>.
        </div>
    <?php else: ?>
        <form method="GET" action="" id="modelSelectForm">
            <div class="row align-items-end">
                <div class="col-md-10 col-lg-11 mb-3 mb-md-0">
                    <label for="model_id" class="form-label">Choose a vehicle model:</label>
                    <select class="form-select form-select-lg" id="model_id" name="model_id" onchange="this.form.submit()">
                        <option value="">-- Select a model --</option>
                        <?php foreach ($models as $model): ?>
                            <option value="<?php echo $model['model_id']; ?>" 
                                    <?php echo ($selectedModelId == $model['model_id']) ? 'selected' : ''; ?>>
                                <?php 
                                echo htmlspecialchars($model['manufacturer'] . ' ' . $model['model_name']);
                                echo ' (' . htmlspecialchars(ucfirst($model['vehicle_type'])) . ')';
                                echo ' - ' . htmlspecialchars($model['unit_meter']) . '/' . htmlspecialchars($model['unit_time']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-lg-1">
                    <?php if ($selectedModelId): ?>
                        <a href="maintenance-program.php" class="btn btn-secondary w-100">
                            <i class="bi bi-x-circle"></i><span class="d-none d-lg-inline ms-1">Clear</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Maintenance Items List -->
<?php if ($selectedModel): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-check me-2"></i>Maintenance Items
            <small class="text-muted">for <?php echo htmlspecialchars($selectedModel['manufacturer'] . ' ' . $selectedModel['model_name']); ?></small>
        </h5>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-circle me-2"></i>Add Item
        </button>
    </div>
    
    <?php if (empty($items)): ?>
        <div class="form-card text-center py-5">
            <i class="bi bi-inbox" style="font-size: 4rem; color: #667eea;"></i>
            <h5 class="mt-3 mb-2">No Maintenance Items</h5>
            <p class="text-muted mb-3">Start by adding maintenance items like "Engine Oil", "Tires", "Brake System"</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle me-2"></i>Add First Item
            </button>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($items as $item): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="form-card h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="mb-0">
                                <i class="bi bi-wrench-adjustable me-2 text-primary"></i>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </h6>
                            <button class="btn btn-sm btn-outline-danger"
                                    data-item_id="<?= h($item['item_id']) ?>"
                                    data-item_name="<?= h($item['item_name']) ?>"
                                    onclick="confirmDelete(this)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>

                        <?php if (!empty($item['description'])): ?>
                            <p class="text-muted small mb-3">
                                <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                            </p>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge bg-info">
                                <i class="bi bi-gear me-1"></i>
                                <?php echo $item['operation_count']; ?> operation(s)
                            </span>
                            <a href="maintenance-operations.php?item_id=<?php echo $item['item_id']; ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Manage Operations
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php elseif (!empty($models)): ?>
    <div class="text-center py-5">
        <i class="bi bi-arrow-up-circle" style="font-size: 4rem; color: #667eea;"></i>
        <h5 class="mt-3 mb-2">Select a Vehicle Model</h5>
        <p class="text-muted">Choose a vehicle model above to view and manage its maintenance items</p>
    </div>
<?php endif; ?>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Maintenance Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="model_id" value="<?php echo $selectedModelId; ?>">

                    <div class="mb-3">
                        <label for="item_name" class="form-label">Item Name *</label>
                        <input type="text" class="form-control" id="item_name" name="item_name" 
                               required placeholder="e.g., Engine Oil, Tires, Brake System">
                        <div class="form-text">The component or part that requires maintenance</div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Optional description of this maintenance item..."></textarea>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Examples:</strong> Engine Oil, Tires, Brake System, Air Filter, Battery, Coolant, Transmission Fluid
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Add Item
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
                <p>Are you sure you want to delete <strong id="deleteItemName"></strong>?</p>
                <p class="text-danger mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    This will also delete all maintenance operations associated with this item.
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="item_id" id="deleteItemId">
                    <input type="hidden" name="model_id" value="<?php echo $selectedModelId; ?>">
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
function confirmDelete(button) {
    document.getElementById('deleteItemId').value = button.dataset.item_id;
    document.getElementById('deleteItemName').textContent = button.dataset.item_name;

    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>
";

// Include footer
include 'includes/footer.php';
?>

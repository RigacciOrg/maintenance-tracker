<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'Maintenance History - Maintenance Tracker';
$page_heading = 'Maintenance History';
$current_page = 'history';

requireLogin();
$userId = getCurrentUserId();

$database = new Database();
$db       = $database->getConnection();

$success = '';
$error   = '';

// ─────────────────────────────────────────────────────────────────────
// Resolve vehicle
// ─────────────────────────────────────────────────────────────────────
$vehicleId = isset($_GET['vehicle_id'])
    ? (int)$_GET['vehicle_id']
    : (int)($_SESSION['selected_vehicle_id'] ?? 0);

if (!$vehicleId) {
    header('Location: index.php');
    exit();
}

// ─────────────────────────────────────────────────────────────────────
// Load vehicle (verify ownership)
// ─────────────────────────────────────────────────────────────────────
try {
    $vStmt = $db->prepare(
        "SELECT v.*, vm.manufacturer, vm.model_name, vm.unit_meter
         FROM   vehicles v
         JOIN   vehicle_models vm ON vm.model_id = v.model_id
         WHERE  v.vehicle_id = :vid AND v.user_id = :uid"
    );
    $vStmt->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
    $vStmt->bindParam(':uid', $userId,    PDO::PARAM_INT);
    $vStmt->execute();
    $vehicle = $vStmt->fetch();
} catch (PDOException $e) {
    $vehicle = null;
}

if (!$vehicle) {
    header('Location: index.php');
    exit();
}

$unitMeter = $vehicle['unit_meter'];

// ─────────────────────────────────────────────────────────────────────
// POST handler: delete history record
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_history') {
        $historyId = (int)($_POST['history_id'] ?? 0);

        try {
            // Verify the history record belongs to a vehicle owned by this user
            $checkStmt = $db->prepare(
                "SELECT mh.history_id
                 FROM   maintenance_history mh
                 JOIN   vehicles v ON v.vehicle_id = mh.vehicle_id
                 WHERE  mh.history_id = :hid AND v.user_id = :uid"
            );
            $checkStmt->bindParam(':hid', $historyId, PDO::PARAM_INT);
            $checkStmt->bindParam(':uid', $userId,    PDO::PARAM_INT);
            $checkStmt->execute();
            $row = $checkStmt->fetch();

            if ($row === false) {
                $error = 'History record not found or access denied.';
            } else {
                $delStmt = $db->prepare("DELETE FROM maintenance_history WHERE history_id = :hid");
                $delStmt->bindParam(':hid', $historyId, PDO::PARAM_INT);
                $delStmt->execute();

                $success = 'History record deleted successfully.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// Load complete maintenance history for this vehicle
// ─────────────────────────────────────────────────────────────────────
try {
    $historyStmt = $db->prepare(
        "SELECT mh.history_id, mh.operation_date, mh.operation_meter,
                mi.item_name, mo.operation_name
         FROM   maintenance_history mh
         LEFT   JOIN maintenance_operations mo ON mo.operation_id = mh.operation_id
         LEFT   JOIN maintenance_items mi ON mi.item_id = mo.item_id
         WHERE  mh.vehicle_id = :vid
         ORDER  BY mh.operation_date DESC, mh.created_at DESC"
    );
    $historyStmt->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
    $historyStmt->execute();
    $historyRecords = $historyStmt->fetchAll();
} catch (PDOException $e) {
    $historyRecords = [];
    $error = 'Could not load history: ' . $e->getMessage();
}

include 'includes/header.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">My Vehicles</a></li>
        <li class="breadcrumb-item">
            <a href="vehicle-info.php?vehicle_id=<?= $vehicleId ?>">
                <?= h($vehicle['nickname']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active">Maintenance History</li>
    </ol>
</nav>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<!-- Vehicle Info Header -->
<div class="card mb-4 shadow-sm">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-6 col-sm-3">
                <small class="text-muted d-block">Vehicle</small>
                <strong><?= h($vehicle['nickname']) ?></strong>
            </div>
            <div class="col-6 col-sm-3">
                <small class="text-muted d-block">Model</small>
                <strong><?= h($vehicle['manufacturer'] . ' ' . $vehicle['model_name']) ?></strong>
            </div>
            <div class="col-6 col-sm-3">
                <small class="text-muted d-block">Total Records</small>
                <strong><?php echo count($historyRecords); ?></strong>
            </div>
            <div class="col-6 col-sm-3">
                <small class="text-muted d-block">Unit</small>
                <strong><?= h($unitMeter) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- History Table -->
<?php if (empty($historyRecords)): ?>
    <div class="text-center py-5">
        <i class="bi bi-clock-history" style="font-size: 4rem; color: #667eea;"></i>
        <h4 class="mt-3 mb-2">No Maintenance History</h4>
        <p class="text-muted">
            No maintenance operations have been recorded yet for this vehicle.
        </p>
        <a href="maintenance-status.php?vehicle_id=<?php echo $vehicleId; ?>" 
           class="btn btn-primary mt-3">
            <i class="bi bi-clipboard-check me-2"></i>View Maintenance Status
        </a>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col-md-6 mb-2 mb-md-0">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        Complete Maintenance History
                        <span class="badge bg-secondary ms-2" id="recordCount"><?php echo count($historyRecords); ?></span>
                    </h5>
                </div>
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               id="searchInput"
                               placeholder="Search maintenance history..."
                               autocomplete="off">
                        <button class="btn btn-outline-secondary" 
                                type="button" 
                                id="clearSearch"
                                style="display: none;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row table-col-header border d-none d-sm-flex py-2 mx-0 bg-light text-dark fw-bold">
            <div class="col-sm-6 col-md-7">Maintenance Operation</div>
            <div class="col-sm-3 col-md-2">Date</div>
            <div class="col-sm-2 col-md-2">Meter (<?= h($unitMeter) ?>)</div>
            <div class="col-sm-1 col-md-1">&nbsp;</div>
        </div>
        <?php foreach ($historyRecords as $rec): ?>
            <?php
            $itemName = $rec['item_name'] ?: 'Unknown item';
            $operationName = $rec['operation_name'] ?: 'Unknown operation';
            $fullOperation = $itemName . ' - ' . $operationName;
            ?>
        <div class="row history-row table-row table-row-div border-start border-end border-bottom align-items-center py-1 mx-0"
            data-search-text="<?= h(strtolower($fullOperation)) ?>">
            <div class="col-12 col-sm-6 col-md-7 mb-2 mb-md-0">
                <strong><?= h($itemName) ?></strong>
                <span class="text-muted"> - <?= h($operationName) ?></span>
            </div>
            <div class="col-5 col-sm-3 col-md-2"><?= h(date('d M Y', strtotime($rec['operation_date']))) ?></div>
            <div class="col-5 col-sm-2 col-md-2"><?= h(number_format((float)$rec['operation_meter'], 0)) ?></div>
            <div class="col-2 col-sm-1 col-md-1 text-end pe-3">
                <button type="button"
                    class="btn btn-sm btn-outline-danger"
                    data-history_id="<?= h($rec['history_id']) ?>"
                    data-item_name="<?= h($itemName) ?>"
                    data-operation_name="<?= h($operationName) ?>"
                    data-operation_date="<?= h(date('d M Y', strtotime($rec['operation_date']))) ?>"
                    data-operation_meter="<?= h(number_format((float)$rec['operation_meter'], 0)) ?>"
                    onclick="openDeleteModal(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="card-footer bg-light text-muted small" id="noResults" style="display: none;">
            <i class="bi bi-info-circle me-1"></i>
            No records match your search.
        </div>
    </div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete History Record
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to delete this maintenance record?</p>

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <div class="mb-2">
                            <small class="text-muted d-block">Maintenance Item</small>
                            <strong id="deleteItemName"></strong>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted d-block">Operation</small>
                            <strong id="deleteOperationName"></strong>
                        </div>
                        <div>
                            <small class="text-muted d-block">Date</small>
                            <strong id="deleteOperationDate"></strong>
                        </div>
                        <div>
                            <small class="text-muted d-block">Meter (<?= h($unitMeter) ?>)</small>
                            <strong id="deleteOperationMeter"></strong>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    This will remove this record from the maintenance history. This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteHistoryForm">
                    <input type="hidden" name="action" value="delete_history">
                    <input type="hidden" name="history_id" id="deleteHistoryId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Record
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
    document.getElementById('deleteItemName').textContent = button.dataset.item_name;
    document.getElementById('deleteOperationName').textContent = button.dataset.operation_name;
    document.getElementById('deleteOperationDate').textContent = button.dataset.operation_date;
    document.getElementById('deleteOperationMeter').textContent = button.dataset.operation_meter;
    document.getElementById('deleteHistoryId').value = button.dataset.history_id;

    const modal = new bootstrap.Modal(document.getElementById('deleteHistoryModal'));
    modal.show();
}

// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const rows = document.querySelectorAll('.history-row');
    const noResults = document.getElementById('noResults');
    const recordCount = document.getElementById('recordCount');
    const totalRecords = rows.length;

    function performSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;

        rows.forEach(row => {
            const searchText = row.getAttribute('data-search-text');
            if (searchText.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update record count badge
        recordCount.textContent = visibleCount + ' / ' + totalRecords;

        // Show/hide no results message
        if (visibleCount === 0) {
            noResults.style.display = 'block';
        } else {
            noResults.style.display = 'none';
        }

        // Show/hide clear button
        if (searchTerm !== '') {
            clearBtn.style.display = 'block';
        } else {
            clearBtn.style.display = 'none';
            recordCount.textContent = totalRecords;
        }
    }

    searchInput.addEventListener('input', performSearch);

    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        performSearch();
        searchInput.focus();
    });
});
</script>
JS;

include 'includes/footer.php';
?>

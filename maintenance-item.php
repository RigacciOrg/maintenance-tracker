<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'Maintenance Item - Maintenance Tracker';
$page_heading = 'Maintenance Item';
$current_page = 'maintenance-status';

requireLogin();
$userId = getCurrentUserId();

$database = new Database();
$db       = $database->getConnection();

$success = '';
$error   = '';

// ─────────────────────────────────────────────────────────────────────
// Get parameters
// ─────────────────────────────────────────────────────────────────────
$itemId    = isset($_GET['item_id'])    ? (int)$_GET['item_id']    : 0;
$vehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if (!$itemId || !$vehicleId) {
    header('Location: index.php');
    exit();
}

// ─────────────────────────────────────────────────────────────────────
// Load vehicle (verify ownership)
// ─────────────────────────────────────────────────────────────────────
try {
    $vStmt = $db->prepare(
        "SELECT v.*, vm.manufacturer, vm.model_name, vm.vehicle_type,
                vm.unit_meter, vm.unit_time
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
$unitTime  = $vehicle['unit_time'];
$effMeter  = (float)$vehicle['effective_meter'];
$effDate   = $vehicle['effective_date'] ?? date('Y-m-d');

// ─────────────────────────────────────────────────────────────────────
// Load maintenance item (verify it belongs to this vehicle's model)
// ─────────────────────────────────────────────────────────────────────
try {
    $itemStmt = $db->prepare(
        "SELECT mi.item_id, mi.item_name, mi.description, mi.model_id
         FROM   maintenance_items mi
         WHERE  mi.item_id = :iid"
    );
    $itemStmt->bindParam(':iid', $itemId, PDO::PARAM_INT);
    $itemStmt->execute();
    $item = $itemStmt->fetch();
} catch (PDOException $e) {
    $item = null;
}

if (!$item || $item['model_id'] != $vehicle['model_id']) {
    header('Location: maintenance-status.php?vehicle_id=' . $vehicleId);
    exit();
}

// ─────────────────────────────────────────────────────────────────────
// POST handler: register operation done
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_operation') {
        $operationId = (int)($_POST['operation_id'] ?? 0);

        // Verify operation belongs to this item
        $opCheck = $db->prepare(
            "SELECT operation_id FROM maintenance_operations WHERE operation_id = :opid AND item_id = :iid"
        );
        $opCheck->bindParam(':opid', $operationId, PDO::PARAM_INT);
        $opCheck->bindParam(':iid',  $itemId,      PDO::PARAM_INT);
        $opCheck->execute();
        $row = $opCheck->fetch();

        if ($row === false) {
            $error = 'Invalid operation.';
        } else {
            try {
                $ins = $db->prepare(
                    "INSERT INTO maintenance_history
                        (vehicle_id, operation_id, operation_date, operation_meter)
                     VALUES
                        (:vid, :opid, :op_date, :op_meter)"
                );
                $ins->bindParam(':vid',      $vehicleId,   PDO::PARAM_INT);
                $ins->bindParam(':opid',     $operationId, PDO::PARAM_INT);
                $ins->bindParam(':op_date',  $effDate);
                $ins->bindParam(':op_meter', $effMeter);
                $ins->execute();

                $success = 'Operation registered successfully.';
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// Load operations for this item with calculated status
// ─────────────────────────────────────────────────────────────────────
try {
    $opsStmt = $db->prepare(
        "SELECT operation_id, operation_name, interval_meter, interval_time, description
         FROM   maintenance_operations
         WHERE  item_id = :iid
         ORDER  BY operation_name"
    );
    $opsStmt->bindParam(':iid', $itemId, PDO::PARAM_INT);
    $opsStmt->execute();
    $operations = $opsStmt->fetchAll();
} catch (PDOException $e) {
    $operations = [];
    $error = 'Could not load operations: ' . $e->getMessage();
}

// Calculate status for each operation
$operationStatuses = [];

foreach ($operations as $op) {
    $opId          = $op['operation_id'];
    $intervalMeter = $op['interval_meter'] !== null ? (float)$op['interval_meter'] : null;
    $intervalTime  = $op['interval_time']  !== null ? (int)$op['interval_time']    : null;

    // Find most recent history
    $histStmt = $db->prepare(
        "SELECT operation_meter, operation_date
         FROM   maintenance_history
         WHERE  vehicle_id = :vid AND operation_id = :opid
         ORDER  BY operation_date DESC, created_at DESC
         LIMIT  1"
    );
    $histStmt->bindParam(':vid',  $vehicleId, PDO::PARAM_INT);
    $histStmt->bindParam(':opid', $opId,      PDO::PARAM_INT);
    $histStmt->execute();
    $lastOp = $histStmt->fetch();

    // ── Meter availability ────────────────────────────────────────
    $meterAvail = null;
    $meterUrg   = 0;

    if ($intervalMeter !== null) {
        $lastMeter = 0; // Default: never executed
        if ($lastOp && $lastOp['operation_meter'] !== null) {
            $lastMeter = (float)$lastOp['operation_meter'];
        }
        $meterAvail = $intervalMeter - ($effMeter - $lastMeter);
        // Urgency percentage for meter
        $meterUrg   = (($intervalMeter - $meterAvail) / $intervalMeter) * 100;
        $meterUrg   = max(0, $meterUrg);
    }

    // ── Time availability ─────────────────────────────────────────
    $timeAvail = null;
    $timeUrg   = 0;

    if ($intervalTime !== null) {
        // Convert $intervalTime to days.
        $timeUnitDays = $timeUnitsDays[$unitTime] ?? 1;
        $intervalTimeDays = $intervalTime * $timeUnitDays;
        // Reference date: last operation date, or vehicle start date, or effective date
        if ($lastOp && $lastOp['operation_date'] !== null) {
            $lastDate = new DateTime($lastOp['operation_date']);
        } elseif ($vehicle['start_date'] !== null) {
            $lastDate = new DateTime($vehicle['start_date']);
        } else {
            $lastDate = new DateTime($effDate);
        }

        $currentDate   = new DateTime($effDate);
        $elapsed       = $currentDate->diff($lastDate)->days;
        $timeAvailDays = $intervalTimeDays - $elapsed;
        $timeAvail     = round($timeAvailDays / $timeUnitDays);
        // Urgency percentage for time
        $timeUrg     = (($intervalTimeDays - $timeAvailDays) / $intervalTimeDays) * 100;
        $timeUrg     = max(0, $timeUrg); // clamp to 0 minimum
    }

    // Overall urgency for this operation
    $urgency = max($meterUrg, $timeUrg);

    if ($urgency < 100) {
        $urgencyColour = 'success';
        $urgencyIcon   = 'check-circle-fill';
    } elseif ($urgency < 200) {
        $urgencyColour = 'warning';
        $urgencyIcon   = 'exclamation-triangle-fill';
    } else {
        $urgencyColour = 'danger';
        $urgencyIcon   = 'exclamation-octagon-fill';
    }

    $operationStatuses[] = [
        'operation_id'    => $opId,
        'operation_name'  => $op['operation_name'],
        'description'     => $op['description'],
        'interval_meter'  => $intervalMeter,
        'interval_time'   => $intervalTime,
        'meter_avail'     => $meterAvail,
        'time_avail'      => $timeAvail,
        'urgency'         => $urgency,
        'urgency_colour'  => $urgencyColour,
        'urgency_icon'    => $urgencyIcon,
    ];
}

// Sort by urgency descending
usort($operationStatuses, function($a, $b) {
    return $b['urgency'] <=> $a['urgency'];
});

// ─────────────────────────────────────────────────────────────────────
// Load full history for this item (all operations on this item + vehicle)
// ─────────────────────────────────────────────────────────────────────
try {
    $historyStmt = $db->prepare(
        "SELECT mh.history_id, mh.operation_date, mh.operation_meter,
                mo.operation_name
         FROM   maintenance_history mh
         JOIN   maintenance_operations mo ON mo.operation_id = mh.operation_id
         WHERE  mh.vehicle_id = :vid
         AND    mo.item_id = :iid
         ORDER  BY mh.operation_date DESC, mh.created_at DESC"
    );
    $historyStmt->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
    $historyStmt->bindParam(':iid', $itemId,    PDO::PARAM_INT);
    $historyStmt->execute();
    $historyRecords = $historyStmt->fetchAll();
} catch (PDOException $e) {
    $historyRecords = [];
}

$additional_styles = '
.urgency-badge {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: inline-block;
    margin-right: .5rem;
}
.operation-card {
    border-left: 4px solid transparent;
    transition: transform .15s, box-shadow .15s;
}
.operation-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0,0,0,.1);
}
.operation-card-success { border-left-color: #198754; }
.operation-card-warning { border-left-color: #ffc107; }
.operation-card-danger  { border-left-color: #dc3545; }
';

include 'includes/header.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">My Vehicles</a></li>
        <li class="breadcrumb-item">
            <a href="vehicle-info.php?vehicle_id=<?php echo $vehicleId; ?>">
                <?= h($vehicle['nickname']) ?> 
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="maintenance-status.php?vehicle_id=<?php echo $vehicleId; ?>">
                Maintenance Status
            </a>
        </li>
        <li class="breadcrumb-item active">
            <?= h($item['item_name']) ?> 
        </li>
    </ol>
</nav>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">

        <!-- Item Header -->
        <div class="card mb-4 shadow-sm">
            <div class="card-body">
                <h4 class="mb-2">
                    <i class="bi bi-tools me-2 text-primary"></i>
                    <?= h($item['item_name']) ?> 
                </h4>
                <?php if ($item['description']): ?>
                    <p class="text-muted mb-3">
                        <?= h($item['description']) ?> 
                    </p>
                <?php endif; ?>
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
                        <small class="text-muted d-block">Current Meter</small>
                        <strong><?= h(number_format($effMeter, 0) . ' ' . $unitMeter) ?></strong>
                    </div>
                    <div class="col-6 col-sm-3">
                        <small class="text-muted d-block">Reference Date</small>
                        <strong><?= h(date('d M Y', strtotime($effDate))) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operations List -->
        <h5 class="mb-3">
            <i class="bi bi-list-check me-2"></i>
            Maintenance Operations
            <span class="badge bg-secondary ms-1"><?php echo count($operationStatuses); ?></span>
        </h5>

        <?php if (empty($operationStatuses)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                No operations defined for this maintenance item.
            </div>
        <?php else: ?>
            <div class="row g-3 mb-5">
                <?php foreach ($operationStatuses as $opStatus): ?>
                    <div class="col-12">
                        <div class="card operation-card operation-card-<?php echo $opStatus['urgency_colour']; ?> shadow-sm">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-12 col-md-6 mb-3 mb-md-0">
                                        <div class="d-flex align-items-start">
                                            <span class="urgency-badge bg-<?php echo $opStatus['urgency_colour']; ?>"></span>
                                            <div>
                                                <h6 class="mb-1">
                                                    <?= h($opStatus['operation_name']) ?> 
                                                </h6>
                                                <?php if ($opStatus['description']): ?>
                                                    <small class="text-muted d-block mb-2">
                                                        <?= h($opStatus['description']) ?> 
                                                    </small>
                                                <?php endif; ?>

                                                <!-- Intervals -->
                                                <div class="small text-muted">
                                                    <?php if ($opStatus['interval_meter'] !== null): ?>
                                                        <span class="me-3">
                                                            <i class="bi bi-speedometer2 me-1"></i>
                                                            Every <?= h(number_format($opStatus['interval_meter'], 0) . ' ' . $unitMeter) ?> 
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($opStatus['interval_time'] !== null): ?>
                                                        <span>
                                                            <i class="bi bi-clock me-1"></i>
                                                            Every <?= h($opStatus['interval_time'] . ' ' . $unitTime) ?> 
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <div class="row g-2 align-items-center">
                                            <!-- Urgency & Availability -->
                                            <div class="col-12 col-sm-7 mb-3 mb-sm-0 pe-sm-5">
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <small class="text-muted">Urgency</small>
                                                        <strong class="text-<?php echo $opStatus['urgency_colour']; ?>">
                                                            <?php echo round($opStatus['urgency'], 0); ?>%
                                                        </strong>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-<?php echo $opStatus['urgency_colour']; ?>"
                                                             style="width: <?php echo min(100, $opStatus['urgency']); ?>%"></div>
                                                    </div>
                                                </div>

                                                <div class="d-flex justify-content-between small">
                                                    <div>
                                                        <i class="bi bi-speedometer2 me-1 text-muted"></i>
                                                        <?php if ($opStatus['meter_avail'] !== null): ?>
                                                            <?php
                                                            $avail = $opStatus['meter_avail'];
                                                            if ($avail < 0) {
                                                                echo '<span class="text-danger fw-bold">Overdue</span>';
                                                            } else {
                                                                echo h(number_format($avail, 0) . ' ' . $unitMeter);
                                                            }
                                                            ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <i class="bi bi-clock me-1 text-muted"></i>
                                                        <?php if ($opStatus['time_avail'] !== null): ?>
                                                            <?php
                                                            $avail = $opStatus['time_avail'];
                                                            if ($avail < 0) {
                                                                echo '<span class="text-danger fw-bold">Overdue</span>';
                                                            } else {
                                                                echo $avail . ' ' . h($unitTime);
                                                            }
                                                            ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Action Button -->
                                            <div class="col-12 col-sm-5 text-sm-end">
                                                <button type="button" 
                                                        class="btn btn-primary btn-sm w-100"
                                                        data-operation_id="<?= h($opStatus['operation_id']) ?>"
                                                        data-operation_name="<?= h($opStatus['operation_name']) ?>"
                                                        data-item_name="<?= h($item['item_name']) ?>"
                                                        data-meter="<?= h(number_format($effMeter, 0)) ?>"
                                                        data-meter_unit="<?= h($unitMeter) ?>"
                                                        data-date="<?= h(date('d M Y', strtotime($effDate))) ?>"
                                                        onclick="openRegisterModal(this)">
                                                    <i class="bi bi-check-circle me-1"></i>
                                                    Done Now
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- History Section (collapsible) -->
        <h5 class="mb-3">
            <i class="bi bi-clock-history me-2"></i>
            Maintenance History
            <span class="badge bg-secondary ms-1"><?php echo count($historyRecords); ?></span>
        </h5>

        <?php if (empty($historyRecords)): ?>
            <div class="alert alert-info mb-5">
                <i class="bi bi-info-circle me-2"></i>
                No maintenance history recorded yet for this item.
            </div>
        <?php else: ?>
            <div class="card shadow-sm mb-5">
                <div class="card-header bg-light">
                    <a class="text-decoration-none text-dark d-flex justify-content-between align-items-center"
                       data-bs-toggle="collapse" href="#historyCollapse" role="button"
                       aria-expanded="false" aria-controls="historyCollapse">
                        <span>
                            <i class="bi bi-list-ul me-2"></i>
                            <?php echo count($historyRecords); ?> record<?php echo count($historyRecords) === 1 ? '' : 's'; ?>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </a>
                </div>
                <div class="collapse" id="historyCollapse">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Operation</th>
                                    <th>Date</th>
                                    <th>Meter (<?= h($unitMeter) ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historyRecords as $rec): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($rec['operation_name']) ?></strong>
                                        </td>
                                        <td>
                                            <?= h(date('d M Y', strtotime($rec['operation_date']))) ?> 
                                        </td>
                                        <td>
                                            <?php if ($rec['operation_meter'] !== null): ?>
                                                <?= h(number_format((float)$rec['operation_meter'], 0)) ?> 
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Register Operation Confirmation Modal -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2"></i>Register Maintenance Operation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">You are about to register the following maintenance operation:</p>
                
                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-12">
                                <small class="text-muted d-block">Maintenance Item</small>
                                <strong id="modalItemName"></strong>
                            </div>
                            <div class="col-12">
                                <small class="text-muted d-block">Operation</small>
                                <strong id="modalOperationName"></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Date</small>
                                <strong id="modalDate"></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Meter Reading</small>
                                <strong id="modalMeter"></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This will record that the operation was completed at the current vehicle meter reading and date.
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="registerForm">
                    <input type="hidden" name="action" value="register_operation">
                    <input type="hidden" name="operation_id" id="modalOperationId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Register
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function openRegisterModal(button) {
    document.getElementById('modalItemName').textContent = button.dataset.item_name;
    document.getElementById('modalOperationName').textContent = button.dataset.operation_name;
    document.getElementById('modalDate').textContent = button.dataset.date;
    document.getElementById('modalMeter').textContent = button.dataset.meter + ' ' + button.dataset.meter_unit;
    document.getElementById('modalOperationId').value = button.dataset.operation_id;

    const modal = new bootstrap.Modal(document.getElementById('registerModal'));
    modal.show();
}
</script>
JS;
?>

<?php
include 'includes/footer.php';
?>

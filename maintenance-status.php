<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'Maintenance Status - Maintenance Tracker';
$page_heading = 'Maintenance Status';
$current_page = 'maintenance-status';

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
// Load vehicle with model info
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
// Load maintenance items with operations for this vehicle's model
// ─────────────────────────────────────────────────────────────────────
try {
    $itemsStmt = $db->prepare(
        "SELECT mi.item_id, mi.item_name, mi.description
         FROM   maintenance_items mi
         WHERE  mi.model_id = :model_id
         ORDER  BY mi.item_name"
    );
    $itemsStmt->bindParam(':model_id', $vehicle['model_id'], PDO::PARAM_INT);
    $itemsStmt->execute();
    $items = $itemsStmt->fetchAll();
} catch (PDOException $e) {
    $items = [];
    $error = 'Could not load maintenance items: ' . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────────────
// Calculate status for each item
// ─────────────────────────────────────────────────────────────────────
$itemStatuses = [];

foreach ($items as $item) {
    $itemId = $item['item_id'];

    // ── Load operations for this item ─────────────────────────────────
    $opsStmt = $db->prepare(
        "SELECT operation_id, operation_name, interval_meter, interval_time
         FROM   maintenance_operations
         WHERE  item_id = :item_id"
    );
    $opsStmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
    $opsStmt->execute();
    $operations = $opsStmt->fetchAll();

    if (empty($operations)) {
        // Item has no operations defined — skip
        continue;
    }

    // ── Per-operation calculations ────────────────────────────────────
    $meterAvails = [];
    $timeAvails  = [];
    $urgencies   = [];

    foreach ($operations as $op) {
        $opId          = $op['operation_id'];
        $intervalMeter = $op['interval_meter'] !== null ? (float)$op['interval_meter'] : null;
        $intervalTime  = $op['interval_time']  !== null ? (int)$op['interval_time']    : null;

        // Find most recent history entry for this operation on this vehicle
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
            $lastMeter = 0; // Default: operation never executed
            if ($lastOp && $lastOp['operation_meter'] !== null) {
                $lastMeter = (float)$lastOp['operation_meter'];
            }
            $meterAvail = $intervalMeter - ($effMeter - $lastMeter);
            // Urgency percentage for meter
            $meterUrg = (($intervalMeter - $meterAvail) / $intervalMeter) * 100;
            $meterUrg = max(0, $meterUrg); // clamp to 0 minimum
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
            $timeUrg = (($intervalTimeDays - $timeAvailDays) / $intervalTimeDays) * 100;
            $timeUrg = max(0, $timeUrg); // clamp to 0 minimum
        }

        // Collect for aggregation
        if ($meterAvail !== null) {
            $meterAvails[] = $meterAvail;
        }
        if ($timeAvail !== null) {
            $timeAvails[] = $timeAvail;
        }
        $urgencies[] = max($meterUrg, $timeUrg);
    }

    // ── Aggregate: minimum availability, maximum urgency ──────────────
    $finalMeterAvail = !empty($meterAvails) ? min($meterAvails) : null;
    $finalTimeAvail  = !empty($timeAvails)  ? min($timeAvails)  : null;
    $finalUrgency    = !empty($urgencies)   ? max($urgencies)   : 0;

    // Determine urgency colour
    if ($finalUrgency < 100) {
        $urgencyColour = 'success'; // green
        $urgencyIcon   = 'check-circle-fill';
    } elseif ($finalUrgency < 200) {
        $urgencyColour = 'warning'; // yellow
        $urgencyIcon   = 'exclamation-triangle-fill';
    } else {
        $urgencyColour = 'danger'; // red
        $urgencyIcon   = 'exclamation-octagon-fill';
    }

    $itemStatuses[] = [
        'item_id'        => $itemId,
        'item_name'      => $item['item_name'],
        'description'    => $item['description'],
        'meter_avail'    => $finalMeterAvail,
        'time_avail'     => $finalTimeAvail,
        'urgency'        => $finalUrgency,
        'urgency_colour' => $urgencyColour,
        'urgency_icon'   => $urgencyIcon,
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Calculate overall vehicle urgency
// ─────────────────────────────────────────────────────────────────────
$overallUrgency = 0;
if (!empty($itemStatuses)) {
    $allUrgencies   = array_column($itemStatuses, 'urgency');
    $overallUrgency = max($allUrgencies);
}

if ($overallUrgency < 100) {
    $overallColour = 'success';
    $overallIcon   = 'check-circle-fill';
    $overallLabel  = 'Good';
} elseif ($overallUrgency < 200) {
    $overallColour = 'warning';
    $overallIcon   = 'exclamation-triangle-fill';
    $overallLabel  = 'Attention';
} else {
    $overallColour = 'danger';
    $overallIcon   = 'exclamation-octagon-fill';
    $overallLabel  = 'Urgent';
}

// ─────────────────────────────────────────────────────────────────────
// Sort items by urgency descending (most urgent first)
// ─────────────────────────────────────────────────────────────────────
usort($itemStatuses, function($a, $b) {
    return $b['urgency'] <=> $a['urgency'];
});

$additional_styles = '
.urgency-badge {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: inline-block;
    margin-right: .5rem;
}
.item-card {
    transition: transform .2s, box-shadow .2s;
    border-left: 4px solid transparent;
}
.item-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
}
.item-card-success { border-left-color: #198754; }
.item-card-warning { border-left-color: #ffc107; }
.item-card-danger  { border-left-color: #dc3545; }

.overall-status-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
';

include 'includes/header.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">My Vehicles</a></li>
        <li class="breadcrumb-item">
            <a href="vehicle-info.php"><?= h($vehicle['nickname']) ?></a>
        </li>
        <li class="breadcrumb-item active">Maintenance Status</li>
    </ol>
</nav>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<!-- Vehicle Header Card -->
<div class="card overall-status-card shadow-sm mb-4">
    <div class="card-body py-4">
        <div class="text-center mb-3">
            <h1 class="mb-0" style="font-size: 2.5rem; font-weight: 700;">
                <?= h($vehicle['nickname']) ?> 
            </h1>
        </div>
        <div class="row g-2">
            <div class="col-6 col-sm-4">
                <div class="bg-white bg-opacity-25 rounded p-2 text-center">
                    <small class="d-block opacity-75">Model</small>
                    <strong><?= h($vehicle['manufacturer'] . ' ' . $vehicle['model_name']) ?></strong>
                </div>
            </div>
            <div class="col-6 col-sm-4">
                <div class="bg-white bg-opacity-25 rounded p-2 text-center">
                    <small class="d-block opacity-75">Current Meter</small>
                    <strong><?= h(number_format($effMeter, 0) . ' ' . $unitMeter) ?></strong>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="bg-white bg-opacity-25 rounded p-2 text-center">
                    <small class="d-block opacity-75">Reference Date</small>
                    <strong><?= h(date('d M Y', strtotime($effDate))) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Items List -->
<?php if (empty($itemStatuses)): ?>
    <div class="text-center py-5">
        <i class="bi bi-tools" style="font-size: 4rem; color: #667eea;"></i>
        <h4 class="mt-3 mb-2">No Maintenance Items</h4>
        <p class="text-muted">
            No maintenance programs are defined for this vehicle model.
        </p>
        <a href="maintenance-program.php" class="btn btn-primary mt-3">
            <i class="bi bi-calendar-check me-2"></i>Set Up Maintenance Programs
        </a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($itemStatuses as $status): ?>
            <div class="col-12 col-md-6 col-xl-3">
                <a href="maintenance-item.php?item_id=<?php echo $status['item_id']; ?>&vehicle_id=<?php echo $vehicleId; ?>"
                   class="text-decoration-none">
                    <div class="card item-card item-card-<?php echo $status['urgency_colour']; ?> h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-3">
                                <span class="urgency-badge bg-<?php echo $status['urgency_colour']; ?>"></span>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 text-dark">
                                        <?= h($status['item_name']) ?> 
                                    </h6>
                                    <?php if ($status['description']): ?>
                                        <small class="text-muted">
                                            <?= h($status['description']) ?> 
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Urgency -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Urgency</small>
                                    <strong class="text-<?php echo $status['urgency_colour']; ?>">
                                        <?php echo round($status['urgency'], 0); ?>%
                                    </strong>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-<?php echo $status['urgency_colour']; ?>"
                                         style="width: <?php echo min(100, $status['urgency']); ?>%"></div>
                                </div>
                            </div>

                            <!-- Availability -->
                            <div class="d-flex justify-content-between small">
                                <div class="text-muted">
                                    <i class="bi bi-speedometer2 me-1"></i>
                                    <?php if ($status['meter_avail'] !== null): ?>
                                        <?php
                                        $avail = $status['meter_avail'];
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
                                <div class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    <?php if ($status['time_avail'] !== null): ?>
                                        <?php
                                        $avail = $status['time_avail'];
                                        if ($avail < 0) {
                                            echo '<span class="text-danger fw-bold">Overdue</span>';
                                        } else {
                                            echo h($avail . ' ' . $unitTime);
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>

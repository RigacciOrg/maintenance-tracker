<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'Add Vehicle - Maintenance Tracker';
$page_heading = 'Add Vehicle';
$current_page = 'vehicles';

requireLogin();
$userId = getCurrentUserId();

$database = new Database();
$db       = $database->getConnection();

$success = '';
$error   = '';

// ─────────────────────────────────────────────────────────────────────
// Load user's vehicle models for the dropdown
// ─────────────────────────────────────────────────────────────────────
try {
    $modelStmt = $db->prepare(
        "SELECT model_id, manufacturer, model_name, vehicle_type, unit_meter, unit_time
         FROM   vehicle_models
         WHERE  user_id = :uid
         ORDER  BY manufacturer, model_name"
    );
    $modelStmt->bindParam(':uid', $userId, PDO::PARAM_INT);
    $modelStmt->execute();
    $models = $modelStmt->fetchAll();
} catch (PDOException $e) {
    $models = [];
    $error  = h('Could not load vehicle models: ' . $e->getMessage());
}

// JSON map of model metadata → used by JS to update unit labels on model change
$modelsJson = json_encode(
    array_column($models, null, 'model_id'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);

// ─────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nickname        = trim($_POST['nickname']        ?? '');
    $model_id        = $_POST['model_id']             ?? '';
    $license_plate   = trim($_POST['license_plate']   ?? '');
    $vin             = trim($_POST['vin']              ?? '');
    $start_date      = trim($_POST['start_date']       ?? '');
    $effective_meter = trim($_POST['effective_meter']  ?? '');
    $effective_date  = trim($_POST['effective_date']   ?? '');
    $notes           = trim($_POST['notes']            ?? '');

    // Coerce types / nullify empty optionals
    $model_id        = ($model_id       === '') ? null : (int)$model_id;
    $license_plate   = ($license_plate  === '') ? null : $license_plate;
    $vin             = ($vin            === '') ? null : $vin;
    $start_date      = ($start_date     === '') ? null : $start_date;
    $effective_meter = ($effective_meter === '') ? 0   : (float)$effective_meter;
    $effective_date  = ($effective_date  === '') ? null : $effective_date;
    $notes           = ($notes          === '') ? null : $notes;

    // ── Validation ────────────────────────────────────
    if (empty($nickname)) {
        $error = h('A vehicle name / nickname is required.');

    } elseif ($model_id === null) {
        $error = h('A vehicle model must be selected.');

    } else {
        // Make sure the chosen model actually belongs to this user
        $chk = $db->prepare(
            "SELECT model_id FROM vehicle_models WHERE model_id = :mid AND user_id = :uid"
        );
        $chk->bindParam(':mid', $model_id, PDO::PARAM_INT);
        $chk->bindParam(':uid', $userId,   PDO::PARAM_INT);
        $chk->execute();
        $row = $chk->fetch();
        if ($row === false) {
            $error    = h('The selected model does not exist or does not belong to you.');
            $model_id = null;
        }
    }

    // ── Insert ────────────────────────────────────────
    if (empty($error)) {
        try {
            $ins = $db->prepare(
                "INSERT INTO vehicles
                     (user_id, model_id, nickname, license_plate, vin,
                      start_date, effective_meter, effective_date, notes)
                 VALUES
                     (:uid, :mid, :nickname, :plate, :vin,
                      :start_date, :eff_meter, :eff_date, :notes)"
            );
            $ins->bindParam(':uid',       $userId,          PDO::PARAM_INT);
            $ins->bindValue(':mid',       $model_id,        $model_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $ins->bindParam(':nickname',  $nickname);
            $ins->bindParam(':plate',     $license_plate);
            $ins->bindParam(':vin',       $vin);
            $ins->bindParam(':start_date',$start_date);
            $ins->bindParam(':eff_meter', $effective_meter);
            $ins->bindParam(':eff_date',  $effective_date);
            $ins->bindParam(':notes',     $notes);
            $ins->execute();

            $success = "Vehicle <strong>" . h($nickname) . "</strong> added successfully!";

            // Clear form so the user can add another vehicle right away
            $nickname = $license_plate = $vin = $start_date = '';
            $effective_meter = '';
            $effective_date  = date('Y-m-d');
            $notes           = '';
            $model_id        = null;

        } catch (PDOException $e) {
            // Check for unique violation 23505 or integrity constraint violation 23000.
            $sqlState = $e->errorInfo[0];
            if (in_array($sqlState, ['23000', '23505'])) {
                $error = h('A vehicle with this name already exists');
            } else {
                $error = h('Database error: ' . $e->getMessage());
            }
        }
    }
}

// Form re-population values (safe fallbacks)
$f = [
    'nickname'        => $nickname        ?? '',
    'model_id'        => $model_id        ?? '',
    'license_plate'   => $license_plate   ?? '',
    'vin'             => $vin             ?? '',
    'start_date'      => $start_date      ?? '',
    'effective_meter' => $effective_meter ?? '',
    'effective_date'  => $effective_date  ?? date('Y-m-d'),
    'notes'           => $notes           ?? '',
];

include 'includes/header.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">My Vehicles</a></li>
        <li class="breadcrumb-item active">Add Vehicle</li>
    </ol>
</nav>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?= $success ?> 
        — <a href="index.php" class="alert-link">Go to My Vehicles</a>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?= $error ?> 
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($models)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        You have no vehicle models yet.
        <a href="vehicle-models.php" class="alert-link">Create a vehicle model first</a>
        before adding a vehicle.
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-9 col-xl-8">
        <form method="POST" id="addVehicleForm" novalidate>

            <!-- ════════════════════════════════════════
                 SECTION 1 – Identity
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-tag-fill"></i>&nbsp;Vehicle Identity
                </h5>

                <!-- Nickname -->
                <div class="mb-3">
                    <label for="nickname" class="form-label">
                        Name / Nickname <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg"
                           id="nickname" name="nickname"
                           required maxlength="100"
                           placeholder="e.g. Daily Driver, Red Corolla, Work Truck…"
                           value="<?php echo htmlspecialchars($f['nickname']); ?>">
                    <div class="form-text">
                        The friendly label shown on the vehicle selection screen.
                    </div>
                </div>

                <!-- Plate + VIN -->
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="license_plate" class="form-label">License Plate</label>
                        <input type="text" class="form-control auto-upper"
                               id="license_plate" name="license_plate"
                               maxlength="20" placeholder="e.g. AB 123 CD"
                               value="<?php echo htmlspecialchars($f['license_plate']); ?>">
                    </div>
                    <div class="col-sm-6">
                        <label for="vin" class="form-label">
                            VIN
                            <small class="text-muted fw-normal">
                                — Vehicle Identification Number
                            </small>
                        </label>
                        <input type="text" class="form-control auto-upper"
                               id="vin" name="vin"
                               maxlength="50" placeholder="Chassis code"
                               value="<?php echo htmlspecialchars($f['vin']); ?>">
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION 2 – Model
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-car-front-fill"></i>&nbsp;Vehicle Model
                </h5>

                <?php if (empty($models)): ?>
                    <p class="text-muted mb-0">
                        No models available.
                        <a href="vehicle-models.php">Create one first</a>.
                    </p>
                <?php else: ?>
                    <div class="mb-3">
                        <label for="model_id" class="form-label">
                            Select Model <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="model_id" name="model_id"
                                required onchange="onModelChange(this.value)">
                            <option value="">— Select a model —</option>
                            <?php foreach ($models as $m): ?>
                                <option value="<?php echo $m['model_id']; ?>"
                                    <?php echo ($f['model_id'] == $m['model_id']) ? 'selected' : ''; ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        $m['manufacturer'] . ' ' . $m['model_name'] .
                                        ' (' . ucfirst($m['vehicle_type']) . ')'
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dynamic info strip filled by JS -->
                    <div id="modelInfoStrip" class="d-none mb-2">
                        <span class="badge bg-dark   me-1" id="badgeType"></span>
                        <span class="badge bg-primary me-1" id="badgeMeter"></span>
                        <span class="badge bg-info        " id="badgeTime"></span>
                    </div>

                    <a href="vehicle-models.php" class="btn btn-sm btn-outline-secondary mt-1">
                        <i class="bi bi-plus-circle me-1"></i>Add new model
                    </a>
                <?php endif; ?>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION 3 – Dates & Meter
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-speedometer2"></i>&nbsp;Dates &amp; Meter Reading
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control"
                               id="start_date" name="start_date"
                               value="<?php echo htmlspecialchars($f['start_date']); ?>">
                        <div class="form-text">Purchase, lease or registration date.</div>
                    </div>
                    <div class="col-sm-6">
                        <label for="effective_date" class="form-label">
                            Effective Date
                        </label>
                        <input type="date" class="form-control"
                               id="effective_date" name="effective_date"
                               value="<?php echo htmlspecialchars($f['effective_date']); ?>">
                        <div class="form-text">
                            Reference date used for maintenance calculations
                            (usually today).
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="effective_meter" class="form-label">
                            Effective Meter Reading
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control"
                                   id="effective_meter" name="effective_meter"
                                   min="0" step="0.01" placeholder="0"
                                   value="<?php echo htmlspecialchars($f['effective_meter']); ?>">
                            <span class="input-group-text" id="meterUnitSuffix">km</span>
                        </div>
                        <div class="form-text">
                            Current odometer / hour-meter reading at the
                            effective date above.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION 4 – Notes
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-sticky-fill"></i>&nbsp;Notes
                </h5>
                <textarea class="form-control" id="notes" name="notes" rows="4"
                          placeholder="Any additional information about this vehicle…"
                    ><?php echo htmlspecialchars($f['notes'] ?? ''); ?></textarea>
            </div>

            <!-- ════════════════════════════════════════
                 Action buttons
            ════════════════════════════════════════ -->
            <div class="d-flex gap-2 justify-content-end mb-5">
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-circle-fill me-2"></i>Add Vehicle
                </button>
            </div>

        </form>
    </div>
</div>

<?php
$additional_styles = '
.section-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    color: #444;
    margin-bottom: 1.25rem;
    padding-bottom: .75rem;
    border-bottom: 2px solid #f0f0f0;
}
.section-title i {
    color: #667eea;
}
';

$extra_js = <<<SCRIPT
<script>
const models = {$modelsJson};

function onModelChange(id) {
    const strip  = document.getElementById('modelInfoStrip');
    const suffix = document.getElementById('meterUnitSuffix');

    if (!id || !models[id]) {
        strip.classList.add('d-none');
        suffix.textContent = 'km';
        return;
    }

    const m = models[id];

    document.getElementById('badgeType').innerHTML  =
        '<i class="bi bi-car-front me-1"></i>' +
        m.vehicle_type.charAt(0).toUpperCase() + m.vehicle_type.slice(1);

    document.getElementById('badgeMeter').innerHTML =
        '<i class="bi bi-speedometer2 me-1"></i>' + m.unit_meter;

    document.getElementById('badgeTime').innerHTML  =
        '<i class="bi bi-clock me-1"></i>' + m.unit_time;

    suffix.textContent = m.unit_meter;
    strip.classList.remove('d-none');
}

document.addEventListener('DOMContentLoaded', function () {
    // Restore model info strip after a failed POST
    const sel = document.getElementById('model_id');
    if (sel) onModelChange(sel.value);

    // Auto-uppercase while typing for plate and VIN
    document.querySelectorAll('.auto-upper').forEach(function (el) {
        el.addEventListener('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    });
});
</script>
SCRIPT;

include 'includes/footer.php';
?>

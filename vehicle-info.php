<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/app_data.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'Vehicle Info - Maintenance Tracker';
$page_heading = 'Vehicle Info';
$current_page = 'vehicle-info';

requireLogin();
$userId = getCurrentUserId();

$database = new Database();
$db       = $database->getConnection();

$success = '';
$error   = '';

// ─────────────────────────────────────────────────────────────────────
// Resolve vehicle: accept ?vehicle_id=N in URL, fall back to session
// ─────────────────────────────────────────────────────────────────────
$vehicleId = isset($_GET['vehicle_id'])
    ? (int)$_GET['vehicle_id']
    : (int)($_SESSION['selected_vehicle_id'] ?? 0);

if (!$vehicleId) {
    header('Location: index.php');
    exit();
}

// ─────────────────────────────────────────────────────────────────────
// Load vehicle (must belong to current user)
// ─────────────────────────────────────────────────────────────────────
try {
    $vStmt = $db->prepare(
        "SELECT v.*,
                vm.manufacturer, vm.model_name, vm.vehicle_type,
                vm.unit_meter,   vm.unit_time,   vm.year_range
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

// Handy shortcuts
$unitMeter = $vehicle['unit_meter'];
$unitTime  = $vehicle['unit_time'];

// ─────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Edit vehicle attributes ───────────────────────────────────────
    if ($action === 'edit_vehicle') {
        $nickname        = trim($_POST['nickname']         ?? '');
        $license_plate   = trim($_POST['license_plate']    ?? '');
        $vin             = trim($_POST['vin']              ?? '');
        $start_date      = trim($_POST['start_date']       ?? '');
        $effective_meter = trim($_POST['effective_meter']  ?? '');
        $effective_date  = trim($_POST['effective_date']   ?? '');
        $notes           = trim($_POST['notes']            ?? '');

        // Nullify empty optionals
        $license_plate   = $license_plate   !== '' ? $license_plate   : null;
        $vin             = $vin             !== '' ? $vin             : null;
        $start_date      = $start_date      !== '' ? $start_date      : null;
        $effective_meter = $effective_meter !== '' ? (float)$effective_meter : 0;
        $effective_date  = $effective_date  !== '' ? $effective_date  : null;
        $notes           = $notes           !== '' ? $notes           : null;

        if (empty($nickname)) {
            $error = 'Vehicle name / nickname is required.';
        } else {
            try {
                $upd = $db->prepare(
                    "UPDATE vehicles
                     SET    nickname        = :nickname,
                            license_plate   = :plate,
                            vin             = :vin,
                            start_date      = :start_date,
                            effective_meter = :eff_meter,
                            effective_date  = :eff_date,
                            notes           = :notes
                     WHERE  vehicle_id = :vid AND user_id = :uid"
                );
                $upd->bindParam(':nickname',  $nickname);
                $upd->bindParam(':plate',     $license_plate);
                $upd->bindParam(':vin',       $vin);
                $upd->bindParam(':start_date',$start_date);
                $upd->bindParam(':eff_meter', $effective_meter);
                $upd->bindParam(':eff_date',  $effective_date);
                $upd->bindParam(':notes',     $notes);
                $upd->bindParam(':vid',       $vehicleId, PDO::PARAM_INT);
                $upd->bindParam(':uid',       $userId,    PDO::PARAM_INT);
                $upd->execute();

                $success = 'Vehicle details updated successfully.';

                // Reload vehicle so the page reflects the saved values
                $vStmt->execute();
                $vehicle = $vStmt->fetch();

            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }

    // ── Add note ─────────────────────────────────────────────────────
    } elseif ($action === 'add_note') {
        $note_date  = trim($_POST['note_date']  ?? '');
        $note_meter = trim($_POST['note_meter'] ?? '');
        $note       = trim($_POST['note']       ?? '');

        if (empty($note)) {
            $error = 'Note text is required.';
        } elseif (empty($note_date)) {
            $error = 'Note date is required.';
        } elseif (empty($note_meter)) {
            $error = 'Note meter reading is required.';
        } else {
            $note_meter = (float)$note_meter;

            try {
                $ins = $db->prepare(
                    "INSERT INTO vehicle_notes (vehicle_id, note_date, note_meter, note)
                     VALUES (:vid, :note_date, :note_meter, :note)"
                );
                $ins->bindParam(':vid',        $vehicleId, PDO::PARAM_INT);
                $ins->bindParam(':note_date',  $note_date);
                $ins->bindParam(':note_meter', $note_meter);
                $ins->bindParam(':note',       $note);
                $ins->execute();

                $success = 'Note added successfully.';

            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }

    // ── Delete note ──────────────────────────────────────────────────
    } elseif ($action === 'delete_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);

        try {
            // Sub-select ensures the note belongs to a vehicle owned by this user
            $del = $db->prepare(
                "DELETE FROM vehicle_notes
                 WHERE  note_id   = :nid
                 AND    vehicle_id IN (
                     SELECT vehicle_id FROM vehicles
                     WHERE  vehicle_id = :vid AND user_id = :uid
                 )"
            );
            $del->bindParam(':nid', $noteId,   PDO::PARAM_INT);
            $del->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
            $del->bindParam(':uid', $userId,    PDO::PARAM_INT);
            $del->execute();

            $success = 'Note deleted.';

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// Load notes (most recent first)
// ─────────────────────────────────────────────────────────────────────
try {
    $nStmt = $db->prepare(
        "SELECT note_id, note_date, note_meter, note, created_at
         FROM   vehicle_notes
         WHERE  vehicle_id = :vid
         ORDER  BY note_date DESC, created_at DESC"
    );
    $nStmt->bindParam(':vid', $vehicleId, PDO::PARAM_INT);
    $nStmt->execute();
    $notes = $nStmt->fetchAll();
} catch (PDOException $e) {
    $notes = [];
    $error = 'Could not load notes: ' . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────────────
// Vehicle-type icon
// ─────────────────────────────────────────────────────────────────────
$typeIcon = $typeIcons[$vehicle['vehicle_type']] ?? 'gear';

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
.section-title i { color: #667eea; }

/* Read-only model badge row */
.model-badge-row .badge { font-size: .85rem; padding: .45em .75em; }

/* Notes table */
.notes-table td:first-child { white-space: nowrap; }

/* Nickname field */
#nickname { background-color: #f0f2ff; }
#nickname:focus { background-color: #e8ebff; border-color: #667eea; }
';

include 'includes/header.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">My Vehicles</a></li>
        <li class="breadcrumb-item active">
            <?= h($vehicle['nickname']) ?>
        </li>
    </ol>
</nav>

<?php include __DIR__ . '/includes/_alerts.inc.php' ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-9 col-xl-8">

        <form method="POST" id="editVehicleForm" novalidate>
            <input type="hidden" name="action" value="edit_vehicle">

            <!-- ════════════════════════════════════════
                 SECTION 1 – Identity
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-tag-fill"></i>&nbsp;Vehicle Identity
                </h5>

                <div class="mb-3">
                    <label for="nickname" class="form-label">
                        Name / Nickname <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg fw-semibold"
                           id="nickname" name="nickname"
                           required maxlength="100"
                           style="font-size:1.35rem; letter-spacing:.01em; background-color:#f0f2ff;"
                           value="<?= h($vehicle['nickname']) ?>"> 
                </div>

                <!-- Model (read-only) -->
                <div class="mb-3">
                    <label class="form-label">
                        Vehicle Model
                    </label>
                    <div class="p-2 rounded" style="background-color: #f8f9fa; border: 1px solid #dee2e6;">
                        <div class="mb-2">
                            <strong><?= h($vehicle['manufacturer'] . ' ' . $vehicle['model_name']) ?></strong>
                        </div>
                        <div>
                            <span class="badge bg-dark me-1">
                                <i class="fa-solid fa-<?= $typeIcon ?> me-1"></i>
                                <?= h(ucfirst($vehicle['vehicle_type'])) ?> 
                            </span>
                            <span class="badge bg-primary me-1">
                                <i class="bi bi-speedometer2 me-1"></i>
                                <?= h($unitMeter) ?> 
                            </span>
                            <span class="badge bg-info me-1">
                                <i class="bi bi-clock me-1"></i>
                                <?= h($unitTime) ?> 
                            </span>
                            <?php if ($vehicle['year_range']): ?>
                                <span class="badge bg-secondary">
                                    <i class="bi bi-calendar-range me-1"></i>
                                    <?= h($vehicle['year_range']) ?> 
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="license_plate" class="form-label">License Plate</label>
                        <input type="text" class="form-control auto-upper"
                               id="license_plate" name="license_plate" maxlength="20"
                               placeholder="e.g. AB 123 CD"
                               value="<?= h($vehicle['license_plate'] ?? '') ?>">
                    </div>
                    <div class="col-sm-6">
                        <label for="vin" class="form-label">
                            VIN <small class="text-muted fw-normal">— Vehicle Identification Number</small>
                        </label>
                        <input type="text" class="form-control auto-upper"
                               id="vin" name="vin" maxlength="50"
                               placeholder="Chassis code"
                               value="<?= h($vehicle['vin'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <a href="maintenance-status.php?vehicle_id=<?php echo $vehicleId; ?>" 
                       class="btn btn-primary">
                        <i class="bi bi-clipboard-check me-2"></i>View Maintenance Status
                    </a>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION 2 – Dates & Meter
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-speedometer2"></i>&nbsp;Dates &amp; Meter Reading
                </h5>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date"
                               value="<?= h($vehicle['start_date'] ?? '') ?>">
                        <div class="form-text">Purchase, lease or registration date.</div>
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
                                   min="0" step="0.01"
                                   value="<?= h($vehicle['effective_meter'] ?? '0') ?>">
                            <span class="input-group-text">
                                <?= h($unitMeter) ?> 
                            </span>
                        </div>
                        <div class="form-text">Current odometer / hour-meter reading.</div>
                    </div>
                    <div class="col-sm-6">
                        <label for="effective_date" class="form-label">Effective Date</label>
                        <input type="date" class="form-control" id="effective_date" name="effective_date"
                               value="<?= h($vehicle['effective_date'] ?? date('Y-m-d')) ?>">
                        <div class="form-text">Reference date for maintenance calculations.</div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════
                 SECTION 3 – Vehicle Notes (free text)
            ════════════════════════════════════════ -->
            <div class="form-card mb-4">
                <h5 class="section-title">
                    <i class="bi bi-sticky-fill"></i>&nbsp;Vehicle Notes
                </h5>
                <textarea class="form-control" id="v_notes" name="notes" rows="3"
                          placeholder="General notes about this vehicle…"
                    ><?= h($vehicle['notes'] ?? '') ?></textarea>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-2 justify-content-end mb-5">
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Save Changes
                </button>
            </div>

        </form>

        <!-- ════════════════════════════════════════
             SECTION 4 – Log Notes
        ════════════════════════════════════════ -->
        <div class="form-card mb-4">
            <h5 class="section-title">
                <i class="bi bi-journal-text"></i>&nbsp;Log Notes
                <span class="badge bg-secondary ms-auto">
                    <?= count($notes) ?> entr<?= count($notes) === 1 ? 'y' : 'ies' ?> 
                </span>
            </h5>

            <!-- Add-note form -->
            <form method="POST" class="mb-4" id="addNoteForm" novalidate>
                <input type="hidden" name="action" value="add_note">

                <div class="row g-3 align-items-end">
                    <div class="col-sm-4 col-lg-3">
                        <label for="note_date" class="form-label">
                            Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="note_date" name="note_date"
                               required
                               value="<?= h($vehicle['effective_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-sm-4 col-lg-3">
                        <label for="note_meter" class="form-label">
                            Meter <span class="text-danger">*</span>
                            <small class="text-muted fw-normal">
                                (<?= h($unitMeter) ?>)
                            </small>
                        </label>
                        <input type="number" class="form-control" id="note_meter" name="note_meter"
                               required min="0" step="0.01"
                               value="<?= h($vehicle['effective_meter'] ?? '0') ?>">
                    </div>
                    <div class="col-12 col-lg-6">
                        <label for="note" class="form-label">
                            Note <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="note" name="note"
                               required placeholder="e.g. Replaced faulty fuel pump…">
                    </div>
                    <div class="col-12 col-sm-auto">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle me-2"></i>Add Note
                        </button>
                    </div>
                </div>
            </form>

            <!-- Notes table -->
            <?php if (empty($notes)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-journal-x" style="font-size:2.5rem;"></i>
                    <p class="mt-2 mb-0">No log notes yet.</p>
                </div>
            <?php else: ?>
                <!-- Search input -->
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               id="notesSearchInput"
                               placeholder="Search notes..."
                               autocomplete="off">
                        <button class="btn btn-outline-secondary" 
                                type="button" 
                                id="clearNotesSearch"
                                style="display: none;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="notesSearchCount"></small>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle notes-table mb-0" id="noteTable">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>
                                    Meter
                                    <small class="text-muted fw-normal">
                                        (<?= h($unitMeter) ?>)
                                    </small>
                                </th>
                                <th>Note</th>
                                <th class="text-end pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notes as $n): ?>
                                <tr class="note-row" data-search-text="<?= h(strtolower($n['note'])) ?>">
                                    <td style="white-space: nowrap;">
                                        <?php echo date('d M Y', strtotime($n['note_date'])); ?> 
                                    </td>
                                    <td>
                                        <?php echo number_format((float)$n['note_meter'], 0); ?> 
                                    </td>
                                    <td>
                                        <?= h($n['note']) ?> 
                                    </td>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            title="Delete note"
                                            data-note_id="<?= h($n['note_id']) ?>"
                                            data-vehicle_note="<?= h($n['note']) ?>"
                                            data-note_date="<?= h(date('d M Y', strtotime($n['note_date']))) ?>"
                                            data-note_meter="<?= h($n['note_meter']) ?>"
                                            onclick="openDeleteModal(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted small mt-2" id="noNotesResults" style="display: none;">
                    <i class="bi bi-info-circle me-1"></i>
                    No notes match your search.
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.col -->
</div><!-- /.row -->

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Vehicle Note
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to delete this vehicle note?</p>

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <div class="mb-2">
                            <small class="text-muted d-block">Note</small>
                            <strong id="deleteVehicleNote"></strong>
                        </div>
                        <div>
                            <small class="text-muted d-block">Date</small>
                            <strong id="deleteNoteDate"></strong>
                        </div>
                        <div>
                            <small class="text-muted d-block">Meter</small>
                            <strong id="deleteNoteMeter"></strong>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    This will remove the note. This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteNoteForm">
                    <input type="hidden" name="action" value="delete_note">
                    <input type="hidden" name="note_id" id="deleteNoteId">
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
    document.getElementById('deleteVehicleNote').textContent = button.dataset.vehicle_note;
    document.getElementById('deleteNoteDate').textContent = button.dataset.note_date;
    document.getElementById('deleteNoteMeter').textContent = button.dataset.note_meter;
    document.getElementById('deleteNoteId').value = button.dataset.note_id;

    const modal = new bootstrap.Modal(document.getElementById('deleteNoteModal'));
    modal.show();
}

document.querySelectorAll('.auto-upper').forEach(function (el) {
    el.addEventListener('input', function () {
        const pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
});

// Notes search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('notesSearchInput');
    if (!searchInput) return; // Exit if no notes to search

    const clearBtn = document.getElementById('clearNotesSearch');
    const rows = document.querySelectorAll('.note-row');
    const noResults = document.getElementById('noNotesResults');
    const searchCount = document.getElementById('notesSearchCount');
    const totalNotes = rows.length;

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

        // Update count
        if (searchTerm !== '') {
            searchCount.textContent = 'Showing ' + visibleCount + ' of ' + totalNotes + ' notes';
        } else {
            searchCount.textContent = '';
        }

        // Show/hide no results message
        if (visibleCount === 0 && searchTerm !== '') {
            noResults.style.display = 'block';
        } else {
            noResults.style.display = 'none';
        }

        // Show/hide clear button
        if (searchTerm !== '') {
            clearBtn.style.display = 'block';
        } else {
            clearBtn.style.display = 'none';
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

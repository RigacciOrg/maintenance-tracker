<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$page_title   = 'User Management - Maintenance Tracker';
$page_heading = isAdministrator() ? 'User Management' : 'My Account';
$current_page = 'user-management';

requireLogin();
$currentUserId = getCurrentUserId();
$isAdmin       = isAdministrator();

$database = new Database();
$db       = $database->getConnection();

$success = '';
$error   = '';

// ──────────────────────────────────────────────
// POST handler
// ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add new user (admin only) ──────────────
    if ($action === 'add_user' && $isAdmin) {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $admin     = isset($_POST['administrator']) ? true : false;

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match';
        } else {
            try {
                $query = "INSERT INTO users (username, email, password_hash, administrator)
                          VALUES (:username, :email, :password_hash, :administrator)";
                $stmt  = $db->prepare($query);
                $stmt->bindParam(':username',      $username);
                $stmt->bindParam(':email',         $email);
                $stmt->bindValue(':password_hash', password_hash($password, PASSWORD_DEFAULT));
                $stmt->bindParam(':administrator', $admin, PDO::PARAM_BOOL);
                $stmt->execute();
                $success = "User '{$username}' created successfully.";
            } catch (PDOException $e) {
                $sqlState = $e->errorInfo[0];
                $error = (in_array($sqlState, ['23000', '23505']))
                    ? "Username '{$username}' already exists"
                    : 'Database error: ' . $e->getMessage();
            }
        }

    // ── Change own password (any logged-in user) ──
    } elseif ($action === 'change_own_password') {
        $email     = trim($_POST['email'] ?? '');
        $current   = $_POST['current_password'] ?? '';
        $password  = $_POST['new_password'] ?? '';
        $password2 = $_POST['new_password2'] ?? '';

        $changingPassword = $current !== '' || $password !== '' || $password2 !== '';

        if ($changingPassword) {
            if (empty($current) || empty($password)) {
                $error = 'To change your password, all three password fields are required';
            } elseif (strlen($password) < 8) {
                $error = 'New password must be at least 8 characters';
            } elseif ($password !== $password2) {
                $error = 'New passwords do not match';
            } else {
                // Verify current password
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :id");
                $stmt->bindParam(':id', $currentUserId, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch();

                if (!$row || !password_verify($current, $row['password_hash'])) {
                    $error = 'Current password is incorrect';
                } else {
                    $stmt = $db->prepare(
                        "UPDATE users SET email = :email, password_hash = :hash WHERE user_id = :id"
                    );
                    $stmt->bindParam(':email', $email);
                    $stmt->bindValue(':hash', password_hash($password, PASSWORD_DEFAULT));
                    $stmt->bindParam(':id',   $currentUserId, PDO::PARAM_INT);
                    $stmt->execute();
                    $success = 'Your account has been updated successfully.';
                }
            }
        } else {
            // Email-only update — no password change requested
            $stmt = $db->prepare("UPDATE users SET email = :email WHERE user_id = :id");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':id',   $currentUserId, PDO::PARAM_INT);
            $stmt->execute();
            $success = 'Your email has been updated successfully.';
        }

    // ── Admin: reset any user's password ─────────
    } elseif ($action === 'reset_password' && $isAdmin) {
        $targetId  = (int)($_POST['user_id'] ?? 0);
        $password  = $_POST['new_password'] ?? '';
        $password2 = $_POST['new_password2'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match';
        } else {
            $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
            $stmt->bindValue(':hash', password_hash($password, PASSWORD_DEFAULT));
            $stmt->bindParam(':id',   $targetId, PDO::PARAM_INT);
            $stmt->execute();
            $success = 'Password updated successfully.';
        }

    // ── Admin: toggle administrator flag ─────────
    } elseif ($action === 'toggle_admin' && $isAdmin) {
        $targetId = (int)($_POST['user_id'] ?? 0);

        // Prevent removing own admin flag
        if ($targetId === $currentUserId) {
            $error = 'You cannot change your own administrator status';
        } else {
            $stmt = $db->prepare(
                "UPDATE users SET administrator = NOT administrator WHERE user_id = :id"
            );
            $stmt->bindParam(':id', $targetId, PDO::PARAM_INT);
            $stmt->execute();
            $success = 'Administrator status updated.';
        }

    // ── Admin: delete user ────────────────────────
    } elseif ($action === 'delete_user' && $isAdmin) {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId === $currentUserId) {
            $error = 'You cannot delete your own account';
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM users WHERE user_id = :id");
                $stmt->bindParam(':id', $targetId, PDO::PARAM_INT);
                $stmt->execute();
                $success = 'User deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ──────────────────────────────────────────────
// Fetch data
// ──────────────────────────────────────────────
$users = [];
if ($isAdmin) {
    try {
        $stmt  = $db->query(
            "SELECT user_id, username, email, administrator, created_at,
                    (SELECT COUNT(*) FROM vehicles v WHERE v.user_id = users.user_id) AS vehicle_count
             FROM users
             ORDER BY username"
        );
        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Error fetching users: ' . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<?php include __DIR__ . '/includes/_alerts.inc.php'; ?>

<?php if ($isAdmin): ?>
<!-- ══════════════════════════════════════════════
     ADMIN VIEW
══════════════════════════════════════════════ -->

    <!-- Action bar -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-people-fill me-2"></i>
            Registered Users <span class="badge bg-secondary"><?php echo count($users); ?></span>
        </h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill me-2"></i>Add User
        </button>
    </div>

    <!-- Users table -->
    <div class="form-card p-0 overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Username</th>
                        <th class="d-none d-md-table-cell">Email</th>
                        <th class="text-center">Admin</th>
                        <th class="text-center d-none d-sm-table-cell">Vehicles</th>
                        <th class="d-none d-lg-table-cell">Registered</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr <?php echo $u['user_id'] == $currentUserId ? 'class="table-primary"' : ''; ?>>
                            <td class="ps-3">
                                <i class="bi bi-person-circle me-2 text-muted"></i>
                                <strong><?= h($u['username']) ?></strong>
                                <?php if ($u['user_id'] == $currentUserId): ?>
                                    <span class="badge bg-primary ms-1">You</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell text-muted">
                                <?= h($u['email'] ?: '—') ?> 
                            </td>
                            <td class="text-center">
                                <?php if ($u['administrator']): ?>
                                    <i class="bi bi-shield-fill-check text-success fs-5"
                                       title="Administrator"></i>
                                <?php else: ?>
                                    <i class="bi bi-shield text-muted fs-5"
                                       title="Regular user"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center d-none d-sm-table-cell">
                                <span class="badge bg-secondary">
                                    <?= $u['vehicle_count'] ?> 
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell text-muted small">
                                <?= h(date('d M Y', strtotime($u['created_at']))) ?> 
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-flex justify-content-end gap-1">
                                    <!-- Change password -->
                                    <button class="btn btn-sm btn-outline-primary"
                                            title="Change password"
                                            data-user_id="<?= h($u['user_id']) ?>"
                                            data-username="<?= h($u['username']) ?>"
                                            onclick="openResetModal(this)">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <?php if ($u['user_id'] != $currentUserId): ?>
                                        <!-- Toggle admin -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_admin">
                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning"
                                                    title="<?= $u['administrator'] ? 'Remove admin' : 'Make admin' ?>">
                                                <i class="bi bi-shield<?= $u['administrator'] ? '-fill-x' : '-fill-check' ?>"></i>
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <button class="btn btn-sm btn-outline-danger"
                                                title="Delete user"
                                                data-user_id="<?= h($u['user_id']) ?>"
                                                data-username="<?= h($u['username']) ?>"
                                                onclick="openDeleteModal(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Own password section -->
    <h5 class="mb-3"><i class="bi bi-person-fill me-2"></i>Your Account</h5>
    <?php include 'includes/_change_password_form.inc.php'; ?>


    <!-- ── Add User Modal ──────────────────────── -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill me-2"></i>Add New User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">

                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-control" name="username" required
                                   placeholder="e.g. jsmith">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email"
                                   placeholder="user@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password * <small class="text-muted">(min. 8 chars)</small></label>
                            <input type="password" class="form-control" name="password"
                                   id="newUserPwd" required minlength="8">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" class="form-control" name="password2" required>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="adminFlag"
                                   name="administrator" value="1">
                            <label class="form-check-label" for="adminFlag">
                                <i class="bi bi-shield-fill-check me-1 text-success"></i>
                                Grant administrator privileges
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus-fill me-2"></i>Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Reset Password Modal (admin) ───────── -->
    <div class="modal fade" id="resetPwdModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2"></i>Reset Password for
                        <span id="resetPwdUsername"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="resetPwdUserId">

                        <div class="mb-3">
                            <label class="form-label">New Password * <small class="text-muted">(min. 8 chars)</small></label>
                            <input type="password" class="form-control" name="new_password" required minlength="8">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password *</label>
                            <input type="password" class="form-control" name="new_password2" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-key me-2"></i>Set Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Delete User Modal ───────────────────── -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-x-fill me-2"></i>Delete User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete user
                        <strong id="deleteUsername"></strong>?
                    </p>
                    <p class="text-danger mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        All vehicles and maintenance data for this user will also be deleted.
                    </p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="delete-submit">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" id="deleteUserId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>Delete User
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>


<?php else: ?>
<!-- ══════════════════════════════════════════════
     NON-ADMIN VIEW  – own password only
══════════════════════════════════════════════ -->

    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="form-card mb-4">
                <div class="d-flex align-items-center mb-4">
                    <i class="bi bi-person-circle me-3" style="font-size:2.5rem;color:#667eea;"></i>
                    <div>
                        <h5 class="mb-0"><?= h(getCurrentUsername()) ?></h5>
                        <small class="text-muted">Regular user</small>
                    </div>
                </div>
                <hr>
                <h6 class="mb-3"><i class="bi bi-person-fill me-2"></i>My Account</h6>
                <?php include 'includes/_change_password_form.inc.php'; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php
$extra_js = <<<'JS'
<script>
function openResetModal(button) {
    document.getElementById('resetPwdUserId').value = button.dataset.user_id;
    document.getElementById('resetPwdUsername').textContent = button.dataset.username;
    // Clear previous values
    document.querySelectorAll('#resetPwdModal input[type=password]')
             .forEach(el => el.value = '');
    new bootstrap.Modal(document.getElementById('resetPwdModal')).show();
}

function openDeleteModal(button) {
    document.getElementById('deleteUserId').value = button.dataset.user_id;
    document.getElementById('deleteUsername').textContent = button.dataset.username;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}
</script>
JS;

include 'includes/footer.php';
?>

<?php
/**
 * Reusable fragment: change own account details (email + password).
 * Included inside user-management.php for both admin and non-admin views.
 * Expects $db and $currentUserId to be in scope (set by the parent page).
 */

// Fetch current email to pre-fill the field
$_selfStmt = $db->prepare("SELECT email FROM users WHERE user_id = :id");
$_selfStmt->bindParam(':id', $currentUserId, PDO::PARAM_INT);
$_selfStmt->execute();
$_selfRow   = $_selfStmt->fetch();
$_currEmail = $_selfRow['email'] ?? '';
?>
<form method="POST">
    <input type="hidden" name="action" value="change_own_password">

    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" name="email"
               value="<?php echo htmlspecialchars($_currEmail); ?>"
               placeholder="user@example.com"
               autocomplete="email">
        <div class="form-text">Leave unchanged to keep your current email.</div>
    </div>

    <hr class="my-3">

    <p class="text-muted small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Fill in the password fields only if you want to change your password.
    </p>

    <div class="mb-3">
        <label class="form-label">Current Password</label>
        <input type="password" class="form-control" name="current_password"
               autocomplete="current-password">
    </div>
    <div class="mb-3">
        <label class="form-label">New Password <small class="text-muted">(min. 8 chars)</small></label>
        <input type="password" class="form-control" name="new_password"
               minlength="8" autocomplete="new-password"
               id="ownNewPwd">
    </div>
    <div class="mb-3">
        <label class="form-label">Confirm New Password</label>
        <input type="password" class="form-control" name="new_password2"
               autocomplete="new-password"
               oninput="this.setCustomValidity(
                   this.value !== document.getElementById('ownNewPwd').value
                   ? 'Passwords do not match' : '')">
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-2"></i>Save Changes
    </button>
</form>

<?php
/**
 * Authentication and session management functions
 */

// Load session configuration
require_once __DIR__ . '/../config/session.php';

// Configure session settings before starting session
if (session_status() === PHP_SESSION_NONE) {
    // Set unique session name to prevent session poisoning
    session_name(APP_SESSION_NAME);

    // Configure session cookie parameters for security
    session_set_cookie_params([
        'lifetime' => SESSION_COOKIE_LIFETIME,
        'path' => SESSION_COOKIE_PATH,
        'domain' => SESSION_COOKIE_DOMAIN,
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => SESSION_COOKIE_HTTPONLY,
        'samesite' => SESSION_COOKIE_SAMESITE
    ]);

    // Set additional session security options
    ini_set('session.use_strict_mode', SESSION_USE_STRICT_MODE ? '1' : '0');
    ini_set('session.use_cookies', SESSION_USE_COOKIES ? '1' : '0');
    ini_set('session.use_only_cookies', SESSION_USE_ONLY_COOKIES ? '1' : '0');

    // Start the session
    session_start();

    // Regenerate session ID periodically to prevent session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require login - redirect to login page if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current username
 * @return string|null
 */
function getCurrentUsername() {
    return $_SESSION['username'] ?? null;
}

/**
 * Login user
 * @param int $userId
 * @param string $username
 * @param bool $administrator
 */
function login($userId, $username, $administrator = false) {
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['administrator'] = (bool)$administrator;
}

/**
 * Check if current user is an administrator
 * @return bool
 */
function isAdministrator() {
    return isset($_SESSION['administrator']) && $_SESSION['administrator'] === true;
}

/**
 * Require administrator - redirect to index if not admin
 */
function requireAdministrator() {
    requireLogin();
    if (!isAdministrator()) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Logout user
 */
function logout() {
    session_unset();
    session_destroy();
}
?>

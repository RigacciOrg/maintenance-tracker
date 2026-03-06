<?php
/**
 * Session Configuration
 * 
 * This file defines session settings to prevent session poisoning
 * when multiple applications run on the same server.
 */

// Unique session name for this application
// Change this to a unique value for your application
define('APP_SESSION_NAME', 'VEHICLE_MAINT_SESS');

// Session cookie parameters (optional but recommended)
define('SESSION_COOKIE_LIFETIME', 0);        // 0 = until browser closes
define('SESSION_COOKIE_PATH', '/');          // Available throughout the domain
define('SESSION_COOKIE_DOMAIN', '');         // Current domain
define('SESSION_COOKIE_SECURE', false);      // Set to true if using HTTPS
define('SESSION_COOKIE_HTTPONLY', true);     // Prevent JavaScript access to session cookie
define('SESSION_COOKIE_SAMESITE', 'Lax');    // CSRF protection (Lax, Strict, or None)

// Session settings
define('SESSION_USE_STRICT_MODE', true);     // Reject uninitialized session IDs
define('SESSION_USE_COOKIES', true);         // Use cookies to store session ID
define('SESSION_USE_ONLY_COOKIES', true);    // Don't allow session ID in URL
define('SESSION_COOKIE_LIFETIME_VALUE', SESSION_COOKIE_LIFETIME);

?>

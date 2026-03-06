<?php
require_once 'includes/auth.php';

// Logout the user
logout();

// Redirect to login page
header('Location: login.php');
exit();
?>

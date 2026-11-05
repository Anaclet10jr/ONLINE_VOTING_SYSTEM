<?php
// logout.php
require_once __DIR__ . '/includes/auth.php';
logout_user();
set_flash('success', 'You have been logged out.');
header('Location: /VOTING_SYSTEM/login.php');
exit;

<?php
/**
 * includes/header.php
 * Reusable HTML head + top navigation bar.
 * Expects: $pageTitle (string), optional $extraCss (array of css filenames).
 */
if (!isset($pageTitle)) $pageTitle = 'SOVMS';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
start_secure_session();
$flash = get_flash();
$cu = current_user();
$csrf = csrf_token();
$siteNameSetting = db_fetch_one($GLOBALS['mysqli'], "SELECT `value` FROM system_settings WHERE `key`='site_name'", "", []);
$siteName = $siteNameSetting && trim((string)$siteNameSetting['value']) !== ''
    ? trim((string)$siteNameSetting['value'])
    : 'SOVMS';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e($pageTitle); ?> — <?php echo e($siteName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/VOTING_SYSTEM/assets/css/main.css">
    <?php if (!empty($extraCss)): foreach ($extraCss as $c): ?>
        <link rel="stylesheet" href="/VOTING_SYSTEM/assets/css/<?php echo e($c); ?>">
    <?php endforeach; endif; ?>
</head>
<body>
<header class="topbar">
    <div class="topbar__brand">
        <a href="/VOTING_SYSTEM/index.php">🗳️ <?php echo e($siteName); ?></a>
    </div>
    <nav class="topbar__nav">
        <?php if ($cu): ?>
            <span class="topbar__user"><?php echo e($cu['full_name']); ?> (<?php echo e($cu['role_name']); ?>)</span>
            <a href="/VOTING_SYSTEM/logout.php" class="btn btn--sm">Logout</a>
        <?php else: ?>
            <a href="/VOTING_SYSTEM/login.php" class="btn btn--sm btn--primary">Login</a>
            <a href="/VOTING_SYSTEM/register.php" class="btn btn--sm">Register</a>
        <?php endif; ?>
        <button id="themeToggle" class="btn btn--sm btn--ghost" type="button" aria-label="Toggle theme">🌙</button>
    </nav>
</header>

<?php if ($flash): ?>
<div class="flash flash--<?php echo e($flash['type']); ?>" role="alert">
    <?php echo e($flash['msg']); ?>
</div>
<?php endif; ?>

<main class="main">

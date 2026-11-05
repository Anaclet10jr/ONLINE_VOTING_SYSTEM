<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$active = 'settings';
global $mysqli;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/admin/settings.php');
        exit;
    }

    $siteName = clean_string($_POST['site_name'] ?? '', 120);
    $autoTransition = $_POST['election_auto_transition'] ?? '';
    $timezone = trim($_POST['app_timezone'] ?? '');
    $validTimezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
    if ($siteName === '' || !in_array($autoTransition, ['0', '1'], true) ||
        !in_array($timezone, $validTimezones, true)) {
        set_flash('danger', 'Enter a site name, valid timezone, and election schedule setting.');
        header('Location: /VOTING_SYSTEM/admin/settings.php');
        exit;
    }

    $adminId = (int)current_user()['id'];
    $mysqli->begin_transaction();
    try {
        db_execute(
            $mysqli,
            "INSERT INTO system_settings (`key`, `value`, type, updated_by)
             VALUES ('site_name', ?, 'string', ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_by=VALUES(updated_by)",
            "si",
            [$siteName, $adminId]
        );
        db_execute(
            $mysqli,
            "INSERT INTO system_settings (`key`, `value`, type, updated_by)
             VALUES ('election_auto_transition', ?, 'bool', ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_by=VALUES(updated_by)",
            "si",
            [$autoTransition, $adminId]
        );
        db_execute(
            $mysqli,
            "INSERT INTO system_settings (`key`, `value`, type, updated_by)
             VALUES ('app_timezone', ?, 'string', ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_by=VALUES(updated_by)",
            "si",
            [$timezone, $adminId]
        );
        $mysqli->commit();
    } catch (Throwable $t) {
        $mysqli->rollback();
        error_log('Admin settings update failed: ' . $t->getMessage());
        set_flash('danger', 'Could not save settings. Please try again.');
        header('Location: /VOTING_SYSTEM/admin/settings.php');
        exit;
    }

    audit_log($adminId, 'settings_update', 'system', null, 'Updated site name, timezone, and automatic election transitions.');
    set_flash('success', 'Settings saved.');
    header('Location: /VOTING_SYSTEM/admin/settings.php');
    exit;
}

$settings = db_fetch_all($mysqli, "SELECT `key`, `value` FROM system_settings WHERE `key` IN ('site_name', 'election_auto_transition', 'app_timezone')");
$settingValues = [];
foreach ($settings as $setting) {
    $settingValues[$setting['key']] = $setting['value'];
}

$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>System settings</h2>
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="site_name">Site name</label>
                    <input class="form-control" id="site_name" name="site_name" maxlength="120" value="<?php echo e($settingValues['site_name'] ?? 'Secure Online Voting Management System'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="app_timezone">Timezone</label>
                    <input class="form-control" id="app_timezone" name="app_timezone" value="<?php echo e($settingValues['app_timezone'] ?? 'Asia/Manila'); ?>" placeholder="Asia/Manila" required>
                    <small>Use an IANA timezone identifier such as Asia/Manila, Africa/Johannesburg, Europe/Berlin, or UTC. The browser does not send timezone information with date/time fields, so this setting controls how election times are entered and displayed.</small>
                </div>
                <div class="form-group">
                    <label for="election_auto_transition">Automatic election schedule</label>
                    <select class="form-control" id="election_auto_transition" name="election_auto_transition">
                        <option value="1" <?php echo ($settingValues['election_auto_transition'] ?? '1') === '1' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="0" <?php echo ($settingValues['election_auto_transition'] ?? '1') === '0' ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                    <small>When enabled, due elections are activated or closed when an admin, manager, or voter opens a protected page. No separate scheduler service is required.</small>
                </div>
                <button class="btn btn--primary" type="submit">Save settings</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

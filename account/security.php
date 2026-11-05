<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'manager']);

$active = 'security';
$user = current_user();
$userId = (int)$user['id'];
global $mysqli;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $password = $_POST['password'] ?? '';
    $codeInput = $_POST['code'] ?? '';
    if (!is_string($codeInput)) {
        set_flash('danger', 'Enter a valid authenticator code.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }
    $code = trim($codeInput);
    if (!is_string($password) || !is_string($code)) {
        set_flash('danger', 'Invalid security settings data.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }
    $account = db_fetch_one(
        $mysqli,
        "SELECT id, password_hash, mfa_enabled, mfa_secret FROM users WHERE id=? AND status='active'",
        "i",
        [$userId]
    );
    if (!$account || !password_verify($password, $account['password_hash'])) {
        set_flash('danger', 'Enter your current password to change MFA settings.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }

    if ($action === 'generate_secret') {
        if ((int)$account['mfa_enabled'] === 1 &&
            (!$account['mfa_secret'] || !verify_totp_code($account['mfa_secret'], $code))) {
            set_flash('danger', 'Enter a valid current authenticator code before replacing MFA.');
            header('Location: /VOTING_SYSTEM/account/security.php');
            exit;
        }
        $secret = generate_totp_secret();
        db_execute(
            $mysqli,
            "UPDATE users SET mfa_enabled=0, mfa_secret=? WHERE id=?",
            "si",
            [$secret, $userId]
        );
        audit_log($userId, 'mfa_secret_generated', 'user', $userId, 'Generated MFA enrollment secret');
        set_flash('success', 'New authenticator key generated. Add it to your authenticator app, then verify a code to enable MFA.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }

    if ($action === 'enable') {
        if ((int)$account['mfa_enabled'] === 1 || !$account['mfa_secret'] ||
            !verify_totp_code($account['mfa_secret'], $code)) {
            set_flash('danger', 'The authenticator code is invalid or the setup key is no longer available.');
            header('Location: /VOTING_SYSTEM/account/security.php');
            exit;
        }
        $enabled = db_execute(
            $mysqli,
            "UPDATE users SET mfa_enabled=1
             WHERE id=? AND mfa_enabled=0 AND mfa_secret=?",
            "is",
            [$userId, $account['mfa_secret']]
        );
        if ($enabled !== 1) {
            set_flash('warning', 'MFA settings changed before setup completed. Refresh and try again.');
            header('Location: /VOTING_SYSTEM/account/security.php');
            exit;
        }
        audit_log($userId, 'mfa_enabled', 'user', $userId, 'Enabled time-based one-time password MFA');
        set_flash('success', 'Multi-factor authentication is enabled.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }

    if ($action === 'disable') {
        if ((int)$account['mfa_enabled'] !== 1 || !$account['mfa_secret'] ||
            !verify_totp_code($account['mfa_secret'], $code)) {
            set_flash('danger', 'Enter a valid current authenticator code to disable MFA.');
            header('Location: /VOTING_SYSTEM/account/security.php');
            exit;
        }
        db_execute(
            $mysqli,
            "UPDATE users SET mfa_enabled=0, mfa_secret=NULL WHERE id=? AND mfa_enabled=1",
            "i",
            [$userId]
        );
        audit_log($userId, 'mfa_disabled', 'user', $userId, 'Disabled time-based one-time password MFA');
        set_flash('success', 'Multi-factor authentication is disabled.');
        header('Location: /VOTING_SYSTEM/account/security.php');
        exit;
    }
}

$account = db_fetch_one(
    $mysqli,
    "SELECT email, mfa_enabled, mfa_secret FROM users WHERE id=?",
    "i",
    [$userId]
);
$issuer = 'SOVMS';
$setupUri = '';
if ((int)$account['mfa_enabled'] !== 1 && !empty($account['mfa_secret'])) {
    $setupUri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $account['email'])
        . '?' . http_build_query([
            'secret' => $account['mfa_secret'],
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
}

$pageTitle = 'Account Security';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <section class="card">
            <h2>Account Security</h2>
            <p>MFA uses a time-based code from an authenticator app. Keep your device clock set automatically.</p>
            <?php if ((int)$account['mfa_enabled'] === 1): ?>
                <p><span class="badge badge--active">MFA enabled</span></p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="disable">
                    <div class="form-group">
                        <label for="disable_password">Current password</label>
                        <input class="form-control" type="password" id="disable_password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="disable_code">Current authenticator code</label>
                        <input class="form-control" type="text" id="disable_code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button class="btn btn--danger" type="submit">Disable MFA</button>
                </form>
                <hr>
                <h3>Replace authenticator</h3>
                <p>Replacing the key disables MFA until you verify the new key.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_secret">
                    <div class="form-group">
                        <label for="replace_password">Current password</label>
                        <input class="form-control" type="password" id="replace_password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="replace_code">Current authenticator code</label>
                        <input class="form-control" type="text" id="replace_code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button class="btn" type="submit">Replace authenticator key</button>
                </form>
            <?php elseif ($account['mfa_secret']): ?>
                <p><span class="badge badge--pending">Setup not complete</span></p>
                <p>Add this key to your authenticator app for <strong><?php echo e($account['email']); ?></strong>. The key is shown only while MFA is disabled.</p>
                <p><strong>Setup key:</strong> <code><?php echo e($account['mfa_secret']); ?></code></p>
                <p><strong>Authenticator URI:</strong><br><code><?php echo e($setupUri); ?></code></p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="enable">
                    <div class="form-group">
                        <label for="enable_password">Current password</label>
                        <input class="form-control" type="password" id="enable_password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="enable_code">6-digit authenticator code</label>
                        <input class="form-control" type="text" id="enable_code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button class="btn btn--primary" type="submit">Verify and enable MFA</button>
                </form>
            <?php else: ?>
                <p>MFA is currently disabled for your account.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_secret">
                    <div class="form-group">
                        <label for="generate_password">Current password</label>
                        <input class="form-control" type="password" id="generate_password" name="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn--primary" type="submit">Set up authenticator</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
// mfa_verify.php
require_once __DIR__ . '/includes/auth.php';
start_secure_session();
if (empty($_SESSION['pending_mfa_user_id'])) {
    header('Location: /VOTING_SYSTEM/login.php');
    exit;
}
$uid = (int)$_SESSION['pending_mfa_user_id'];
if (empty($_SESSION['pending_mfa_started_at']) ||
    time() - (int)$_SESSION['pending_mfa_started_at'] > 300) {
    unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_started_at']);
    set_flash('warning', 'MFA login expired. Please sign in again.');
    header('Location: /VOTING_SYSTEM/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    $codeInput = $_POST['code'] ?? '';
    if (!is_string($codeInput)) {
        set_flash('danger', 'Enter a valid authenticator code.');
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    $code = trim($codeInput);
    global $mysqli;
    if (too_many_attempts('', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
        unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_started_at']);
        set_flash('danger', 'Too many verification attempts. Please sign in again later.');
        header('Location: /VOTING_SYSTEM/login.php');
        exit;
    }
    $mfaUser = db_fetch_one(
        $mysqli,
        "SELECT id, email, mfa_enabled, mfa_secret FROM users WHERE id=? AND status='active'",
        "i",
        [$uid]
    );
    if (!$mfaUser || (int)$mfaUser['mfa_enabled'] !== 1 || !$mfaUser['mfa_secret'] ||
        !verify_totp_code($mfaUser['mfa_secret'], $code)) {
        if ($mfaUser) {
            record_login_attempt($mfaUser['email'], false);
        }
        set_flash('danger', 'The authenticator code is invalid. Please try again.');
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    if (complete_mfa_login($uid)) {
        $u = current_user();
        $dest = '/VOTING_SYSTEM/voter/dashboard.php';
        if ($u && $u['role_name'] === 'admin') $dest = '/VOTING_SYSTEM/admin/dashboard.php';
        elseif ($u && $u['role_name'] === 'manager') $dest = '/VOTING_SYSTEM/manager/dashboard.php';
        header('Location: ' . $dest);
        exit;
    }
    set_flash('danger', 'MFA verification failed.');
    header('Location: /VOTING_SYSTEM/mfa_verify.php');
    exit;
}
$pageTitle = 'MFA Verification';
require_once __DIR__ . '/includes/header.php';
?>
<section class="card" style="max-width:420px;margin:40px auto;">
    <h2>Multi-Factor Authentication</h2>
    <p>Enter the 6-digit code from your authenticator app.</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="code">Code</label>
            <input class="form-control" type="text" id="code" name="code" pattern="\d{6}" maxlength="6" required autofocus>
        </div>
        <button class="btn btn--primary" type="submit">Verify</button>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

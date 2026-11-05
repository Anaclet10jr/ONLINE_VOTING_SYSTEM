<?php
// mfa_verify.php
require_once __DIR__ . '/includes/header.php';
start_secure_session();
if (empty($_SESSION['pending_mfa_user_id'])) {
    header('Location: /VOTING_SYSTEM/login.php');
    exit;
}
$uid = (int)$_SESSION['pending_mfa_user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    // TOTP verification placeholder (implement with a TOTP library in Phase 6)
    $code = trim($_POST['code'] ?? '');
    if ($code === '') {
        set_flash('danger', 'Enter the MFA code.');
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    // For dev: accept any 6-digit code. REPLACE in production.
    if (!preg_match('/^\d{6}$/', $code)) {
        set_flash('danger', 'Invalid code format.');
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
    <p><small>Dev mode: any 6-digit code accepted. TOTP library to be integrated in Phase 6.</small></p>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

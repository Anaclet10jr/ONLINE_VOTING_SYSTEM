<?php
// login.php
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/login.php');
        exit;
    }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $res = login_user($email, $password);
    if (!$res['ok']) {
        set_flash('danger', $res['error']);
        header('Location: /VOTING_SYSTEM/login.php');
        exit;
    }
    if ($res['mfa_required']) {
        header('Location: /VOTING_SYSTEM/mfa_verify.php');
        exit;
    }
    $u = current_user();
    $dest = '/VOTING_SYSTEM/voter/dashboard.php';
    if ($u && $u['role_name'] === 'admin') $dest = '/VOTING_SYSTEM/admin/dashboard.php';
    elseif ($u && $u['role_name'] === 'manager') $dest = '/VOTING_SYSTEM/manager/dashboard.php';
    header('Location: ' . $dest);
    exit;
}
?>
<section class="card" style="max-width:420px;margin:40px auto;">
    <h2>Login</h2>
    <form method="post" action="/VOTING_SYSTEM/login.php" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required>
        </div>
        <button class="btn btn--primary" type="submit">Sign In</button>
        <a class="btn" href="/VOTING_SYSTEM/register.php">Register</a>
        <a class="btn" href="/VOTING_SYSTEM/verify_email.php">Verify account</a>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

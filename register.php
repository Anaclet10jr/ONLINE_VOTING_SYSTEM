<?php
// register.php
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/register.php');
        exit;
    }
    $name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $pw = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if ($pw !== $pw2) {
        set_flash('danger', 'Passwords do not match.');
        header('Location: /VOTING_SYSTEM/register.php');
        exit;
    }
    $res = register_voter_account($name, $email, $pw);
    if (!$res['ok']) {
        set_flash('danger', $res['error']);
        header('Location: /VOTING_SYSTEM/register.php');
        exit;
    }
    set_flash(
        'success',
        'Registration successful. Enter this verification token on the Verify account page to activate your account: '
        . $res['verify_token']
    );
    header('Location: /VOTING_SYSTEM/login.php');
    exit;
}
?>
<section class="card" style="max-width:480px;margin:40px auto;">
    <h2>Voter Registration</h2>
    <form method="post" action="/VOTING_SYSTEM/register.php">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="full_name">Full Name</label>
            <input class="form-control" type="text" id="full_name" name="full_name" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required>
            <small>Min 10 chars, upper, lower, digit, symbol.</small>
        </div>
        <div class="form-group">
            <label for="password2">Confirm Password</label>
            <input class="form-control" type="password" id="password2" name="password2" required>
        </div>
        <button class="btn btn--primary" type="submit">Register</button>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

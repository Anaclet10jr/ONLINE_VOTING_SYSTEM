<?php
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$dashboardUrl = '/VOTING_SYSTEM/voter/dashboard.php';
if ($user && $user['role_name'] === 'admin') {
    $dashboardUrl = '/VOTING_SYSTEM/admin/dashboard.php';
} elseif ($user && $user['role_name'] === 'manager') {
    $dashboardUrl = '/VOTING_SYSTEM/manager/dashboard.php';
}
?>
<section class="home-hero">
    <div class="home-intro">
        <p class="home-eyebrow">Secure Online Voting Management System</p>
        <h1>Every vote matters.</h1>
        <p class="home-lead">A straightforward platform for organizing college and community elections, managing candidates, and giving eligible voters a private way to participate.</p>
        <ul class="home-benefits">
            <li>One secure ballot per eligible voter</li>
            <li>Anonymous ballots designed to protect voter choice</li>
            <li>Clear election schedules and published results</li>
        </ul>
        <?php if ($user): ?>
            <a class="btn btn--primary" href="<?php echo e($dashboardUrl); ?>">Continue to your panel</a>
        <?php else: ?>
            <a class="btn" href="/VOTING_SYSTEM/register.php">Register as a voter</a>
        <?php endif; ?>
    </div>
    <?php if (!$user): ?>
        <div class="home-login card">
            <h2>Sign in</h2>
            <p>Access your voter, manager, or administrator panel.</p>
            <form method="post" action="/VOTING_SYSTEM/login.php" autocomplete="on">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="home_email">Email</label>
                    <input class="form-control" type="email" id="home_email" name="email" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="home_password">Password</label>
                    <input class="form-control" type="password" id="home_password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn--primary" type="submit">Sign in</button>
                <a class="home-verify-link" href="/VOTING_SYSTEM/verify_email.php">Verify voter account</a>
            </form>
            <p class="home-register-note">New voter? <a href="/VOTING_SYSTEM/register.php">Create an account</a></p>
        </div>
    <?php endif; ?>
</section>

<section class="home-features" aria-label="About the voting system">
    <article class="card">
        <h2>For voters</h2>
        <p>Review elections you are eligible for, cast one ballot while voting is open, and check results when they are published.</p>
    </article>
    <article class="card">
        <h2>For election teams</h2>
        <p>Coordinate assigned elections, review candidate nominations, and monitor participation without exposing individual voting choices.</p>
    </article>
    <article class="card">
        <h2>Privacy and integrity</h2>
        <p>Ballots are stored separately from voter identities. Eligibility checks help prevent repeat voting while protecting ballot secrecy.</p>
    </article>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
// admin/dashboard.php
require_once __DIR__ . '/../includes/header.php';
require_role(['admin']);
$active = 'dashboard';

global $mysqli;
$stats = [
    'users'      => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM users")['c'],
    'voters'     => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM voters")['c'],
    'elections'  => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM elections")['c'],
    'candidates' => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM candidates")['c'],
    'ballots'    => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM ballots")['c'],
    'logs'       => (int) db_fetch_one($mysqli, "SELECT COUNT(*) c FROM audit_logs")['c'],
];
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Administrator Dashboard</h2>
            <p>Welcome, <?php echo e(current_user()['full_name']); ?>.</p>
        </div>
        <div class="grid grid--3">
            <div class="stat"><div class="stat__value"><?php echo $stats['users']; ?></div><div class="stat__label">Users</div></div>
            <div class="stat"><div class="stat__value"><?php echo $stats['voters']; ?></div><div class="stat__label">Voters</div></div>
            <div class="stat"><div class="stat__value"><?php echo $stats['elections']; ?></div><div class="stat__label">Elections</div></div>
            <div class="stat"><div class="stat__value"><?php echo $stats['candidates']; ?></div><div class="stat__label">Candidates</div></div>
            <div class="stat"><div class="stat__value"><?php echo $stats['ballots']; ?></div><div class="stat__label">Ballots cast</div></div>
            <div class="stat"><div class="stat__value"><?php echo $stats['logs']; ?></div><div class="stat__label">Audit entries</div></div>
        </div>
        <div class="card">
            <h3>Quick Links</h3>
            <p>
                <a class="btn btn--primary" href="/VOTING_SYSTEM/admin/users.php">Manage Users</a>
                <a class="btn" href="/VOTING_SYSTEM/admin/voters.php">Manage Voters</a>
                <a class="btn" href="/VOTING_SYSTEM/admin/elections.php">Manage Elections</a>
                <a class="btn" href="/VOTING_SYSTEM/admin/candidates.php">Manage Candidates</a>
                <a class="btn" href="/VOTING_SYSTEM/admin/reports.php">Reports</a>
                <a class="btn" href="/VOTING_SYSTEM/admin/settings.php">Settings</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['manager']);

$active = 'dashboard';
global $mysqli;
$user = current_user();
$managerId = (int)$user['id'];
$stats = db_fetch_one(
    $mysqli,
    "SELECT COUNT(DISTINCT e.id) AS elections,
            COUNT(DISTINCT CASE WHEN e.status='active' THEN e.id END) AS active_elections,
            COUNT(DISTINCT b.id) AS ballots
     FROM election_managers em
     JOIN elections e ON e.id=em.election_id
     LEFT JOIN ballots b ON b.election_id=e.id
     WHERE em.user_id=?",
    "i",
    [$managerId]
);
$elections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.status, e.start_at, e.end_at
     FROM election_managers em
     JOIN elections e ON e.id=em.election_id
     WHERE em.user_id=?
     ORDER BY CASE e.status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END,
              e.start_at DESC, e.id DESC
     LIMIT 5",
    "i",
    [$managerId]
);

$pageTitle = 'Manager Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Election Manager Dashboard</h2>
            <p>Welcome, <?php echo e($user['full_name']); ?>. This panel only shows elections assigned to you.</p>
        </div>
        <div class="grid grid--3">
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['elections'] ?? 0); ?></div><div class="stat__label">Assigned elections</div></div>
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['active_elections'] ?? 0); ?></div><div class="stat__label">Active elections</div></div>
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['ballots'] ?? 0); ?></div><div class="stat__label">Ballots cast</div></div>
        </div>
        <div class="card">
            <h3>My Elections</h3>
            <?php if ($elections): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Election</th><th>Status</th><th>Voting period</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($elections as $election): ?>
                            <tr>
                                <td><?php echo e($election['title']); ?></td>
                                <td><span class="badge badge--<?php echo e($election['status']); ?>"><?php echo e(ucfirst($election['status'])); ?></span></td>
                                <td><?php echo e($election['start_at']); ?> — <?php echo e($election['end_at']); ?></td>
                                <td><a class="btn btn--sm" href="/VOTING_SYSTEM/manager/elections.php">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No elections are assigned to your account yet. Contact an administrator to request an assignment.</p>
            <?php endif; ?>
            <p>
                <a class="btn btn--primary" href="/VOTING_SYSTEM/manager/elections.php">My Elections</a>
                <a class="btn" href="/VOTING_SYSTEM/manager/candidates.php">Candidates</a>
                <a class="btn" href="/VOTING_SYSTEM/manager/monitoring.php">Monitoring</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

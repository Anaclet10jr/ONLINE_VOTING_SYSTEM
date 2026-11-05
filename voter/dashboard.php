<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['voter']);

$active = 'dashboard';
global $mysqli;
$user = current_user();
$voter = db_fetch_one($mysqli, "SELECT id, is_verified FROM voters WHERE user_id=?", "i", [(int)$user['id']]);
if (!$voter) {
    http_response_code(403);
    exit('Voter profile not found. Please contact an administrator.');
}
$voterId = (int)$voter['id'];
$stats = db_fetch_one(
    $mysqli,
    "SELECT
        (SELECT COUNT(*) FROM elections e
         LEFT JOIN voter_elections ve ON ve.election_id=e.id AND ve.voter_id=?
         WHERE e.status='scheduled'
           AND (e.eligibility_mode='all_voters' OR ve.is_eligible=1)) AS upcoming,
        (SELECT COUNT(*) FROM voter_elections ve
         JOIN elections e ON e.id=ve.election_id
         WHERE ve.voter_id=? AND ve.is_eligible=1 AND ve.voted_at IS NULL
           AND e.status='active' AND ?=1) AS active,
        (SELECT COUNT(*) FROM voter_elections ve
         WHERE ve.voter_id=? AND ve.is_eligible=1 AND ve.voted_at IS NOT NULL) AS voted",
    "iiii",
    [$voterId, $voterId, (int)$voter['is_verified'], $voterId]
);
$activeElections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.end_at
     FROM voter_elections ve
     JOIN elections e ON e.id=ve.election_id
     WHERE ve.voter_id=? AND ve.is_eligible=1 AND ve.voted_at IS NULL
       AND e.status='active' AND ?=1
     ORDER BY e.end_at, e.id
     LIMIT 5",
    "ii",
    [$voterId, (int)$voter['is_verified']]
);

$pageTitle = 'Voter Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Voter Dashboard</h2>
            <p>Welcome, <?php echo e($user['full_name']); ?>.</p>
            <p>Voter profile status:
                <?php if ((int)$voter['is_verified'] === 1): ?>
                    <span class="badge badge--active">Verified</span>
                <?php else: ?>
                    <span class="badge badge--draft">Pending verification</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="grid grid--3">
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['upcoming'] ?? 0); ?></div><div class="stat__label">Upcoming elections</div></div>
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['active'] ?? 0); ?></div><div class="stat__label">Elections open to you</div></div>
            <div class="stat"><div class="stat__value"><?php echo (int)($stats['voted'] ?? 0); ?></div><div class="stat__label">Elections you have voted in</div></div>
        </div>
        <div class="card">
            <h3>Voting is open</h3>
            <?php if ($activeElections): ?>
                <ul>
                    <?php foreach ($activeElections as $election): ?>
                        <li><?php echo e($election['title']); ?> — closes <?php echo e($election['end_at']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn--primary" href="/VOTING_SYSTEM/voter/elections.php">Review elections and vote</a>
            <?php else: ?>
                <p>You have no active elections to vote in right now.</p>
                <a class="btn" href="/VOTING_SYSTEM/voter/elections.php">View my elections</a>
            <?php endif; ?>
            <a class="btn" href="/VOTING_SYSTEM/voter/profile.php">Update my profile</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['manager']);

$active = 'elections';
global $mysqli;
$userId = (int)current_user()['id'];
$elections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.description, e.start_at, e.end_at, e.status,
            e.eligibility_mode,
            (SELECT COUNT(*) FROM election_candidates ec WHERE ec.election_id=e.id) AS candidate_count,
            (SELECT COUNT(*) FROM ballots b WHERE b.election_id=e.id) AS ballot_count
     FROM election_managers em
     JOIN elections e ON e.id=em.election_id
     WHERE em.user_id=?
     ORDER BY e.start_at DESC, e.id DESC",
    "i",
    [$userId]
);

$pageTitle = 'My Elections';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>My Elections</h2>
            <p>View election schedules and nomination progress for elections assigned to you. Election setup and status changes are managed by an administrator.</p>
        </div>
        <?php if ($elections): ?>
            <?php foreach ($elections as $election): ?>
                <section class="card">
                    <h3><?php echo e($election['title']); ?></h3>
                    <?php if (!empty($election['description'])): ?><p><?php echo nl2br(e($election['description'])); ?></p><?php endif; ?>
                    <p>
                        <strong>Status:</strong>
                        <span class="badge badge--<?php echo e($election['status']); ?>"><?php echo e(ucfirst($election['status'])); ?></span><br>
                        <strong>Voting period:</strong> <?php echo e($election['start_at']); ?> to <?php echo e($election['end_at']); ?><br>
                        <strong>Eligibility:</strong> <?php echo $election['eligibility_mode'] === 'all_voters' ? 'All voters' : 'Selected voters'; ?><br>
                        <strong>Nominations:</strong> <?php echo (int)$election['candidate_count']; ?>
                        &nbsp; <strong>Ballots:</strong> <?php echo (int)$election['ballot_count']; ?>
                    </p>
                    <a class="btn btn--sm" href="/VOTING_SYSTEM/manager/candidates.php?election_id=<?php echo (int)$election['id']; ?>">View candidates</a>
                    <a class="btn btn--sm" href="/VOTING_SYSTEM/manager/monitoring.php">View monitoring</a>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card"><p>No elections are assigned to your account yet. Contact an administrator to request an assignment.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

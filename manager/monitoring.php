<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['manager']);

$active = 'monitoring';
global $mysqli;
$userId = (int)current_user()['id'];
$elections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.status, e.start_at, e.end_at,
            (SELECT COUNT(*) FROM voter_elections ve
             WHERE ve.election_id=e.id AND ve.is_eligible=1) AS eligible_count,
            (SELECT COUNT(*) FROM voter_elections ve
             WHERE ve.election_id=e.id AND ve.is_eligible=1 AND ve.voted_at IS NOT NULL) AS voted_count,
            (SELECT COUNT(*) FROM ballots b WHERE b.election_id=e.id) AS ballot_count
     FROM election_managers em
     JOIN elections e ON e.id=em.election_id
     WHERE em.user_id=?
     ORDER BY e.start_at DESC, e.id DESC",
    "i",
    [$userId]
);

$pageTitle = 'Election Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Election Monitoring</h2>
            <p>Participation statistics are shown only for elections assigned to you. Ballots are anonymous; this page never displays voter identities or live candidate tallies.</p>
        </div>
        <?php if ($elections): ?>
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Election</th><th>Status</th><th>Eligible</th><th>Participation</th><th>Ballots</th><th>Results</th></tr></thead>
                        <tbody>
                        <?php foreach ($elections as $election): ?>
                            <?php
                            $eligible = (int)$election['eligible_count'];
                            $voted = (int)$election['voted_count'];
                            $participation = $eligible > 0 ? number_format(($voted / $eligible) * 100, 1) . '%' : '—';
                            ?>
                            <tr>
                                <td><?php echo e($election['title']); ?><br><small><?php echo e($election['start_at']); ?> to <?php echo e($election['end_at']); ?></small></td>
                                <td><span class="badge badge--<?php echo e($election['status']); ?>"><?php echo e(ucfirst($election['status'])); ?></span></td>
                                <td><?php echo $eligible; ?></td>
                                <td><?php echo $voted; ?> (<?php echo $participation; ?>)</td>
                                <td><?php echo (int)$election['ballot_count']; ?></td>
                                <td>
                                    <?php if ($election['status'] === 'published'): ?>
                                        <a class="btn btn--sm" href="/VOTING_SYSTEM/results/index.php?election_id=<?php echo (int)$election['id']; ?>">Published results</a>
                                    <?php else: ?>
                                        Not published
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="card"><p>No elections are assigned to your account yet.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

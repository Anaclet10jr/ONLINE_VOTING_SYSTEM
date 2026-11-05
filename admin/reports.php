<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$active = 'reports';
global $mysqli;

$export = $_GET['export'] ?? '';
$requestedElectionId = filter_var($_GET['election_id'] ?? null, FILTER_VALIDATE_INT);
$electionId = $requestedElectionId !== false ? (int)$requestedElectionId : 0;

$elections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.status, e.start_at, e.end_at,
            (SELECT COUNT(*) FROM election_candidates ec WHERE ec.election_id=e.id AND ec.status='approved') AS candidate_count,
            (SELECT COUNT(*) FROM ballots b WHERE b.election_id=e.id) AS ballot_count,
            (SELECT COUNT(*) FROM voter_elections ve WHERE ve.election_id=e.id AND ve.is_eligible=1) AS eligible_count
     FROM elections e
     ORDER BY e.start_at DESC, e.id DESC"
);

$selectedElection = null;
foreach ($elections as $item) {
    if ((int)$item['id'] === $electionId) {
        $selectedElection = $item;
        break;
    }
}

$candidateResults = [];
$winnerNames = [];
$winnerVoteCount = 0;
if ($selectedElection) {
    $candidateResults = db_fetch_all(
        $mysqli,
        "SELECT c.full_name, COUNT(b.id) AS vote_count
         FROM election_candidates ec
         JOIN candidates c ON c.id=ec.candidate_id
         LEFT JOIN ballots b ON b.election_candidate_id=ec.id AND b.election_id=ec.election_id
         WHERE ec.election_id=? AND ec.status='approved'
         GROUP BY ec.id, c.full_name, ec.position_order
         ORDER BY vote_count DESC, ec.position_order, c.full_name",
        "i",
        [$electionId]
    );
    if (in_array($selectedElection['status'], ['closed', 'published'], true) && $candidateResults) {
        $winnerVoteCount = (int)$candidateResults[0]['vote_count'];
        if ($winnerVoteCount > 0) {
            foreach ($candidateResults as $result) {
                if ((int)$result['vote_count'] !== $winnerVoteCount) {
                    break;
                }
                $winnerNames[] = $result['full_name'];
            }
        }
    }
}

if ($export === 'elections' || $export === 'results') {
    if ($export === 'results' && !$selectedElection) {
        http_response_code(404);
        exit('Election not found.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sovms-' . $export . '-' . date('Ymd-His') . '.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to create report output.');
    }

    if ($export === 'elections') {
        fputcsv($output, ['Election ID', 'Title', 'Status', 'Starts', 'Ends', 'Approved candidates', 'Eligible voters', 'Ballots']);
        foreach ($elections as $item) {
            fputcsv($output, [
                (int)$item['id'],
                $item['title'],
                $item['status'],
                $item['start_at'],
                $item['end_at'],
                (int)$item['candidate_count'],
                (int)$item['eligible_count'],
                (int)$item['ballot_count'],
            ]);
        }
    } else {
        fputcsv($output, ['Election', 'Candidate', 'Votes']);
        foreach ($candidateResults as $result) {
            fputcsv($output, [$selectedElection['title'], $result['full_name'], (int)$result['vote_count']]);
        }
    }
    fclose($output);
    exit;
}

$pageTitle = 'Election Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Election reports</h2>
            <p>Review election participation and candidate vote totals. Reports contain aggregate ballot counts only.</p>
            <a class="btn btn--primary" href="/VOTING_SYSTEM/admin/reports.php?export=elections">Download election summary CSV</a>
        </div>
        <div class="card">
            <h3>Election summary</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Election</th><th>Status</th><th>Schedule</th><th>Approved candidates</th><th>Eligible voters</th><th>Ballots</th><th>Report</th></tr></thead>
                    <tbody>
                    <?php foreach ($elections as $item): ?>
                        <tr>
                            <td><?php echo e($item['title']); ?></td>
                            <td><span class="badge badge--<?php echo e($item['status']); ?>"><?php echo e(ucfirst($item['status'])); ?></span></td>
                            <td><?php echo e($item['start_at']); ?><br>to <?php echo e($item['end_at']); ?></td>
                            <td><?php echo (int)$item['candidate_count']; ?></td>
                            <td><?php echo (int)$item['eligible_count']; ?></td>
                            <td><?php echo (int)$item['ballot_count']; ?></td>
                            <td>
                                <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/reports.php?election_id=<?php echo (int)$item['id']; ?>">View</a>
                                <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/reports.php?export=results&amp;election_id=<?php echo (int)$item['id']; ?>">CSV</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$elections): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);">No elections are available.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($selectedElection): ?>
            <div class="card">
                <h3>Candidate totals — <?php echo e($selectedElection['title']); ?></h3>
                <?php if (in_array($selectedElection['status'], ['closed', 'published'], true)): ?>
                    <?php if ($winnerNames): ?>
                        <p><strong><?php echo count($winnerNames) > 1 ? 'Tie' : 'Winner'; ?>:</strong>
                            <?php echo e(implode(', ', $winnerNames)); ?>
                            (<?php echo $winnerVoteCount; ?> <?php echo $winnerVoteCount === 1 ? 'vote' : 'votes'; ?>)
                        </p>
                    <?php else: ?>
                        <p>No ballots were cast; there is no winner to declare.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($selectedElection['status'] !== 'published'): ?>
                    <p>These totals are private until the election is published.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Rank</th><th>Candidate</th><th>Votes</th></tr></thead>
                        <tbody>
                        <?php $rank = 0; $previousVotes = null; ?>
                        <?php foreach ($candidateResults as $index => $result): ?>
                            <?php $votes = (int)$result['vote_count']; ?>
                            <?php if ($previousVotes === null || $votes !== $previousVotes) $rank = $index + 1; ?>
                            <?php $previousVotes = $votes; ?>
                            <tr><td><?php echo $rank; ?></td><td><?php echo e($result['full_name']); ?></td><td><?php echo $votes; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$candidateResults): ?><tr><td colspan="3" style="text-align:center;color:var(--muted);">No approved candidates are available.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

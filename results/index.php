<?php
require_once __DIR__ . '/../includes/auth.php';

global $mysqli;
$publishedElections = db_fetch_all(
    $mysqli,
    "SELECT id, title, start_at, end_at, published_at
     FROM elections
     WHERE status='published' AND results_public=1
     ORDER BY published_at DESC, id DESC"
);

$hasRequestedId = array_key_exists('election_id', $_GET);
$requestedId = $hasRequestedId ? filter_var($_GET['election_id'], FILTER_VALIDATE_INT) : null;
$election = null;
$results = [];
$totalBallots = 0;

if ($hasRequestedId) {
    if ($requestedId === false || $requestedId <= 0) {
        http_response_code(404);
    } else {
        foreach ($publishedElections as $publishedElection) {
            if ((int)$publishedElection['id'] === (int)$requestedId) {
                $election = $publishedElection;
                break;
            }
        }
        if (!$election) {
            http_response_code(404);
        }
    }
} elseif ($publishedElections) {
    $election = $publishedElections[0];
}

if ($election) {
    $totalRow = db_fetch_one(
        $mysqli,
        "SELECT COUNT(*) AS ballot_count FROM ballots WHERE election_id=?",
        "i",
        [(int)$election['id']]
    );
    $totalBallots = (int)($totalRow['ballot_count'] ?? 0);
    $results = db_fetch_all(
        $mysqli,
        "SELECT ec.id, c.full_name, ec.position_order, COUNT(b.id) AS vote_count
         FROM election_candidates ec
         JOIN candidates c ON c.id=ec.candidate_id
         LEFT JOIN ballots b
           ON b.election_candidate_id=ec.id AND b.election_id=ec.election_id
         WHERE ec.election_id=? AND ec.status='approved'
         GROUP BY ec.id, c.full_name, ec.position_order
         ORDER BY vote_count DESC, ec.position_order ASC, c.full_name ASC",
        "i",
        [(int)$election['id']]
    );
}

$pageTitle = 'Election Results';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>Published Election Results</h2>
    <?php if ($publishedElections): ?>
        <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:220px;margin-bottom:0;">
                <label for="election_id">Election</label>
                <select class="form-control" id="election_id" name="election_id">
                    <?php foreach ($publishedElections as $item): ?>
                        <option value="<?php echo (int)$item['id']; ?>" <?php echo $election && (int)$election['id'] === (int)$item['id'] ? 'selected' : ''; ?>>
                            <?php echo e($item['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn--primary" type="submit">View results</button>
        </form>

        <?php if ($election): ?>
            <h3><?php echo e($election['title']); ?></h3>
            <p>
                Election period: <?php echo e($election['start_at']); ?> to <?php echo e($election['end_at']); ?><br>
                Published: <?php echo e($election['published_at']); ?><br>
                Total ballots: <?php echo $totalBallots; ?>
            </p>
            <?php if ($results): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Rank</th><th>Candidate</th><th>Votes</th><th>Share</th></tr></thead>
                        <tbody>
                        <?php $rank = 0; $previousVoteCount = null; ?>
                        <?php foreach ($results as $index => $result): ?>
                            <?php $voteCount = (int)$result['vote_count']; ?>
                            <?php if ($previousVoteCount === null || $voteCount !== $previousVoteCount) $rank = $index + 1; ?>
                            <?php $previousVoteCount = $voteCount; ?>
                            <?php $share = $totalBallots > 0 ? ($voteCount / $totalBallots) * 100 : 0; ?>
                            <tr>
                                <td><?php echo $rank; ?></td>
                                <td><?php echo e($result['full_name']); ?></td>
                                <td><?php echo $voteCount; ?></td>
                                <td><?php echo number_format($share, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No approved candidates are recorded for this election.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>The requested election does not have published results.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>No election results have been published yet.</p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

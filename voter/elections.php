<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['voter']);

$active = 'elections';
global $mysqli;
$user = current_user();
$voter = db_fetch_one($mysqli, "SELECT id, is_verified FROM voters WHERE user_id=?", "i", [(int)$user['id']]);
if (!$voter) {
    http_response_code(403);
    exit('Voter profile not found. Please contact an administrator.');
}
$voterId = (int)$voter['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
    }

    $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
    $electionCandidateId = filter_var($_POST['election_candidate_id'] ?? null, FILTER_VALIDATE_INT);
    if ($electionId === false || $electionId <= 0 || $electionCandidateId === false || $electionCandidateId <= 0) {
        set_flash('danger', 'Choose a valid candidate and election.');
        header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
    }
    if ((int)$voter['is_verified'] !== 1) {
        set_flash('danger', 'Your voter profile must be verified before you can vote. Please contact an administrator.');
        header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
    }

    $mysqli->begin_transaction();
    $transactionActive = true;
    try {
        $eligibility = db_fetch_one(
            $mysqli,
            "SELECT id, is_eligible, voted_at
             FROM voter_elections
             WHERE voter_id=? AND election_id=?
             FOR UPDATE",
            "ii",
            [$voterId, $electionId]
        );
        if (!$eligibility || (int)$eligibility['is_eligible'] !== 1 || $eligibility['voted_at'] !== null) {
            $mysqli->rollback();
            $transactionActive = false;
            set_flash('danger', 'You are not eligible to vote in this election or have already voted.');
            header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
        }

        $election = db_fetch_one(
            $mysqli,
            "SELECT id, status, start_at, end_at FROM elections WHERE id=? LOCK IN SHARE MODE",
            "i",
            [$electionId]
        );
        if (!$election || $election['status'] !== 'active' ||
            strtotime($election['start_at']) > time() || strtotime($election['end_at']) <= time()) {
            $mysqli->rollback();
            $transactionActive = false;
            set_flash('danger', 'Voting is not currently open for this election.');
            header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
        }

        $candidate = db_fetch_one(
            $mysqli,
            "SELECT id FROM election_candidates
             WHERE id=? AND election_id=? AND status='approved'",
            "ii",
            [$electionCandidateId, $electionId]
        );
        if (!$candidate) {
            $mysqli->rollback();
            $transactionActive = false;
            set_flash('danger', 'The selected candidate is not approved for this election.');
            header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
        }

        $receiptCode = bin2hex(random_bytes(32));
        $ipHash = hash_ip($_SERVER['REMOTE_ADDR'] ?? null);
        db_execute(
            $mysqli,
            "INSERT INTO ballots (election_id, election_candidate_id, receipt_code, cast_ip_hash) VALUES (?,?,?,?)",
            "iiss",
            [$electionId, $electionCandidateId, $receiptCode, null]
        );
        $updated = db_execute(
            $mysqli,
            "UPDATE voter_elections
             SET voted_at=NOW(), voted_ip_hash=?
             WHERE id=? AND is_eligible=1 AND voted_at IS NULL",
            "si",
            [$ipHash, (int)$eligibility['id']]
        );
        if ($updated !== 1) {
            throw new RuntimeException('Voter eligibility row changed while recording a ballot.');
        }

        $mysqli->commit();
        $transactionActive = false;
        set_flash('success', 'Your vote was recorded. Ballots do not store your voter identity.');
    } catch (Throwable $t) {
        if ($transactionActive) {
            $mysqli->rollback();
        }
        error_log('Vote submission failed: ' . $t->getMessage());
        set_flash('danger', 'Unable to record your vote. Please try again.');
    }
    header('Location: /VOTING_SYSTEM/voter/elections.php'); exit;
}

$elections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.description, e.start_at, e.end_at, e.status,
            e.results_public, ve.voted_at
     FROM elections e
     LEFT JOIN voter_elections ve ON ve.election_id=e.id AND ve.voter_id=?
     WHERE e.status IN ('scheduled','active','closed','published')
       AND ((e.status='scheduled' AND e.eligibility_mode='all_voters') OR ve.is_eligible=1)
     ORDER BY e.start_at ASC, e.id ASC",
    "i",
    [$voterId]
);

$candidatesByElection = [];
foreach ($elections as $election) {
    if ((int)$voter['is_verified'] === 1 && $election['status'] === 'active' && $election['voted_at'] === null) {
        $candidatesByElection[(int)$election['id']] = db_fetch_all(
            $mysqli,
            "SELECT ec.id, ec.manifesto, c.full_name
             FROM election_candidates ec
             JOIN candidates c ON c.id=ec.candidate_id
             WHERE ec.election_id=? AND ec.status='approved'
             ORDER BY ec.position_order, c.full_name",
            "i",
            [(int)$election['id']]
        );
    }
}

$pageTitle = 'My Elections';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>My Elections</h2>
            <p>Review your eligible elections and cast one ballot while voting is open.</p>
            <?php if ((int)$voter['is_verified'] !== 1): ?>
                <p class="flash flash--warning">Your voter profile is not verified yet. You can review elections, but voting is unavailable until an administrator verifies your profile.</p>
            <?php endif; ?>
        </div>

        <?php foreach ($elections as $election): ?>
            <?php $electionId = (int)$election['id']; ?>
            <section class="card">
                <h3><?php echo e($election['title']); ?></h3>
                <?php if (!empty($election['description'])): ?><p><?php echo nl2br(e($election['description'])); ?></p><?php endif; ?>
                <p>
                    <strong>Status:</strong> <span class="badge badge--<?php echo e($election['status']); ?>"><?php echo e(ucfirst($election['status'])); ?></span><br>
                    <strong>Voting period:</strong> <?php echo e($election['start_at']); ?> to <?php echo e($election['end_at']); ?>
                </p>

                <?php if ($election['status'] === 'active' && $election['voted_at'] !== null): ?>
                    <p>You have already voted in this election.</p>
                <?php elseif ($election['status'] === 'active' && (int)$voter['is_verified'] !== 1): ?>
                    <p>Your profile must be verified before you can vote.</p>
                <?php elseif ($election['status'] === 'active'): ?>
                    <?php $candidates = $candidatesByElection[$electionId] ?? []; ?>
                    <?php if ($candidates && strtotime($election['start_at']) <= time() && strtotime($election['end_at']) > time()): ?>
                        <form method="post" onsubmit="return confirm('Submit your vote? Your choice cannot be changed.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="election_id" value="<?php echo $electionId; ?>">
                            <fieldset style="border:0;padding:0;margin:0 0 16px;">
                                <legend class="card__title">Choose one candidate</legend>
                                <?php foreach ($candidates as $candidate): ?>
                                    <label style="display:block;padding:10px 0;border-bottom:1px solid var(--grey-dark);">
                                        <input type="radio" name="election_candidate_id" value="<?php echo (int)$candidate['id']; ?>" required>
                                        <strong><?php echo e($candidate['full_name']); ?></strong>
                                        <?php if (!empty($candidate['manifesto'])): ?><br><?php echo nl2br(e($candidate['manifesto'])); ?><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <button class="btn btn--primary" type="submit">Cast vote</button>
                        </form>
                    <?php else: ?>
                        <p>Voting is not currently available for this election.</p>
                    <?php endif; ?>
                <?php elseif ($election['status'] === 'scheduled'): ?>
                    <p>Voting has not started yet.</p>
                <?php elseif ($election['status'] === 'closed'): ?>
                    <p>Voting has ended.</p>
                <?php elseif ($election['status'] === 'published' && (int)$election['results_public'] === 1): ?>
                    <p><a class="btn btn--sm" href="/VOTING_SYSTEM/results/index.php?election_id=<?php echo $electionId; ?>">View published results</a></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if (!$elections): ?>
            <div class="card"><p>You are not currently eligible for any upcoming or active elections.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['manager']);

$active = 'candidates';
global $mysqli;
$userId = (int)current_user()['id'];
$assignedElections = db_fetch_all(
    $mysqli,
    "SELECT e.id, e.title, e.status
     FROM election_managers em
     JOIN elections e ON e.id=em.election_id
     WHERE em.user_id=?
     ORDER BY e.start_at DESC, e.id DESC",
    "i",
    [$userId]
);
$selectedElectionId = filter_var($_POST['election_id'] ?? $_GET['election_id'] ?? null, FILTER_VALIDATE_INT);
$selectedElectionId = $selectedElectionId === false ? 0 : (int)$selectedElectionId;
$selectedElection = null;
foreach ($assignedElections as $assignedElection) {
    if ((int)$assignedElection['id'] === $selectedElectionId) {
        $selectedElection = $assignedElection;
        break;
    }
}
if (!$selectedElection && $_SERVER['REQUEST_METHOD'] !== 'POST' && $assignedElections) {
    $selectedElection = $assignedElections[0];
    $selectedElectionId = (int)$selectedElection['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/manager/candidates.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'nominate') {
        $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT);
        $manifestoInput = $_POST['manifesto'] ?? '';
        if (!$selectedElection || $candidateId === false || $candidateId <= 0 ||
            !in_array($selectedElection['status'], ['draft', 'scheduled'], true) ||
            !is_string($manifestoInput) || strlen($manifestoInput) > 60000) {
            set_flash('danger', 'Check the candidate, manifesto length, and election status.');
            header('Location: /VOTING_SYSTEM/manager/candidates.php');
            exit;
        }
        require_election_scope($selectedElectionId);
        $manifesto = trim($manifestoInput);
        $mysqli->begin_transaction();
        try {
            $lockedElection = db_fetch_one(
                $mysqli,
                "SELECT e.status FROM election_managers em
                 JOIN elections e ON e.id=em.election_id
                 WHERE em.user_id=? AND em.election_id=?
                 FOR UPDATE",
                "ii",
                [$userId, $selectedElectionId]
            );
            if (!$lockedElection || !in_array($lockedElection['status'], ['draft', 'scheduled'], true)) {
                throw new DomainException('This election is no longer available for nominations.');
            }
            $candidate = db_fetch_one($mysqli, "SELECT id FROM candidates WHERE id=?", "i", [$candidateId]);
            if (!$candidate) {
                throw new DomainException('Candidate not found.');
            }
            $existing = db_fetch_one(
                $mysqli,
                "SELECT id FROM election_candidates WHERE election_id=? AND candidate_id=?",
                "ii",
                [$selectedElectionId, $candidateId]
            );
            if ($existing) {
                throw new DomainException('That candidate is already nominated for this election.');
            }
            db_execute(
                $mysqli,
                "INSERT INTO election_candidates (election_id, candidate_id, manifesto, status) VALUES (?,?,?,'pending')",
                "iis",
                [$selectedElectionId, $candidateId, $manifesto !== '' ? $manifesto : null]
            );
            $mysqli->commit();
        } catch (DomainException $t) {
            $mysqli->rollback();
            set_flash('danger', $t->getMessage());
            header('Location: /VOTING_SYSTEM/manager/candidates.php?election_id=' . $selectedElectionId);
            exit;
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Manager candidate nomination failed: ' . $t->getMessage());
            set_flash('danger', 'Unable to submit nomination. Refresh the page and try again.');
            header('Location: /VOTING_SYSTEM/manager/candidates.php?election_id=' . $selectedElectionId);
            exit;
        }
        audit_log($userId, 'candidate_nominate', 'election', $selectedElectionId, "Manager nominated candidate $candidateId");
        set_flash('success', 'Nomination submitted and is awaiting administrator review.');
        header('Location: /VOTING_SYSTEM/manager/candidates.php?election_id=' . $selectedElectionId);
        exit;
    }
}

$nominations = [];
$availableCandidates = [];
if ($selectedElection) {
    require_election_scope($selectedElectionId);
    $nominations = db_fetch_all(
        $mysqli,
        "SELECT ec.id, ec.status, ec.manifesto, ec.rejection_reason, ec.position_order,
                c.full_name
         FROM election_candidates ec
         JOIN candidates c ON c.id=ec.candidate_id
         WHERE ec.election_id=?
         ORDER BY ec.position_order, c.full_name",
        "i",
        [$selectedElectionId]
    );
    if (in_array($selectedElection['status'], ['draft', 'scheduled'], true)) {
        $availableCandidates = db_fetch_all(
            $mysqli,
            "SELECT c.id, c.full_name
             FROM candidates c
             WHERE NOT EXISTS (
                 SELECT 1 FROM election_candidates ec
                 WHERE ec.election_id=? AND ec.candidate_id=c.id
             )
             ORDER BY c.full_name",
            "i",
            [$selectedElectionId]
        );
    }
}

$pageTitle = 'Election Candidates';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>Candidates</h2>
            <p>Review nominations for your assigned elections or nominate an existing candidate before voting begins. Administrators approve nominations.</p>
            <?php if ($assignedElections): ?>
                <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                    <div class="form-group" style="min-width:220px;margin:0;">
                        <label for="election_id">Election</label>
                        <select class="form-control" id="election_id" name="election_id">
                            <?php foreach ($assignedElections as $item): ?>
                                <option value="<?php echo (int)$item['id']; ?>" <?php echo (int)$item['id'] === $selectedElectionId ? 'selected' : ''; ?>><?php echo e($item['title']); ?> (<?php echo e($item['status']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn--primary" type="submit">View candidates</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$assignedElections): ?>
            <div class="card"><p>No elections are assigned to your account yet.</p></div>
        <?php elseif ($selectedElection): ?>
            <?php if (in_array($selectedElection['status'], ['draft', 'scheduled'], true)): ?>
                <div class="card">
                    <h3>Nominate a Candidate</h3>
                    <?php if ($availableCandidates): ?>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="nominate">
                            <input type="hidden" name="election_id" value="<?php echo $selectedElectionId; ?>">
                            <div class="form-group">
                                <label for="candidate_id">Candidate</label>
                                <select class="form-control" id="candidate_id" name="candidate_id" required>
                                    <option value="">Choose a candidate</option>
                                    <?php foreach ($availableCandidates as $candidate): ?>
                                        <option value="<?php echo (int)$candidate['id']; ?>"><?php echo e($candidate['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="manifesto">Manifesto (optional)</label>
                                <textarea class="form-control" id="manifesto" name="manifesto" rows="3"></textarea>
                            </div>
                            <button class="btn btn--primary" type="submit">Submit nomination</button>
                        </form>
                    <?php else: ?>
                        <p>No additional candidates are available. Ask an administrator to add candidates first.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="card">
                <h3><?php echo e($selectedElection['title']); ?> nominations</h3>
                <?php if ($nominations): ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Candidate</th><th>Status</th><th>Manifesto</th><th>Review note</th></tr></thead>
                            <tbody>
                            <?php foreach ($nominations as $nomination): ?>
                                <tr>
                                    <td><?php echo e($nomination['full_name']); ?></td>
                                    <td><?php echo e(ucfirst($nomination['status'])); ?></td>
                                    <td><?php echo nl2br(e($nomination['manifesto'] ?? '')); ?></td>
                                    <td><?php echo e($nomination['rejection_reason'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No candidates have been nominated yet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$active = 'candidates';
global $mysqli;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'create_candidate' || $action === 'update_candidate') {
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $name = clean_string($_POST['full_name'] ?? '', 120);
        $bio = trim($_POST['bio'] ?? '');
        if ($name === '') {
            set_flash('danger', 'Candidate name is required.');
            header('Location: /VOTING_SYSTEM/admin/candidates.php' . ($candidateId > 0 ? '?edit=' . $candidateId : '')); exit;
        }
        if ($action === 'create_candidate') {
            db_execute(
                $mysqli,
                "INSERT INTO candidates (full_name, bio, created_by) VALUES (?,?,?)",
                "ssi",
                [$name, $bio !== '' ? $bio : null, current_user()['id']]
            );
            $candidateId = (int)$mysqli->insert_id;
            audit_log(current_user()['id'], 'candidate_create', 'candidate', $candidateId, "Created candidate $name");
            set_flash('success', 'Candidate created.');
        } else {
            $editableCandidate = $candidateId > 0 ? db_fetch_one(
                $mysqli,
                "SELECT c.id FROM candidates c
                 WHERE c.id=?
                   AND NOT EXISTS (
                       SELECT 1 FROM election_candidates ec
                       JOIN elections e ON e.id=ec.election_id
                       WHERE ec.candidate_id=c.id AND e.status NOT IN ('draft','scheduled')
                   )",
                "i",
                [$candidateId]
            ) : null;
            if (!$editableCandidate) {
                set_flash('danger', 'Candidate not found or already associated with an election that has started.');
                header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
            }
            db_execute(
                $mysqli,
                "UPDATE candidates SET full_name=?, bio=? WHERE id=?",
                "ssi",
                [$name, $bio !== '' ? $bio : null, $candidateId]
            );
            audit_log(current_user()['id'], 'candidate_update', 'candidate', $candidateId, "Updated candidate $candidateId");
            set_flash('success', 'Candidate updated.');
        }
        header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
    }

    if ($action === 'nominate') {
        $electionId = (int)($_POST['election_id'] ?? 0);
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $manifesto = trim($_POST['manifesto'] ?? '');
        $election = db_fetch_one($mysqli, "SELECT id, title, status FROM elections WHERE id=?", "i", [$electionId]);
        $candidate = db_fetch_one($mysqli, "SELECT id FROM candidates WHERE id=?", "i", [$candidateId]);
        if (!$election || !$candidate || !in_array($election['status'], ['draft', 'scheduled'], true)) {
            set_flash('danger', 'Choose an existing candidate and an election that has not started.');
            header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
        }
        if (db_fetch_one($mysqli, "SELECT id FROM election_candidates WHERE election_id=? AND candidate_id=?", "ii", [$electionId, $candidateId])) {
            set_flash('danger', 'That candidate is already nominated for this election.');
            header('Location: /VOTING_SYSTEM/admin/candidates.php?election_id=' . $electionId); exit;
        }
        db_execute(
            $mysqli,
            "INSERT INTO election_candidates (election_id, candidate_id, manifesto, status) VALUES (?,?,?,'pending')",
            "iis",
            [$electionId, $candidateId, $manifesto !== '' ? $manifesto : null]
        );
        audit_log(current_user()['id'], 'candidate_nominate', 'election', $electionId, "Nominated candidate $candidateId");
        set_flash('success', 'Candidate nominated and awaiting approval.');
        header('Location: /VOTING_SYSTEM/admin/candidates.php?election_id=' . $electionId); exit;
    }

    if ($action === 'review_nomination') {
        $nominationId = (int)($_POST['nomination_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $reason = clean_string(trim($_POST['rejection_reason'] ?? ''), 255);
        $nomination = db_fetch_one(
            $mysqli,
            "SELECT ec.id, ec.election_id, ec.status, e.status AS election_status
             FROM election_candidates ec JOIN elections e ON e.id=ec.election_id
             WHERE ec.id=?",
            "i",
            [$nominationId]
        );
        if (!$nomination || !in_array($nomination['election_status'], ['draft', 'scheduled'], true) ||
            $nomination['status'] !== 'pending' || !in_array($decision, ['approved', 'rejected'], true) ||
            ($decision === 'rejected' && $reason === '')) {
            set_flash('danger', 'This nomination cannot be reviewed with the submitted details.');
            header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
        }

        if ($decision === 'approved') {
            $updated = db_execute(
                $mysqli,
                "UPDATE election_candidates SET status='approved', approved_by=?, approved_at=NOW(), rejection_reason=NULL
                 WHERE id=? AND status='pending'
                   AND EXISTS (
                       SELECT 1 FROM elections e
                       WHERE e.id=election_candidates.election_id AND e.status IN ('draft','scheduled')
                   )",
                "ii",
                [current_user()['id'], $nominationId]
            );
        } else {
            $updated = db_execute(
                $mysqli,
                "UPDATE election_candidates SET status='rejected', approved_by=?, approved_at=NULL, rejection_reason=?
                 WHERE id=? AND status='pending'
                   AND EXISTS (
                       SELECT 1 FROM elections e
                       WHERE e.id=election_candidates.election_id AND e.status IN ('draft','scheduled')
                   )",
                "isi",
                [current_user()['id'], $reason, $nominationId]
            );
        }
        if ($updated !== 1) {
            set_flash('warning', 'This nomination or election changed before the review was saved. Refresh and review the current status.');
            header('Location: /VOTING_SYSTEM/admin/candidates.php?election_id=' . (int)$nomination['election_id']);
            exit;
        }
        audit_log(current_user()['id'], 'candidate_nomination_review', 'election', (int)$nomination['election_id'], "$decision nomination $nominationId");
        set_flash('success', 'Nomination ' . $decision . '.');
        header('Location: /VOTING_SYSTEM/admin/candidates.php?election_id=' . (int)$nomination['election_id']); exit;
    }
}

$candidateRows = db_fetch_all(
    $mysqli,
    "SELECT c.id, c.full_name, c.bio, c.created_at,
            NOT EXISTS (
                SELECT 1 FROM election_candidates ec
                JOIN elections e ON e.id=ec.election_id
                WHERE ec.candidate_id=c.id AND e.status NOT IN ('draft','scheduled')
            ) AS is_editable
     FROM candidates c ORDER BY c.full_name, c.id"
);
$elections = db_fetch_all(
    $mysqli,
    "SELECT id, title, status FROM elections WHERE status IN ('draft','scheduled') ORDER BY start_at, id"
);
$allElections = db_fetch_all($mysqli, "SELECT id, title FROM elections ORDER BY start_at DESC, id DESC");
$selectedElectionId = max(0, (int)($_GET['election_id'] ?? 0));
$selectedElectionExists = false;
foreach ($allElections as $availableElection) {
    if ((int)$availableElection['id'] === $selectedElectionId) $selectedElectionExists = true;
}
if (!$selectedElectionExists) $selectedElectionId = 0;

$nominationSql = "SELECT ec.id, ec.election_id, ec.status, ec.manifesto, ec.rejection_reason,
                         ec.created_at, ec.position_order, e.title AS election_title,
                         e.status AS election_status, c.id AS candidate_id, c.full_name
                  FROM election_candidates ec
                  JOIN elections e ON e.id=ec.election_id
                  JOIN candidates c ON c.id=ec.candidate_id";
$nominationParams = [];
$nominationTypes = '';
if ($selectedElectionId > 0) {
    $nominationSql .= " WHERE ec.election_id=?";
    $nominationTypes = 'i';
    $nominationParams[] = $selectedElectionId;
}
$nominationSql .= " ORDER BY e.start_at DESC, e.id DESC, ec.position_order, c.full_name";
$nominations = db_fetch_all($mysqli, $nominationSql, $nominationTypes, $nominationParams);

$editId = max(0, (int)($_GET['edit'] ?? 0));
$editCandidate = null;
if ($editId > 0) {
    $editCandidate = db_fetch_one(
        $mysqli,
        "SELECT c.id, c.full_name, c.bio FROM candidates c
         WHERE c.id=?
           AND NOT EXISTS (
               SELECT 1 FROM election_candidates ec
               JOIN elections e ON e.id=ec.election_id
               WHERE ec.candidate_id=c.id AND e.status NOT IN ('draft','scheduled')
           )",
        "i",
        [$editId]
    );
    if (!$editCandidate) {
        set_flash('danger', 'Candidate not found.');
        header('Location: /VOTING_SYSTEM/admin/candidates.php'); exit;
    }
}

$pageTitle = 'Manage Candidates';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2><?php echo $editCandidate ? 'Edit Candidate' : 'Candidates'; ?></h2>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $editCandidate ? 'update_candidate' : 'create_candidate'; ?>">
                <?php if ($editCandidate): ?>
                    <input type="hidden" name="candidate_id" value="<?php echo (int)$editCandidate['id']; ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <input class="form-control" id="full_name" name="full_name" maxlength="120" value="<?php echo e($editCandidate['full_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="bio">Biography</label>
                    <textarea class="form-control" id="bio" name="bio" rows="3"><?php echo e($editCandidate['bio'] ?? ''); ?></textarea>
                </div>
                <button class="btn btn--primary" type="submit"><?php echo $editCandidate ? 'Save candidate' : 'Add candidate'; ?></button>
                <?php if ($editCandidate): ?><a class="btn" href="/VOTING_SYSTEM/admin/candidates.php">Cancel</a><?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3>Nominate a candidate</h3>
            <?php if ($elections && $candidateRows): ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="nominate">
                    <div class="grid grid--3">
                        <div class="form-group">
                            <label for="election_id">Election</label>
                            <select class="form-control" id="election_id" name="election_id" required>
                                <option value="">Select an election</option>
                                <?php foreach ($elections as $item): ?>
                                    <option value="<?php echo (int)$item['id']; ?>" <?php echo $selectedElectionId === (int)$item['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($item['title']); ?> (<?php echo e($item['status']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="candidate_id">Candidate</label>
                            <select class="form-control" id="candidate_id" name="candidate_id" required>
                                <option value="">Select a candidate</option>
                                <?php foreach ($candidateRows as $candidate): ?>
                                    <option value="<?php echo (int)$candidate['id']; ?>"><?php echo e($candidate['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="manifesto">Manifesto</label>
                            <textarea class="form-control" id="manifesto" name="manifesto" rows="2"></textarea>
                        </div>
                    </div>
                    <button class="btn btn--primary" type="submit">Submit nomination</button>
                </form>
            <?php elseif (!$elections): ?>
                <p>Create a draft or scheduled election before nominating candidates.</p>
            <?php else: ?>
                <p>Add a candidate before submitting a nomination.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Candidate directory</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Biography</th><th>Added</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($candidateRows as $candidate): ?>
                        <tr>
                            <td><?php echo e($candidate['full_name']); ?></td>
                            <td><?php echo nl2br(e($candidate['bio'] ?? '')); ?></td>
                            <td><?php echo e($candidate['created_at']); ?></td>
                            <td>
                                <?php if ($candidate['is_editable']): ?>
                                    <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/candidates.php?edit=<?php echo (int)$candidate['id']; ?>">Edit</a>
                                <?php else: ?>
                                    Locked after election start
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$candidateRows): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);">No candidates have been added.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Election nominations</h3>
            <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:12px;">
                <div class="form-group" style="min-width:220px;margin-bottom:0;">
                    <label for="filter_election">Filter by election</label>
                    <select class="form-control" id="filter_election" name="election_id">
                        <option value="">All elections</option>
                        <?php foreach ($allElections as $item): ?>
                            <option value="<?php echo (int)$item['id']; ?>" <?php echo $selectedElectionId === (int)$item['id'] ? 'selected' : ''; ?>><?php echo e($item['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn" type="submit">Filter</button>
            </form>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Election</th><th>Candidate</th><th>Manifesto</th><th>Status</th><th>Review</th></tr></thead>
                    <tbody>
                    <?php foreach ($nominations as $nomination): ?>
                        <tr>
                            <td><?php echo e($nomination['election_title']); ?></td>
                            <td><?php echo e($nomination['full_name']); ?></td>
                            <td><?php echo nl2br(e($nomination['manifesto'] ?? '')); ?></td>
                            <td>
                                <span class="badge badge--<?php echo e($nomination['status'] === 'approved' ? 'active' : ($nomination['status'] === 'rejected' ? 'closed' : 'draft')); ?>">
                                    <?php echo e(ucfirst($nomination['status'])); ?>
                                </span>
                                <?php if ($nomination['rejection_reason']): ?><br><?php echo e($nomination['rejection_reason']); ?><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($nomination['status'] === 'pending' && in_array($nomination['election_status'], ['draft', 'scheduled'], true)): ?>
                                    <form method="post" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="review_nomination">
                                        <input type="hidden" name="nomination_id" value="<?php echo (int)$nomination['id']; ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <button class="btn btn--sm btn--primary" type="submit">Approve</button>
                                    </form>
                                    <form method="post" style="margin-top:4px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="review_nomination">
                                        <input type="hidden" name="nomination_id" value="<?php echo (int)$nomination['id']; ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <input class="form-control" name="rejection_reason" maxlength="255" placeholder="Reason required" aria-label="Rejection reason" required>
                                        <button class="btn btn--sm btn--danger" type="submit">Reject</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$nominations): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);">No nominations found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

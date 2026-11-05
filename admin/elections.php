<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);
$active = 'elections';
global $mysqli;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$view = $_GET['view'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
    }
    if ($action === 'create') {
        $title = clean_string($_POST['title'] ?? '', 150);
        $desc = trim($_POST['description'] ?? '');
        $start = str_replace('T', ' ', trim($_POST['start_at'] ?? ''));
        $end = str_replace('T', ' ', trim($_POST['end_at'] ?? ''));
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        $mode = $_POST['eligibility_mode'] ?? 'all_voters';
        if ($title === '' || $startTime === false || $endTime === false) {
            set_flash('danger', 'Title, start and end are required.');
            header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
        }
        if ($endTime <= $startTime) {
            set_flash('danger', 'End must be after start.');
            header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
        }
        if (!in_array($mode, ['all_voters','selected'], true)) $mode = 'all_voters';
        $status = 'scheduled';
        db_execute($mysqli, "INSERT INTO elections (title, description, start_at, end_at, eligibility_mode, status, created_by) VALUES (?,?,?,?,?,?,?)", "ssssssi", [$title, $desc ?: null, $start, $end, $mode, $status, current_user()['id']]);
        $eid = $mysqli->insert_id;
        audit_log(current_user()['id'], 'election_create', 'election', $eid, "Created election $title");
        set_flash('success', 'Election created.');
        header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
    }
    if ($action === 'update') {
        $eid = (int)($_POST['election_id'] ?? 0);
        $title = clean_string($_POST['title'] ?? '', 150);
        $desc = trim($_POST['description'] ?? '');
        $start = str_replace('T', ' ', trim($_POST['start_at'] ?? ''));
        $end = str_replace('T', ' ', trim($_POST['end_at'] ?? ''));
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        $mode = $_POST['eligibility_mode'] ?? 'all_voters';
        if ($eid <= 0 || $title === '' || $startTime === false || $endTime === false) {
            set_flash('danger', 'Invalid data.'); header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
        }
        if ($endTime <= $startTime) {
            set_flash('danger', 'End must be after start.'); header('Location: /VOTING_SYSTEM/admin/elections.php?view=edit&id=' . $eid); exit;
        }
        if (!in_array($mode, ['all_voters','selected'], true)) $mode = 'all_voters';
        $mysqli->begin_transaction();
        try {
            $existing = db_fetch_one($mysqli, "SELECT status FROM elections WHERE id=? FOR UPDATE", "i", [$eid]);
            if (!$existing || !in_array($existing['status'], ['draft', 'scheduled'], true)) {
                $mysqli->rollback();
                set_flash('danger', 'Only draft or scheduled elections can be edited.');
                header('Location: /VOTING_SYSTEM/admin/elections.php');
                exit;
            }
            db_execute(
                $mysqli,
                "UPDATE elections
                 SET title=?, description=?, start_at=?, end_at=?, eligibility_mode=?,
                     status=IF(status='draft','scheduled',status)
                 WHERE id=? AND status IN ('draft','scheduled')",
                "sssssi",
                [$title, $desc ?: null, $start, $end, $mode, $eid]
            );
            $mysqli->commit();
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Election update failed: ' . $t->getMessage());
            set_flash('danger', 'Unable to update the election. Please try again.');
            header('Location: /VOTING_SYSTEM/admin/elections.php?view=edit&id=' . $eid);
            exit;
        }
        audit_log(current_user()['id'], 'election_update', 'election', $eid, "Updated election $eid");
        set_flash('success', 'Election updated.');
        header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
    }
    if ($action === 'delete') {
        $eid = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
        if ($eid === false || $eid <= 0) {
            set_flash('danger', 'Choose a valid election to delete.');
            header('Location: /VOTING_SYSTEM/admin/elections.php');
            exit;
        }

        $mysqli->begin_transaction();
        try {
            $electionToDelete = db_fetch_one(
                $mysqli,
                "SELECT id, title, status FROM elections WHERE id=? FOR UPDATE",
                "i",
                [$eid]
            );
            if (!$electionToDelete) {
                throw new DomainException('Election not found.');
            }
            if ($electionToDelete['status'] === 'active') {
                throw new DomainException('An active election cannot be deleted. Close or cancel it first.');
            }

            db_execute($mysqli, "DELETE FROM ballots WHERE election_id=?", "i", [$eid]);
            $deleted = db_execute(
                $mysqli,
                "DELETE FROM elections WHERE id=? AND status<>'active'",
                "i",
                [$eid]
            );
            if ($deleted !== 1) {
                throw new RuntimeException('Election was not deleted; it may have changed.');
            }
            $mysqli->commit();
        } catch (DomainException $t) {
            $mysqli->rollback();
            set_flash('danger', $t->getMessage());
            header('Location: /VOTING_SYSTEM/admin/elections.php');
            exit;
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Election deletion failed: ' . $t->getMessage());
            set_flash('danger', 'Unable to delete the election. It may have related ballot records.');
            header('Location: /VOTING_SYSTEM/admin/elections.php');
            exit;
        }
        audit_log((int)current_user()['id'], 'election_delete', 'election', (int)$eid, 'Deleted election: ' . $electionToDelete['title']);
        set_flash('success', 'Election deleted.');
        header('Location: /VOTING_SYSTEM/admin/elections.php');
        exit;
    }
    if ($action === 'transition') {
        $eid = (int)($_POST['election_id'] ?? 0);
        $to = $_POST['to'] ?? '';
        $e = db_fetch_one($mysqli, "SELECT * FROM elections WHERE id=?", "i", [$eid]);
        if (!$e) { set_flash('danger', 'Election not found.'); header('Location: /VOTING_SYSTEM/admin/elections.php'); exit; }
        $from = $e['status'];
        $ok = false;
        if ($from === 'draft' && $to === 'scheduled') $ok = true;
        if ($from === 'scheduled' && $to === 'active') $ok = true;
        if ($from === 'active' && $to === 'closed') $ok = true;
        if ($from === 'closed' && $to === 'published') $ok = true;
        if (in_array($from, ['draft', 'scheduled', 'active', 'closed'], true) && $to === 'cancelled') $ok = true;
        if (!$ok) { set_flash('danger', "Transition $from → $to not allowed."); header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit; }
        if ($to === 'active' && (strtotime($e['start_at']) > time() || strtotime($e['end_at']) <= time())) {
            set_flash('danger', 'An election can only start during its scheduled voting period.');
            header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
        }
        if ($to === 'active' && $e['eligibility_mode'] === 'selected') {
            $eligible = db_fetch_one($mysqli, "SELECT COUNT(*) AS c FROM voter_elections WHERE election_id=? AND is_eligible=1", "i", [$eid]);
            if ((int)($eligible['c'] ?? 0) === 0) {
                set_flash('danger', 'Select at least one eligible voter before starting this election.');
                header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
            }
        }
        if ($to === 'active') {
            $approvedCandidates = db_fetch_one(
                $mysqli,
                "SELECT COUNT(*) AS c FROM election_candidates WHERE election_id=? AND status='approved'",
                "i",
                [$eid]
            );
            if ((int)($approvedCandidates['c'] ?? 0) === 0) {
                set_flash('danger', 'Approve at least one candidate before starting this election.');
                header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
            }
            $pendingNominations = db_fetch_one(
                $mysqli,
                "SELECT COUNT(*) AS c FROM election_candidates WHERE election_id=? AND status='pending'",
                "i",
                [$eid]
            );
            if ((int)($pendingNominations['c'] ?? 0) > 0) {
                set_flash('danger', 'Review all pending candidate nominations before starting this election.');
                header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
            }
        }
        $mysqli->begin_transaction();
        try {
            if ($to === 'active' && $e['eligibility_mode'] === 'all_voters') {
                $voters = db_fetch_all($mysqli, "SELECT id FROM voters");
                foreach ($voters as $v) {
                    db_execute(
                        $mysqli,
                        "INSERT INTO voter_elections (voter_id, election_id, is_eligible) VALUES (?,?,1)
                         ON DUPLICATE KEY UPDATE is_eligible=1",
                        "ii",
                        [$v['id'], $eid]
                    );
                }
            }
            db_execute($mysqli, "UPDATE elections SET status=? WHERE id=?", "si", [$to, $eid]);
            if ($to === 'published') {
                db_execute($mysqli, "UPDATE elections SET published_at=NOW(), published_by=?, results_public=1 WHERE id=?", "ii", [current_user()['id'], $eid]);
            }
            $mysqli->commit();
        } catch (Throwable $t) {
            $mysqli->rollback(); error_log($t->getMessage());
            set_flash('danger', 'Transition failed.'); header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
        }
        audit_log(current_user()['id'], 'election_transition', 'election', $eid, "$from → $to");
        set_flash('success', "Election moved to $to.");
        header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
    }
    if ($action === 'set_eligibility') {
        $eid = (int)($_POST['election_id'] ?? 0);
        $e = db_fetch_one($mysqli, "SELECT id, status, eligibility_mode FROM elections WHERE id=?", "i", [$eid]);
        if (!$e || $e['eligibility_mode'] !== 'selected' || !in_array($e['status'], ['draft', 'scheduled'], true)) {
            set_flash('danger', 'Voter eligibility can only be changed for a selected-voter election that has not started.');
            header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
        }

        $postedVoterIds = $_POST['voter_ids'] ?? [];
        if (!is_array($postedVoterIds)) {
            set_flash('danger', 'Invalid voter selection.');
            header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
        }
        $selectedIds = [];
        foreach ($postedVoterIds as $postedId) {
            $voterId = filter_var($postedId, FILTER_VALIDATE_INT);
            if ($voterId === false || $voterId <= 0) {
                set_flash('danger', 'Invalid voter selection.');
                header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
            }
            $selectedIds[] = $voterId;
        }
        $selectedIds = array_values(array_unique($selectedIds));
        $validVoters = db_fetch_all($mysqli, "SELECT id FROM voters");
        $validIds = array_map('intval', array_column($validVoters, 'id'));
        if (array_diff($selectedIds, $validIds)) {
            set_flash('danger', 'One or more selected voters are invalid.');
            header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
        }

        $mysqli->begin_transaction();
        try {
            db_execute($mysqli, "DELETE FROM voter_elections WHERE election_id=?", "i", [$eid]);
            foreach ($selectedIds as $voterId) {
                db_execute($mysqli, "INSERT INTO voter_elections (voter_id, election_id, is_eligible) VALUES (?,?,1)", "ii", [$voterId, $eid]);
            }
            $mysqli->commit();
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Election voter eligibility update failed: ' . $t->getMessage());
            set_flash('danger', 'Failed to save voter eligibility.');
            header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
        }
        audit_log(current_user()['id'], 'election_eligibility_update', 'election', $eid, 'Updated selected voters');
        set_flash('success', 'Eligible voters saved.');
        header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid); exit;
    }
    if ($action === 'assign_manager' || $action === 'remove_manager') {
        $eid = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
        $managerId = filter_var($_POST['manager_id'] ?? null, FILTER_VALIDATE_INT);
        $election = $eid !== false && $eid > 0
            ? db_fetch_one($mysqli, "SELECT id FROM elections WHERE id=?", "i", [$eid])
            : null;
        if (!$election || $managerId === false || $managerId <= 0) {
            set_flash('danger', 'Choose a valid election manager.');
            header('Location: /VOTING_SYSTEM/admin/elections.php');
            exit;
        }

        if ($action === 'assign_manager') {
            $manager = db_fetch_one(
                $mysqli,
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE u.id=? AND r.name='manager' AND u.status='active'",
                "i",
                [$managerId]
            );
            if (!$manager) {
                set_flash('danger', 'Only active manager accounts can be assigned.');
                header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid);
                exit;
            }
            $alreadyAssigned = db_fetch_one(
                $mysqli,
                "SELECT id FROM election_managers WHERE user_id=? AND election_id=?",
                "ii",
                [$managerId, $eid]
            );
            if ($alreadyAssigned) {
                set_flash('warning', 'That manager is already assigned to this election.');
            } else {
                db_execute(
                    $mysqli,
                    "INSERT INTO election_managers (user_id, election_id, assigned_by) VALUES (?,?,?)",
                    "iii",
                    [$managerId, $eid, (int)current_user()['id']]
                );
                audit_log((int)current_user()['id'], 'election_manager_assign', 'election', $eid, "Assigned manager $managerId");
                set_flash('success', 'Manager assigned to the election.');
            }
        } else {
            $removed = db_execute(
                $mysqli,
                "DELETE FROM election_managers WHERE user_id=? AND election_id=?",
                "ii",
                [$managerId, $eid]
            );
            if ($removed === 1) {
                audit_log((int)current_user()['id'], 'election_manager_remove', 'election', $eid, "Removed manager $managerId");
                set_flash('success', 'Manager assignment removed.');
            } else {
                set_flash('danger', 'That manager is not assigned to this election.');
            }
        }
        header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $eid);
        exit;
    }
}

$view = in_array($view, ['list', 'edit', 'manage'], true) ? $view : 'list';
$electionId = (int)($_GET['id'] ?? 0);
$election = null;
$electionBallotCount = 0;
$voters = [];
$eligibleIds = [];
$elections = [];
$assignedManagers = [];
$availableManagers = [];

if ($view === 'edit' || $view === 'manage') {
    $election = db_fetch_one($mysqli, "SELECT e.*, u.full_name AS creator_name FROM elections e JOIN users u ON u.id=e.created_by WHERE e.id=?", "i", [$electionId]);
    if (!$election) {
        set_flash('danger', 'Election not found.');
        header('Location: /VOTING_SYSTEM/admin/elections.php'); exit;
    }
    $ballotCountRow = db_fetch_one(
        $mysqli,
        "SELECT COUNT(*) AS ballot_count FROM ballots WHERE election_id=?",
        "i",
        [$electionId]
    );
    $electionBallotCount = (int)($ballotCountRow['ballot_count'] ?? 0);
}

if ($view === 'manage') {
    $assignedManagers = db_fetch_all(
        $mysqli,
        "SELECT u.id, u.full_name, u.email, u.status
         FROM election_managers em
         JOIN users u ON u.id=em.user_id
         WHERE em.election_id=?
         ORDER BY u.full_name, u.id",
        "i",
        [$electionId]
    );
    $availableManagers = db_fetch_all(
        $mysqli,
        "SELECT u.id, u.full_name, u.email
         FROM users u
         JOIN roles r ON r.id=u.role_id
         WHERE r.name='manager' AND u.status='active'
           AND NOT EXISTS (
               SELECT 1 FROM election_managers em
               WHERE em.election_id=? AND em.user_id=u.id
           )
         ORDER BY u.full_name, u.id",
        "i",
        [$electionId]
    );
}

if ($view === 'edit' && !in_array($election['status'], ['draft', 'scheduled'], true)) {
    set_flash('danger', 'Only draft or scheduled elections can be edited.');
    header('Location: /VOTING_SYSTEM/admin/elections.php?view=manage&id=' . $electionId); exit;
}

$autoStartBlockedReason = '';
if ($view === 'manage' && $election['status'] === 'scheduled' &&
    strtotime($election['start_at']) <= time() && strtotime($election['end_at']) > time()) {
    $approvedCandidates = db_fetch_one(
        $mysqli,
        "SELECT COUNT(*) AS c FROM election_candidates WHERE election_id=? AND status='approved'",
        "i",
        [$electionId]
    );
    if ((int)($approvedCandidates['c'] ?? 0) === 0) {
        $autoStartBlockedReason = 'Approve at least one candidate to allow the election to start.';
    } elseif ($election['eligibility_mode'] === 'selected') {
        $eligibleVoters = db_fetch_one(
            $mysqli,
            "SELECT COUNT(*) AS c FROM voter_elections WHERE election_id=? AND is_eligible=1",
            "i",
            [$electionId]
        );
        if ((int)($eligibleVoters['c'] ?? 0) === 0) {
            $autoStartBlockedReason = 'Select at least one eligible voter to allow the election to start.';
        }
    }
    if ($autoStartBlockedReason === '') {
        $pendingNominations = db_fetch_one(
            $mysqli,
            "SELECT COUNT(*) AS c FROM election_candidates WHERE election_id=? AND status='pending'",
            "i",
            [$electionId]
        );
        if ((int)($pendingNominations['c'] ?? 0) > 0) {
            $autoStartBlockedReason = 'Review all pending candidate nominations to allow the election to start.';
        }
    }
}

if ($view === 'manage' && $election['eligibility_mode'] === 'selected') {
    $voters = db_fetch_all(
        $mysqli,
        "SELECT v.id, v.voter_card_no, u.full_name, u.email
         FROM voters v JOIN users u ON u.id=v.user_id
         ORDER BY u.full_name, v.id"
    );
    $eligibleRows = db_fetch_all($mysqli, "SELECT voter_id FROM voter_elections WHERE election_id=? AND is_eligible=1", "i", [$electionId]);
    $eligibleIds = array_map('intval', array_column($eligibleRows, 'voter_id'));
}

if ($view === 'list') {
    $elections = db_fetch_all(
        $mysqli,
        "SELECT e.id, e.title, e.start_at, e.end_at, e.status, e.eligibility_mode,
                (SELECT COUNT(*) FROM election_candidates ec WHERE ec.election_id=e.id) AS candidate_count,
                (SELECT COUNT(*) FROM ballots b WHERE b.election_id=e.id) AS ballot_count
         FROM elections e ORDER BY e.created_at DESC, e.id DESC"
    );
}

$pageTitle = 'Manage Elections';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <?php if ($view === 'edit'): ?>
            <div class="card">
                <h2>Edit Election #<?php echo (int)$election['id']; ?></h2>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input class="form-control" id="title" name="title" maxlength="150" value="<?php echo e($election['title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo e($election['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="grid grid--3">
                        <div class="form-group">
                            <label for="start_at">Starts</label>
                            <input class="form-control" id="start_at" type="datetime-local" name="start_at" value="<?php echo e(date('Y-m-d\TH:i', strtotime($election['start_at']))); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_at">Ends</label>
                            <input class="form-control" id="end_at" type="datetime-local" name="end_at" value="<?php echo e(date('Y-m-d\TH:i', strtotime($election['end_at']))); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="eligibility_mode">Eligibility</label>
                            <select class="form-control" id="eligibility_mode" name="eligibility_mode">
                                <option value="all_voters" <?php echo $election['eligibility_mode'] === 'all_voters' ? 'selected' : ''; ?>>All voters</option>
                                <option value="selected" <?php echo $election['eligibility_mode'] === 'selected' ? 'selected' : ''; ?>>Selected voters</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn--primary" type="submit">Save changes</button>
                    <a class="btn" href="/VOTING_SYSTEM/admin/elections.php?view=manage&id=<?php echo (int)$election['id']; ?>">Cancel</a>
                </form>
            </div>
        <?php elseif ($view === 'manage'): ?>
            <div class="card">
                <h2><?php echo e($election['title']); ?></h2>
                <?php if ($autoStartBlockedReason !== ''): ?>
                    <p class="flash flash--warning"><?php echo e($autoStartBlockedReason); ?> Its scheduled start time has passed; it will start automatically once ready.</p>
                <?php endif; ?>
                <p><?php echo nl2br(e($election['description'] ?? 'No description provided.')); ?></p>
                <p>
                    <strong>Status:</strong>
                    <span class="badge badge--<?php echo e($election['status']); ?>"><?php echo e(ucfirst($election['status'])); ?></span>
                    &nbsp; <strong>Eligibility:</strong> <?php echo $election['eligibility_mode'] === 'all_voters' ? 'All voters' : 'Selected voters'; ?>
                </p>
                <p><strong>Starts:</strong> <?php echo e($election['start_at']); ?> &nbsp; <strong>Ends:</strong> <?php echo e($election['end_at']); ?></p>
                <p><strong>Created by:</strong> <?php echo e($election['creator_name']); ?></p>
                <?php if (in_array($election['status'], ['draft', 'scheduled'], true)): ?>
                    <a class="btn" href="/VOTING_SYSTEM/admin/elections.php?view=edit&id=<?php echo (int)$election['id']; ?>">Edit election</a>
                    <a class="btn" href="/VOTING_SYSTEM/admin/candidates.php?election_id=<?php echo (int)$election['id']; ?>">Manage nominations</a>
                    <?php if ($electionBallotCount === 0): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this election and its nominations, eligibility selections, and manager assignments?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                            <button class="btn btn--danger" type="submit">Delete election</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <a class="btn" href="/VOTING_SYSTEM/admin/elections.php">All elections</a>
            </div>

            <?php if ($election['eligibility_mode'] === 'selected'): ?>
                <div class="card">
                    <h3>Eligible voters</h3>
                    <?php if (in_array($election['status'], ['draft', 'scheduled'], true)): ?>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_eligibility">
                            <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Include</th><th>Voter card</th><th>Name</th><th>Email</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($voters as $voter): ?>
                                        <tr>
                                            <td><input type="checkbox" name="voter_ids[]" value="<?php echo (int)$voter['id']; ?>" aria-label="Include <?php echo e($voter['full_name']); ?>" <?php echo in_array((int)$voter['id'], $eligibleIds, true) ? 'checked' : ''; ?>></td>
                                            <td><?php echo e($voter['voter_card_no']); ?></td>
                                            <td><?php echo e($voter['full_name']); ?></td>
                                            <td><?php echo e($voter['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$voters): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);">No voters are registered.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p><button class="btn btn--primary" type="submit">Save eligible voters</button></p>
                        </form>
                    <?php else: ?>
                        <p>This election has started; voter eligibility is locked.</p>
                        <ul>
                            <?php foreach ($voters as $voter): ?>
                                <?php if (in_array((int)$voter['id'], $eligibleIds, true)): ?>
                                    <li><?php echo e($voter['full_name']); ?> (<?php echo e($voter['voter_card_no']); ?>)</li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>Election status</h3>
                <?php
                $nextStatus = [
                    'draft' => 'scheduled',
                    'scheduled' => 'active',
                    'active' => 'closed',
                    'closed' => 'published',
                ][$election['status']] ?? null;
                ?>
                <?php if (in_array($election['status'], ['draft', 'scheduled', 'active', 'closed'], true)): ?>
                    <?php if ($nextStatus): ?>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="transition">
                            <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                            <input type="hidden" name="to" value="<?php echo e($nextStatus); ?>">
                            <button class="btn btn--primary" type="submit">Move to <?php echo e(ucfirst($nextStatus)); ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this election?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="transition">
                        <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                        <input type="hidden" name="to" value="cancelled">
                        <button class="btn btn--danger" type="submit">Cancel election</button>
                    </form>
                <?php endif; ?>
                <?php if ($election['status'] === 'published'): ?>
                    <p>Results have been published. <a href="/VOTING_SYSTEM/results/index.php?election_id=<?php echo (int)$election['id']; ?>">View public results</a></p>
                <?php endif; ?>
                <?php if ($election['status'] !== 'active'): ?>
                    <div class="flash flash--warning">
                        Deleting this election permanently removes its ballots, results, nominations, eligibility records, and manager assignments. The deletion is recorded in the audit log and cannot be undone.
                    </div>
                    <form method="post" onsubmit="return confirm('Permanently delete this election and ALL associated ballots and results? This cannot be undone.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                        <button class="btn btn--danger" type="submit">Permanently delete election</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card">
                <h3>Assigned managers</h3>
                <?php if ($assignedManagers): ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Name</th><th>Email</th><th>Account status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($assignedManagers as $manager): ?>
                                <tr>
                                    <td><?php echo e($manager['full_name']); ?></td>
                                    <td><?php echo e($manager['email']); ?></td>
                                    <td><?php echo e(ucfirst($manager['status'])); ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Remove this manager from the election?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove_manager">
                                            <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                                            <input type="hidden" name="manager_id" value="<?php echo (int)$manager['id']; ?>">
                                            <button class="btn btn--sm btn--danger" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No managers are assigned to this election.</p>
                <?php endif; ?>
                <?php if ($availableManagers): ?>
                    <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="assign_manager">
                        <input type="hidden" name="election_id" value="<?php echo (int)$election['id']; ?>">
                        <div class="form-group" style="min-width:240px;margin:0;">
                            <label for="manager_id">Active manager</label>
                            <select class="form-control" id="manager_id" name="manager_id" required>
                                <option value="">Choose a manager</option>
                                <?php foreach ($availableManagers as $manager): ?>
                                    <option value="<?php echo (int)$manager['id']; ?>"><?php echo e($manager['full_name']); ?> (<?php echo e($manager['email']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn--primary" type="submit">Assign manager</button>
                    </form>
                <?php else: ?>
                    <p>No unassigned active manager accounts are available.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Elections</h2>
                <p>Create elections, manage voter eligibility, and progress each election through its lifecycle.</p>
                <details>
                    <summary class="btn btn--primary" style="display:inline-block;margin-bottom:12px;">Create election</summary>
                    <form method="post" style="margin-top:16px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create">
                        <div class="form-group">
                            <label for="new_title">Title</label>
                            <input class="form-control" id="new_title" name="title" maxlength="150" required>
                        </div>
                        <div class="form-group">
                            <label for="new_description">Description</label>
                            <textarea class="form-control" id="new_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="grid grid--3">
                            <div class="form-group">
                                <label for="new_start_at">Starts</label>
                                <input class="form-control" id="new_start_at" type="datetime-local" name="start_at" required>
                            </div>
                            <div class="form-group">
                                <label for="new_end_at">Ends</label>
                                <input class="form-control" id="new_end_at" type="datetime-local" name="end_at" required>
                            </div>
                            <div class="form-group">
                                <label for="new_eligibility_mode">Eligibility</label>
                                <select class="form-control" id="new_eligibility_mode" name="eligibility_mode">
                                    <option value="all_voters">All voters</option>
                                    <option value="selected">Selected voters</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn--primary" type="submit">Create election</button>
                    </form>
                </details>
            </div>
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Eligibility</th><th>Schedule</th><th>Candidates</th><th>Ballots</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($elections as $item): ?>
                            <tr>
                                <td><?php echo (int)$item['id']; ?></td>
                                <td><?php echo e($item['title']); ?></td>
                                <td><span class="badge badge--<?php echo e($item['status']); ?>"><?php echo e(ucfirst($item['status'])); ?></span></td>
                                <td><?php echo $item['eligibility_mode'] === 'all_voters' ? 'All voters' : 'Selected voters'; ?></td>
                                <td><?php echo e($item['start_at']); ?><br>to <?php echo e($item['end_at']); ?></td>
                                <td><?php echo (int)$item['candidate_count']; ?></td>
                                <td><?php echo (int)$item['ballot_count']; ?></td>
                                <td style="white-space:nowrap;">
                                    <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/elections.php?view=manage&id=<?php echo (int)$item['id']; ?>">Manage</a>
                                    <?php if (in_array($item['status'], ['draft', 'scheduled'], true)): ?>
                                        <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/elections.php?view=edit&amp;id=<?php echo (int)$item['id']; ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($item['status'] !== 'active'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this election and ALL associated ballots and results? This cannot be undone.');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="election_id" value="<?php echo (int)$item['id']; ?>">
                                            <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$elections): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);">No elections yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

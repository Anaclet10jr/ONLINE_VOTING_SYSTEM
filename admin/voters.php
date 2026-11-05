<?php
require_once __DIR__ . '/../includes/header.php';
require_role(['admin']);
$active = 'voters';
global $mysqli;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
    if ($action === 'create') {
        $name = clean_string($_POST['full_name'] ?? '', 120);
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($name === '' || !is_valid_email($email) || !is_strong_password($password)) {
            set_flash('danger', 'Invalid input or weak password.');
            header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }
        if (db_fetch_one($mysqli, "SELECT id FROM users WHERE email = ?", "s", [$email])) {
            set_flash('danger', 'Email already exists.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }
        $role = db_fetch_one($mysqli, "SELECT id FROM roles WHERE name='voter'", "", []);
        if (!$role) { set_flash('danger', 'Role missing.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
        $mysqli->begin_transaction();
        try {
            db_execute($mysqli, "INSERT INTO users (role_id, full_name, email, password_hash, status) VALUES (?,?,?,?, 'active')", "isss", [$role['id'], $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $uid = $mysqli->insert_id;
            $cardNo = 'V' . date('Y') . str_pad((string)$uid, 6, '0', STR_PAD_LEFT);
            db_execute($mysqli, "INSERT INTO voters (user_id, voter_card_no, is_verified) VALUES (?,?,1)", "is", [$uid, $cardNo]);
            $mysqli->commit();
            audit_log(current_user()['id'], 'voter_create', 'voter', $uid, "Created voter $email");
            set_flash('success', 'Voter created and verified.');
        } catch (Throwable $t) {
            $mysqli->rollback(); error_log($t->getMessage());
            set_flash('danger', 'Failed to create voter.');
        }
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
    if ($action === 'link') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0) { set_flash('danger', 'Invalid user.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
        $u = db_fetch_one($mysqli, "SELECT id, full_name FROM users WHERE id=? AND role_id=(SELECT id FROM roles WHERE name='voter')", "i", [$uid]);
        if (!$u) { set_flash('danger', 'User must have voter role.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
        if (db_fetch_one($mysqli, "SELECT id FROM voters WHERE user_id=?", "i", [$uid])) {
            set_flash('danger', 'Voter profile already exists.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }
        $cardNo = 'V' . date('Y') . str_pad((string)$uid, 6, '0', STR_PAD_LEFT);
        db_execute($mysqli, "INSERT INTO voters (user_id, voter_card_no, is_verified) VALUES (?,?,0)", "is", [$uid, $cardNo]);
        audit_log(current_user()['id'], 'voter_link', 'voter', $uid, "Linked voter profile to user $uid");
        set_flash('success', 'Voter profile linked.');
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
    if ($action === 'toggle_verify') {
        $vid = (int)($_POST['voter_id'] ?? 0);
        if ($vid <= 0) { set_flash('danger', 'Invalid voter.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
        db_execute($mysqli, "UPDATE voters SET is_verified = 1 - is_verified WHERE id=?", "i", [$vid]);
        audit_log(current_user()['id'], 'voter_verify_toggle', 'voter', $vid, "Toggled verification for voter $vid");
        set_flash('success', 'Verification toggled.');
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
    if ($action === 'delete_unvoted') {
        $vid = filter_var($_POST['voter_id'] ?? null, FILTER_VALIDATE_INT);
        $voter = $vid !== false && $vid > 0
            ? db_fetch_one(
                $mysqli,
                "SELECT v.id, v.user_id, r.name AS role_name
                 FROM voters v
                 JOIN users u ON u.id=v.user_id
                 JOIN roles r ON r.id=u.role_id
                 WHERE v.id=?",
                "i",
                [$vid]
            )
            : null;
        if (!$voter || $voter['role_name'] !== 'voter') {
            set_flash('danger', 'Voter profile not found.');
            header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }

        $hasVoted = db_fetch_one(
            $mysqli,
            "SELECT id FROM voter_elections WHERE voter_id=? AND voted_at IS NOT NULL LIMIT 1",
            "i",
            [(int)$voter['id']]
        );
        if ($hasVoted) {
            set_flash('danger', 'This voter has already voted and cannot be deleted.');
            header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }

        $mysqli->begin_transaction();
        try {
            $deleted = db_execute(
                $mysqli,
                "DELETE FROM users
                 WHERE id=?
                   AND role_id=(SELECT id FROM roles WHERE name='voter')
                   AND EXISTS (SELECT 1 FROM voters v WHERE v.id=? AND v.user_id=users.id)
                   AND NOT EXISTS (
                       SELECT 1 FROM voter_elections ve
                       WHERE ve.voter_id=? AND ve.voted_at IS NOT NULL
                   )",
                "iii",
                [(int)$voter['user_id'], (int)$voter['id'], (int)$voter['id']]
            );
            if ($deleted !== 1) {
                throw new RuntimeException('Voter account was not deleted; it may have voted or changed.');
            }
            $mysqli->commit();
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Unvoted voter deletion failed: ' . $t->getMessage());
            set_flash('danger', 'Unable to delete this voter account. It may have related election records.');
            header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
        }
        audit_log((int)current_user()['id'], 'voter_delete_unvoted', 'voter', (int)$voter['id'], 'Deleted an unvoted voter account');
        set_flash('success', 'Unvoted voter account deleted.');
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
    if ($action === 'update_profile') {
        $vid = (int)($_POST['voter_id'] ?? 0);
        $dob = trim($_POST['date_of_birth'] ?? '');
        $phone = clean_string($_POST['phone'] ?? '', 30);
        $address = clean_string($_POST['address'] ?? '', 500);
        if ($vid <= 0) { set_flash('danger', 'Invalid voter.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
        $dobVal = ($dob !== '' && strtotime($dob)) ? $dob : null;
        db_execute($mysqli, "UPDATE voters SET date_of_birth=?, phone=?, address=? WHERE id=?", "sssi", [$dobVal, $phone ?: null, $address ?: null, $vid]);
        audit_log(current_user()['id'], 'voter_profile_update', 'voter', $vid, "Updated voter profile $vid");
        set_flash('success', 'Profile updated.');
        header('Location: /VOTING_SYSTEM/admin/voters.php'); exit;
    }
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$where = "WHERE 1=1"; $params = []; $types = '';
if ($q !== '') {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR v.voter_card_no LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
$totalRow = db_fetch_one($mysqli, "SELECT COUNT(*) c FROM voters v JOIN users u ON u.id=v.user_id $where", $types, $params);
$total = (int)($totalRow['c'] ?? 0);
$pg = paginate($total, $perPage, $page);
$voters = db_fetch_all($mysqli, "SELECT v.id, v.voter_card_no, v.is_verified, v.date_of_birth, v.phone, v.created_at, u.id AS user_id, u.full_name, u.email, u.status, r.name AS role_name, EXISTS (SELECT 1 FROM voter_elections ve WHERE ve.voter_id=v.id AND ve.voted_at IS NOT NULL) AS has_voted FROM voters v JOIN users u ON u.id=v.user_id JOIN roles r ON r.id=u.role_id $where ORDER BY v.id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, $pg['offset']]));
$unlinked = db_fetch_all($mysqli, "SELECT u.id, u.full_name, u.email FROM users u LEFT JOIN voters v ON v.user_id=u.id WHERE u.role_id=(SELECT id FROM roles WHERE name='voter') AND v.id IS NULL");
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
<?php
if ($editId > 0) {
    $ev = db_fetch_one($mysqli, "SELECT v.*, u.full_name, u.email FROM voters v JOIN users u ON u.id=v.user_id WHERE v.id=?", "i", [$editId]);
    if (!$ev) { set_flash('danger','Voter not found.'); header('Location: /VOTING_SYSTEM/admin/voters.php'); exit; }
?>
<div class="card">
    <h2>Edit Voter #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['full_name']); ?></h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="voter_id" value="<?php echo (int)$ev['id']; ?>">
        <div class="form-group"><label>Date of Birth</label><input class="form-control" type="date" name="date_of_birth" value="<?php echo e($ev['date_of_birth']); ?>"></div>
        <div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?php echo e($ev['phone']); ?>"></div>
        <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="3"><?php echo e($ev['address']); ?></textarea></div>
        <button class="btn btn--primary" type="submit">Save Profile</button>
        <a class="btn" href="/VOTING_SYSTEM/admin/voters.php">Cancel</a>
    </form>
</div>
<?php } else { ?>
<div class="card">
    <h2>Voters</h2>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <input class="form-control" style="max-width:260px;" name="q" placeholder="Search name/email/card" value="<?php echo e($q); ?>">
        <button class="btn" type="submit">Search</button>
        <a class="btn" href="/VOTING_SYSTEM/admin/voters.php">Reset</a>
    </form>

    <h3>Create Voter (new account)</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;align-items:end;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group"><label>Full Name</label><input class="form-control" name="full_name" required></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" required></div>
        <div class="form-group"><label>Password</label><input class="form-control" type="password" name="password" required></div>
        <div class="form-group"><button class="btn btn--primary" type="submit">Create Voter</button></div>
    </form>

    <?php if (!empty($unlinked)): ?>
    <h3>Link Existing Voter-Role User</h3>
    <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="link">
        <div class="form-group" style="min-width:260px;">
            <label>User</label>
            <select class="form-control" name="user_id">
                <?php foreach ($unlinked as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['full_name']); ?> (<?php echo e($u['email']); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><button class="btn" type="submit">Link Profile</button></div>
    </form>
    <?php endif; ?>
</div>
<?php } ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Card No.</th><th>Name</th><th>Email</th><th>Verified</th><th>Voting</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($voters as $v): ?>
                <tr>
                    <td><?php echo (int)$v['id']; ?></td>
                    <td><?php echo e($v['voter_card_no']); ?></td>
                    <td><?php echo e($v['full_name']); ?></td>
                    <td><?php echo e($v['email']); ?></td>
                    <td><?php echo $v['is_verified'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $v['has_voted'] ? 'Voted' : 'Not voted'; ?></td>
                    <td><span class="badge badge--<?php echo e($v['status']); ?>"><?php echo e($v['status']); ?></span></td>
                    <td><?php echo e($v['created_at']); ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/voters.php?edit=<?php echo (int)$v['id']; ?>">Edit</a>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_verify">
                            <input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                            <button class="btn btn--sm" type="submit">Toggle Verify</button>
                        </form>
                        <?php if (!$v['has_voted'] && $v['role_name'] === 'voter'): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this voter account? This cannot be undone.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_unvoted">
                                <input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                                <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                            </form>
                        <?php elseif ($v['has_voted']): ?>
                            <span title="Voter accounts with recorded votes cannot be deleted.">Delete locked</span>
                        <?php else: ?>
                            <span title="Only voter accounts can be deleted here.">Delete unavailable</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($voters)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--muted);">No voters found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px;display:flex;gap:6px;align-items:center;">
        <span>Page <?php echo $pg['page']; ?> of <?php echo max(1,$pg['pages']); ?> (<?php echo $total; ?> total)</span>
        <?php if ($pg['page'] > 1): ?>
            <a class="btn btn--sm" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pg['page']-1])); ?>">Prev</a>
        <?php endif; ?>
        <?php if ($pg['page'] < $pg['pages']): ?>
            <a class="btn btn--sm" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pg['page']+1])); ?>">Next</a>
        <?php endif; ?>
    </div>
</div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

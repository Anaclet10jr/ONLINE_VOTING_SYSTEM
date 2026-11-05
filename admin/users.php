<?php
require_once __DIR__ . '/../includes/header.php';
require_role(['admin']);
$active = 'users';
global $mysqli;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
    }
    if ($action === 'create') {
        $name = clean_string($_POST['full_name'] ?? '', 120);
        $email = trim($_POST['email'] ?? '');
        $roleName = $_POST['role'] ?? 'voter';
        $status = $_POST['status'] ?? 'pending';
        $password = $_POST['password'] ?? '';
        if ($name === '' || !is_valid_email($email) || !is_strong_password($password) ||
            !in_array($status, ['pending', 'active'], true)) {
            set_flash('danger', 'Invalid input or weak password.');
            header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
        }
        $role = db_fetch_one($mysqli, "SELECT id FROM roles WHERE name = ?", "s", [$roleName]);
        if (!$role) { set_flash('danger', 'Invalid role.'); header('Location: /VOTING_SYSTEM/admin/users.php'); exit; }
        if (db_fetch_one($mysqli, "SELECT id FROM users WHERE email = ?", "s", [$email])) {
            set_flash('danger', 'Email already exists.'); header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
        }
        $mysqli->begin_transaction();
        try {
            db_execute($mysqli, "INSERT INTO users (role_id, full_name, email, password_hash, status) VALUES (?,?,?,?,?)", "issss", [$role['id'], $name, $email, password_hash($password, PASSWORD_DEFAULT), $status]);
            $uid = $mysqli->insert_id;
            if ($roleName === 'voter') {
                $cardNo = 'V' . date('Y') . str_pad((string)$uid, 6, '0', STR_PAD_LEFT);
                $verified = $status === 'active' ? 1 : 0;
                db_execute($mysqli, "INSERT INTO voters (user_id, voter_card_no, is_verified) VALUES (?,?,?)", "isi", [$uid, $cardNo, $verified]);
            }
            $mysqli->commit();
            audit_log(current_user()['id'], 'user_create', 'user', $uid, "Created user $email ($roleName)");
            set_flash('success', 'User created.');
        } catch (Throwable $t) {
            $mysqli->rollback(); error_log($t->getMessage());
            set_flash('danger', 'Failed to create user.');
        }
        header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
    }
    if ($action === 'update') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $name = clean_string($_POST['full_name'] ?? '', 120);
        $roleName = $_POST['role'] ?? '';
        $status = $_POST['status'] ?? '';
        $mfa = isset($_POST['mfa_enabled']) ? 1 : 0;
        $role = db_fetch_one($mysqli, "SELECT id FROM roles WHERE name = ?", "s", [$roleName]);
        $cu = current_user();
        if (!$uid || !$role || $name === '' ||
            !in_array($status, ['pending', 'active', 'suspended', 'locked'], true) ||
            ($uid === (int)$cu['id'] && ($roleName !== 'admin' || $status !== 'active'))) {
            set_flash('danger', 'Invalid data or self-demotion blocked.');
            header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
        }
        db_execute($mysqli, "UPDATE users SET full_name=?, role_id=?, status=?, mfa_enabled=? WHERE id=?", "sisii", [$name, $role['id'], $status, $mfa, $uid]);
        audit_log($cu['id'], 'user_update', 'user', $uid, "Updated user $uid");
        set_flash('success', 'User updated.');
        header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
    }
    if ($action === 'resetpw') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pw = $_POST['new_password'] ?? '';
        if ($uid <= 0 || !is_strong_password($pw)) {
            set_flash('danger', 'Weak password.'); header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
        }
        db_execute($mysqli, "UPDATE users SET password_hash=?, failed_login_count=0, locked_until=NULL, status=IF(status='locked','active',status) WHERE id=?", "si", [password_hash($pw, PASSWORD_DEFAULT), $uid]);
        audit_log(current_user()['id'], 'password_reset', 'user', $uid, "Admin reset password for user $uid");
        set_flash('success', 'Password reset.');
        header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
    }
    if ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $cu = current_user();
        if ($uid === (int)$cu['id']) {
            set_flash('danger', 'Cannot deactivate yourself.');
            header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
        }
        db_execute($mysqli, "UPDATE users SET status = IF(status='active','suspended','active') WHERE id=?", "i", [$uid]);
        audit_log($cu['id'], 'user_toggle', 'user', $uid, "Toggled user $uid status");
        set_flash('success', 'Status toggled.');
        header('Location: /VOTING_SYSTEM/admin/users.php'); exit;
    }
}

$q = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$where = "WHERE 1=1"; $params = []; $types = '';
if ($q !== '') { $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)"; $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $types .= 'ss'; }
if ($roleFilter !== '') { $where .= " AND r.name = ?"; $params[] = $roleFilter; $types .= 's'; }
if ($statusFilter !== '') { $where .= " AND u.status = ?"; $params[] = $statusFilter; $types .= 's'; }
$totalRow = db_fetch_one($mysqli, "SELECT COUNT(*) c FROM users u JOIN roles r ON r.id=u.role_id $where", $types, $params);
$total = (int)($totalRow['c'] ?? 0);
$pg = paginate($total, $perPage, $page);
$users = db_fetch_all($mysqli, "SELECT u.id, u.full_name, u.email, u.status, u.mfa_enabled, u.created_at, u.last_login_at, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id $where ORDER BY u.id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, $pg['offset']]));
$roles = db_fetch_all($mysqli, "SELECT name FROM roles ORDER BY id");
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
<?php
if ($editId > 0) {
    $eu = db_fetch_one($mysqli, "SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?", "i", [$editId]);
    if (!$eu) { set_flash('danger','User not found.'); header('Location: /VOTING_SYSTEM/admin/users.php'); exit; }
?>
<div class="card">
    <h2>Edit User #<?php echo (int)$eu['id']; ?></h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?php echo (int)$eu['id']; ?>">
        <div class="form-group"><label>Full Name</label><input class="form-control" name="full_name" value="<?php echo e($eu['full_name']); ?>" required></div>
        <div class="form-group"><label>Email (read-only)</label><input class="form-control" value="<?php echo e($eu['email']); ?>" disabled></div>
        <div class="form-group"><label>Role</label><select class="form-control" name="role"><?php foreach ($roles as $r): ?><option value="<?php echo e($r['name']); ?>" <?php echo $r['name']===$eu['role_name']?'selected':''; ?>><?php echo e($r['name']); ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Status</label><select class="form-control" name="status"><?php foreach (['pending','active','suspended','locked'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $s===$eu['status']?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label><input type="checkbox" name="mfa_enabled" <?php echo $eu['mfa_enabled']?'checked':''; ?>> MFA enabled</label></div>
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn" href="/VOTING_SYSTEM/admin/users.php">Cancel</a>
    </form>
    <hr>
    <h3>Reset Password</h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="resetpw">
        <input type="hidden" name="user_id" value="<?php echo (int)$eu['id']; ?>">
        <div class="form-group"><label>New Password</label><input class="form-control" type="password" name="new_password" required></div>
        <button class="btn btn--danger" type="submit">Reset Password</button>
    </form>
</div>
<?php } else { ?>

<div class="card">
    <h2>Users</h2>
    <form method="get" class="form-inline" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <input class="form-control" style="max-width:220px;" name="q" placeholder="Search name/email" value="<?php echo e($q); ?>">
        <select class="form-control" style="max-width:140px;" name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?php echo e($r['name']); ?>" <?php echo $r['name']===$roleFilter?'selected':''; ?>><?php echo e($r['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-control" style="max-width:140px;" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending','active','suspended','locked'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $s===$statusFilter?'selected':''; ?>><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Filter</button>
        <a class="btn" href="/VOTING_SYSTEM/admin/users.php">Reset</a>
    </form>

    <h3>Add User</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;align-items:end;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group"><label>Full Name</label><input class="form-control" name="full_name" required></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" required></div>
        <div class="form-group"><label>Role</label><select class="form-control" name="role"><?php foreach ($roles as $r): ?><option value="<?php echo e($r['name']); ?>"><?php echo e($r['name']); ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Status</label><select class="form-control" name="status"><option value="pending">pending</option><option value="active" selected>active</option></select></div>
        <div class="form-group"><label>Password</label><input class="form-control" type="password" name="password" required></div>
        <div class="form-group"><button class="btn btn--primary" type="submit">Create</button></div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>MFA</th><th>Created</th><th>Last Login</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo e($u['full_name']); ?></td>
                    <td><?php echo e($u['email']); ?></td>
                    <td><?php echo e($u['role_name']); ?></td>
                    <td><span class="badge badge--<?php echo e($u['status']); ?>"><?php echo e($u['status']); ?></span></td>
                    <td><?php echo $u['mfa_enabled'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo e($u['created_at']); ?></td>
                    <td><?php echo e($u['last_login_at'] ?? '-'); ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn--sm" href="/VOTING_SYSTEM/admin/users.php?edit=<?php echo (int)$u['id']; ?>">Edit</a>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <button class="btn btn--sm btn--danger" type="submit">Toggle</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--muted);">No users found.</td></tr>
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
<?php } ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

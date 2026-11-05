<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['voter']);

$active = 'profile';
global $mysqli;
$user = current_user();
$voter = db_fetch_one(
    $mysqli,
    "SELECT v.id, v.voter_card_no, v.date_of_birth, v.phone, v.address, v.is_verified,
            u.full_name, u.email
     FROM voters v JOIN users u ON u.id=v.user_id
     WHERE v.user_id=?",
    "i",
    [(int)$user['id']]
);
if (!$voter) {
    http_response_code(403);
    exit('Voter profile not found. Please contact an administrator.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/voter/profile.php');
        exit;
    }

    $nameInput = $_POST['full_name'] ?? '';
    $dobInput = $_POST['date_of_birth'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $addressInput = $_POST['address'] ?? '';
    if (!is_string($nameInput) || !is_string($dobInput) ||
        !is_string($phoneInput) || !is_string($addressInput)) {
        set_flash('danger', 'Invalid profile data.');
        header('Location: /VOTING_SYSTEM/voter/profile.php');
        exit;
    }
    $name = clean_string($nameInput, 120);
    $dob = trim($dobInput);
    $phone = clean_string($phoneInput, 30);
    $address = clean_string($addressInput, 500);
    $dobValue = null;
    if ($dob !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
            $date->format('Y-m-d') !== $dob || $date > new DateTimeImmutable('today')) {
            set_flash('danger', 'Enter a valid date of birth that is not in the future.');
            header('Location: /VOTING_SYSTEM/voter/profile.php');
            exit;
        }
        $dobValue = $dob;
    }
    if ($name === '') {
        set_flash('danger', 'Full name is required.');
        header('Location: /VOTING_SYSTEM/voter/profile.php');
        exit;
    }

    $mysqli->begin_transaction();
    try {
        db_execute($mysqli, "UPDATE users SET full_name=? WHERE id=?", "si", [$name, (int)$user['id']]);
        db_execute(
            $mysqli,
            "UPDATE voters SET date_of_birth=?, phone=?, address=? WHERE id=?",
            "sssi",
            [$dobValue, $phone !== '' ? $phone : null, $address !== '' ? $address : null, (int)$voter['id']]
        );
        $mysqli->commit();
    } catch (Throwable $t) {
        $mysqli->rollback();
        error_log('Voter profile update failed: ' . $t->getMessage());
        set_flash('danger', 'Unable to save your profile. Please try again.');
        header('Location: /VOTING_SYSTEM/voter/profile.php');
        exit;
    }
    audit_log((int)$user['id'], 'voter_self_profile_update', 'voter', (int)$voter['id'], 'Voter updated own profile');
    set_flash('success', 'Your profile has been updated.');
    header('Location: /VOTING_SYSTEM/voter/profile.php');
    exit;
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="layout__main">
        <div class="card">
            <h2>My Profile</h2>
            <p>Update your contact details. Your voter card number and verification status are managed by an administrator.</p>
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <input class="form-control" id="full_name" name="full_name" maxlength="120" value="<?php echo e($voter['full_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input class="form-control" id="email" value="<?php echo e($voter['email']); ?>" disabled>
                    <small>Email changes must be handled by an administrator.</small>
                </div>
                <div class="form-group">
                    <label for="voter_card_no">Voter card number</label>
                    <input class="form-control" id="voter_card_no" value="<?php echo e($voter['voter_card_no']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="date_of_birth">Date of birth</label>
                    <input class="form-control" type="date" id="date_of_birth" name="date_of_birth" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($voter['date_of_birth'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" maxlength="30" value="<?php echo e($voter['phone'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="3" maxlength="500"><?php echo e($voter['address'] ?? ''); ?></textarea>
                </div>
                <p>Verification: <?php echo (int)$voter['is_verified'] === 1 ? 'Verified' : 'Pending'; ?></p>
                <button class="btn btn--primary" type="submit">Save profile</button>
                <a class="btn" href="/VOTING_SYSTEM/voter/dashboard.php">Cancel</a>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

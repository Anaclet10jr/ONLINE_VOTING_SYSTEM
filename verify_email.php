<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'CSRF validation failed.');
        header('Location: /VOTING_SYSTEM/verify_email.php');
        exit;
    }

    $token = trim($_POST['token'] ?? '');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        set_flash('danger', 'Enter a valid verification token.');
        header('Location: /VOTING_SYSTEM/verify_email.php');
        exit;
    }

    global $mysqli;
    $mysqli->begin_transaction();
    try {
        $verification = db_fetch_one(
            $mysqli,
            "SELECT ev.id, ev.user_id, u.status
             FROM email_verifications ev
             JOIN users u ON u.id=ev.user_id
             WHERE ev.token_hash=? AND ev.verified_at IS NULL AND ev.expires_at>NOW()
             FOR UPDATE",
            "s",
            [hash('sha256', $token)]
        );

        if (!$verification || $verification['status'] !== 'pending') {
            $mysqli->rollback();
            set_flash('danger', 'This verification token is invalid, expired, or already used.');
            header('Location: /VOTING_SYSTEM/verify_email.php');
            exit;
        }

        $activated = db_execute(
            $mysqli,
            "UPDATE users SET status='active' WHERE id=? AND status='pending'",
            "i",
            [(int)$verification['user_id']]
        );
        $verifiedVoter = db_execute(
            $mysqli,
            "UPDATE voters SET is_verified=1 WHERE user_id=?",
            "i",
            [(int)$verification['user_id']]
        );
        if ($activated !== 1 || $verifiedVoter !== 1) {
            throw new RuntimeException('Verification did not activate exactly one registered voter.');
        }

        db_execute(
            $mysqli,
            "UPDATE email_verifications SET verified_at=NOW() WHERE id=? AND verified_at IS NULL",
            "i",
            [(int)$verification['id']]
        );
        $mysqli->commit();
    } catch (Throwable $t) {
        $mysqli->rollback();
        error_log('Email verification failed: ' . $t->getMessage());
        set_flash('danger', 'Unable to verify your account. Please try again or contact an administrator.');
        header('Location: /VOTING_SYSTEM/verify_email.php');
        exit;
    }

    audit_log((int)$verification['user_id'], 'email_verified', 'user', (int)$verification['user_id'], 'Voter email verified');
    set_flash('success', 'Your account is verified and active. You can now sign in.');
    header('Location: /VOTING_SYSTEM/login.php');
    exit;
}

$pageTitle = 'Verify Account';
require_once __DIR__ . '/includes/header.php';
?>
<section class="card" style="max-width:480px;margin:40px auto;">
    <h2>Verify account</h2>
    <p>Paste the verification token shown after registration. Tokens expire after 24 hours and can only be used once.</p>
    <form method="post" action="/VOTING_SYSTEM/verify_email.php">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="token">Verification token</label>
            <textarea class="form-control" id="token" name="token" rows="3" maxlength="64" required></textarea>
        </div>
        <button class="btn btn--primary" type="submit">Verify account</button>
        <a class="btn" href="/VOTING_SYSTEM/login.php">Back to login</a>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

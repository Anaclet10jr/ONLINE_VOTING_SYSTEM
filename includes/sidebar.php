<?php
/**
 * includes/sidebar.php
 * Dashboard sidebar navigation. Pass $active (string) to highlight current page.
 */
if (!isset($active)) $active = '';
$cu = current_user();
if (!$cu) return;
$role = $cu['role_name'];
?>
<aside class="sidebar">
    <div class="sidebar__title"><?php echo e(ucfirst($role)); ?> Panel</div>
    <nav class="sidebar__nav">
        <?php if ($role === 'admin'): ?>
            <a class="<?php echo $active==='dashboard'?'active':''; ?>" href="/VOTING_SYSTEM/admin/dashboard.php">Dashboard</a>
            <a class="<?php echo $active==='users'?'active':''; ?>" href="/VOTING_SYSTEM/admin/users.php">Users</a>
            <a class="<?php echo $active==='voters'?'active':''; ?>" href="/VOTING_SYSTEM/admin/voters.php">Voters</a>
            <a class="<?php echo $active==='elections'?'active':''; ?>" href="/VOTING_SYSTEM/admin/elections.php">Elections</a>
            <a class="<?php echo $active==='candidates'?'active':''; ?>" href="/VOTING_SYSTEM/admin/candidates.php">Candidates</a>
            <a class="<?php echo $active==='reports'?'active':''; ?>" href="/VOTING_SYSTEM/admin/reports.php">Reports</a>
            <a class="<?php echo $active==='settings'?'active':''; ?>" href="/VOTING_SYSTEM/admin/settings.php">Settings</a>
            <a class="<?php echo $active==='security'?'active':''; ?>" href="/VOTING_SYSTEM/account/security.php">Account Security</a>
        <?php elseif ($role === 'manager'): ?>
            <a class="<?php echo $active==='dashboard'?'active':''; ?>" href="/VOTING_SYSTEM/manager/dashboard.php">Dashboard</a>
            <a class="<?php echo $active==='elections'?'active':''; ?>" href="/VOTING_SYSTEM/manager/elections.php">My Elections</a>
            <a class="<?php echo $active==='candidates'?'active':''; ?>" href="/VOTING_SYSTEM/manager/candidates.php">Candidates</a>
            <a class="<?php echo $active==='monitoring'?'active':''; ?>" href="/VOTING_SYSTEM/manager/monitoring.php">Monitoring</a>
            <a class="<?php echo $active==='security'?'active':''; ?>" href="/VOTING_SYSTEM/account/security.php">Account Security</a>
        <?php elseif ($role === 'voter'): ?>
            <a class="<?php echo $active==='dashboard'?'active':''; ?>" href="/VOTING_SYSTEM/voter/dashboard.php">Dashboard</a>
            <a class="<?php echo $active==='elections'?'active':''; ?>" href="/VOTING_SYSTEM/voter/elections.php">Elections</a>
            <a class="<?php echo $active==='profile'?'active':''; ?>" href="/VOTING_SYSTEM/voter/profile.php">Profile</a>
        <?php endif; ?>
        <a href="/VOTING_SYSTEM/results/index.php">Results</a>
    </nav>
</aside>

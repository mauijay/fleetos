<?php
$auth = auth();
$user = $auth->user();
$isLoggedIn = $auth->loggedIn();
$label = $user?->email ?? $user?->username ?? 'Signed in';
?>
<div class="auth-links" aria-label="Account links">
    <?php if ($isLoggedIn): ?>
        <span class="auth-identity"><?= esc($label) ?></span>
        <a class="auth-action" href="/logout">Logout</a>
    <?php else: ?>
        <a class="auth-action" href="/login">Login</a>
    <?php endif; ?>
</div>

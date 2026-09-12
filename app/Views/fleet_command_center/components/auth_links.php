<?php
$auth = auth();
$user = $auth->user();
$isLoggedIn = $auth->loggedIn();
$label = $user?->email ?? $user?->username ?? 'Signed in';
?>
<div class="auth-links" aria-label="Account links">
    <?php if ($isLoggedIn): ?>
        <span class="auth-identity"><?= esc($label) ?></span>
        <form class="auth-action-form" action="<?= esc(url_to('logout'), 'attr') ?>" method="post">
            <?= csrf_field() ?>
            <button class="auth-action" type="submit">Logout</button>
        </form>
    <?php else: ?>
        <a class="auth-action" href="/login">Login</a>
    <?php endif; ?>
</div>

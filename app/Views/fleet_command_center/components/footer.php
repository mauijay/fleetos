<?php
$app = config('App');
$showAuthLinks = $showAuthLinks ?? true;
?>
<footer class="site-footer" aria-label="Site credit">
    <p>Created by <?= esc($app->siteCreditEmail) ?> for <a href="https://<?= esc($app->siteCreditClient, 'attr') ?>"><?= esc($app->siteCreditClient) ?></a>.</p>
    <div class="footer-meta">
        <?php if ($showAuthLinks): ?>
            <?= view('fleet_command_center/components/auth_links') ?>
        <?php endif; ?>
        <span>v<?= esc($app->appVersion) ?></span>
    </div>
</footer>

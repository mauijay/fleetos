<?php
$assets = service('assetManifestService')->appAssets();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | FleetOS</title>
    <?php if ($assets['css'] !== null): ?>
        <link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>">
    <?php endif; ?>
</head>
<body class="fleet-shell auth-page">
    <main class="auth-main" aria-labelledby="login-heading">
        <section class="auth-card">
            <div>
                <p class="eyebrow">FleetOS Access</p>
                <h1 id="login-heading">Login</h1>
                <p class="status-copy">Sign in to manage GO808 fleet operations.</p>
            </div>

            <?php if (session('error') !== null): ?>
                <div class="auth-message tone-danger" role="alert"><?= esc(session('error')) ?></div>
            <?php elseif (session('errors') !== null): ?>
                <div class="auth-message tone-danger" role="alert">
                    <?php if (is_array(session('errors'))): ?>
                        <?php foreach (session('errors') as $error): ?>
                            <span><?= esc($error) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span><?= esc(session('errors')) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (session('message') !== null): ?>
                <div class="auth-message tone-success" role="status"><?= esc(session('message')) ?></div>
            <?php endif; ?>

            <form class="auth-form" action="<?= url_to('login') ?>" method="post">
                <?= csrf_field() ?>

                <label>Email
                    <input type="email" name="email" inputmode="email" autocomplete="email" placeholder="jay@example.com" value="<?= old('email') ?>" required>
                </label>

                <label>Password
                    <input type="password" name="password" autocomplete="current-password" placeholder="Password" required>
                </label>

                <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                    <label class="checkbox-row auth-remember">
                        <input type="checkbox" name="remember" <?php if (old('remember')): ?> checked<?php endif; ?>>
                        <span>Remember this device</span>
                    </label>
                <?php endif; ?>

                <button class="primary-action" type="submit">Login</button>
            </form>
        </section>

        <?= view('fleet_command_center/components/footer', ['showAuthLinks' => false]) ?>
    </main>
</body>
</html>

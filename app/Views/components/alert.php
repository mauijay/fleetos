<?php

/** @var string $type */
/** @var string|null $heading */
/** @var string|list<string> $messages */
/** @var bool|null $list */
$allowedTypes = ['success', 'info', 'warning', 'danger'];
$type = isset($type) && in_array($type, $allowedTypes, true) ? $type : 'info';
$heading = isset($heading) && trim($heading) !== '' ? trim($heading) : null;
$messages = $messages ?? [];
$messages = is_array($messages) ? array_values($messages) : [$messages];
$messages = array_values(array_filter($messages, static fn (mixed $message): bool => is_string($message) && trim($message) !== ''));
$list = (bool) ($list ?? count($messages) > 1);
$urgent = in_array($type, ['warning', 'danger'], true);
?>
<?php if ($heading !== null || $messages !== []): ?>
    <div class="alert alert-<?= esc($type, 'attr') ?>" role="<?= $urgent ? 'alert' : 'status' ?>" aria-live="<?= $urgent ? 'assertive' : 'polite' ?>">
        <span class="alert-indicator" aria-hidden="true"></span>
        <div class="alert-content">
            <?php if ($heading !== null): ?><strong><?= esc($heading) ?></strong><?php endif; ?>
            <?php if ($messages !== [] && $list): ?>
                <ul><?php foreach ($messages as $message): ?><li><?= esc($message) ?></li><?php endforeach; ?></ul>
            <?php elseif ($messages !== []): ?>
                <p><?= esc($messages[0]) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

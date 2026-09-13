<?php

/** @var string|null $notice */
/** @var string|null $warning */
/** @var array<string,string> $errors */
/** @var string|null $warningHeading */
?>
<?php if ($notice): ?><?= view('components/alert', ['type' => 'success', 'heading' => null, 'messages' => $notice]) ?><?php endif; ?>
<?php if ($warning): ?><?= view('components/alert', ['type' => 'warning', 'heading' => $warningHeading ?? 'Please confirm', 'messages' => $warning]) ?><?php endif; ?>
<?php if ($errors !== []): ?><?= view('components/alert', ['type' => 'danger', 'heading' => 'Please review', 'messages' => array_values($errors), 'list' => true]) ?><?php endif; ?>

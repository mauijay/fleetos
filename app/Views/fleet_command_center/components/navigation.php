<?php /** @var array<int, array<string, string>> $items */ ?>
<details class="mobile-nav">
    <summary>
        <span class="brand-mark" aria-hidden="true">F</span>
        <strong>FleetOS</strong>
        <span>Menu</span>
    </summary>
    <nav aria-label="Mobile navigation">
        <?php foreach ($items as $item): ?>
            <a href="<?= esc($item['href'], 'attr') ?>" class="nav-link<?= $item['active'] === 'true' ? ' is-active' : '' ?>" <?= $item['active'] === 'true' ? 'aria-current="page"' : '' ?>>
                <?= esc($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?= view('fleet_command_center/components/auth_links') ?>
</details>

<aside class="side-nav" aria-label="Primary navigation">
    <div class="brand-lockup">
        <span class="brand-mark" aria-hidden="true">F</span>
        <div>
            <strong>FleetOS</strong>
            <span>Operations</span>
        </div>
    </div>
    <nav>
        <?php foreach ($items as $item): ?>
            <a href="<?= esc($item['href'], 'attr') ?>" class="nav-link<?= $item['active'] === 'true' ? ' is-active' : '' ?>" <?= $item['active'] === 'true' ? 'aria-current="page"' : '' ?>>
                <?= esc($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?= view('fleet_command_center/components/auth_links') ?>
</aside>

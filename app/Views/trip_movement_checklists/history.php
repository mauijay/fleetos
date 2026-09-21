<?php
/** @var array{css: ?string, js: ?string} $assets */
/** @var array<string, mixed>|null $vehicle */
/** @var array<int, array<string, mixed>> $trips */
/** @var int|null $selectedTripId */
$selectedTripId ??= null;
$navigation ??= [];
$locationLabel = static fn (?string $code): string => ucwords(str_replace('_', ' ', $code ?? 'Unknown'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vehicle Trip History | FleetOS</title>
    <?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
</head>
<body class="fleet-shell">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-frame import-frame">
    <?= view('fleet_command_center/components/navigation', ['items' => $navigation]) ?>
    <main id="main-content" class="command-main import-main movement-main" tabindex="-1">
        <header class="top-status">
            <div><p class="eyebrow">Vehicle trip history</p><h1><?= esc((string) ($vehicle['fleet_code'] ?? 'Vehicle')) ?></h1></div>
            <div class="vehicle-detail-actions">
                <?php if ($vehicle !== null): ?><a class="secondary-action button-link" href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>">Vehicle details</a><?php endif; ?>
                <a class="secondary-action button-link" href="/">Command Center</a>
            </div>
        </header>
        <section class="section">
            <?php if ($vehicle === null): ?>
                <div class="empty-state">Vehicle not found.</div>
            <?php elseif ($trips === []): ?>
                <div class="empty-state">No trip history is available.</div>
            <?php else: ?>
                <div class="trip-history-list">
                    <?php foreach ($trips as $trip): ?>
                        <?php
                        $isSelected = (int) $trip['id'] === $selectedTripId;
                        $isCanceled = str_starts_with((string) ($trip['trip_status_code'] ?? ''), 'canceled');
                        $movementHref = $trip['movement_href'] ?? null;
                        $commitmentsHref = '/operations/trips/' . (int) $trip['id'] . '/commitments';
                        ?>
                        <article class="trip-history-row<?= $isSelected ? ' is-selected' : '' ?><?= $isCanceled ? ' is-canceled' : '' ?>">
                            <div><strong><?= esc((string) ($trip['guest_name'] ?? 'Guest not captured')) ?></strong><span>Trip <?= esc((string) ($trip['turo_trip_id'] ?? $trip['id'])) ?></span></div>
                            <div><span><?= esc((new DateTimeImmutable((string) $trip['starts_at']))->format('M j, Y g:i A')) ?></span><span><?= esc((new DateTimeImmutable((string) $trip['ends_at']))->format('M j, Y g:i A')) ?></span></div>
                            <div><span>Pickup: <?= esc($locationLabel($trip['pickup_location_class'] ?? null)) ?></span><span>Return: <?= esc($locationLabel($trip['return_location_class'] ?? null)) ?></span></div>
                            <div>
                                <?php if ($isSelected): ?><strong>Selected trip</strong><?php endif; ?>
                                <span class="trip-history-status"><?= esc(ucwords(str_replace('_', ' ', (string) ($trip['trip_status_code'] ?? 'Status unknown')))) ?></span>
                                <?php if ($movementHref === null): ?>
                                    <span>No movement record</span>
                                <?php else: ?>
                                    <a class="action-link" href="<?= esc((string) $movementHref, 'attr') ?>" aria-label="Open movement for trip <?= esc((string) ($trip['turo_trip_id'] ?? $trip['id']), 'attr') ?>">Open movement</a>
                                <?php endif; ?>
                                <a class="action-link" href="<?= esc($commitmentsHref, 'attr') ?>" aria-label="Guest commitments for trip <?= esc((string) ($trip['turo_trip_id'] ?? $trip['id']), 'attr') ?>">Guest commitments</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?= view('fleet_command_center/components/footer') ?>
    </main>
    </div>
    <?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?>
</body>
</html>

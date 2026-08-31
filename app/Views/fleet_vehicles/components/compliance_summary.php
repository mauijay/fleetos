<?php
/** @var array<string, mixed> $vehicle */
/** @var Closure(mixed): string $date */
?>
<div class="capital-subsection compliance-summary"><h3>Registration &amp; Compliance</h3><dl class="issue-facts"><div><dt>Registered owner</dt><dd><?= esc((string) (($vehicle['registered_owner'] ?? null) ?: 'Not entered')) ?></dd></div><div><dt>Registration renewal due</dt><dd><?= esc($date($vehicle['registration_renewal_on'] ?? null)) ?></dd></div><div><dt>Safety inspection due</dt><dd><?= esc($date($vehicle['safety_inspection_due_on'] ?? null)) ?></dd></div></dl></div>

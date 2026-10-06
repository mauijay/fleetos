<section class="section"><h1>Work &amp; Repair</h1><p><a class="primary-action" href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage-repairs/new">Add work</a></p>
<?= view('vehicle_damage_repairs/_jobs', ['work' => $work, 'vehicleId' => $vehicle['id']]) ?></section>

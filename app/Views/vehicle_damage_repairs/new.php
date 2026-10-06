<section class="section"><h1>Add work</h1><p>Record one work effort addressing one or several current conditions.</p>
<form action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage-repairs" method="post" data-work-form>
<?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'create']) ?><?= view('vehicle_damage_repairs/_form') ?><button class="primary-action" type="submit">Save work</button></form></section>

<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleTuroListingRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

class FleetVehicleService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly ?VehicleTuroListingRepository $listings = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicles(): array
    {
        return $this->db->table('fleet_vehicles fv')
            ->select('fv.*, vs.code AS status_code, vs.name AS status_name, vtl.name AS trim_name')
            ->select('vsp.model_year, vm.name AS model_name, vma.name AS make_name')
            ->select('listings.turo_vehicle_id')
            ->join('vehicle_statuses vs', 'vs.id = fv.vehicle_status_id')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id')
            ->join('vehicle_makes vma', 'vma.id = vm.vehicle_make_id')
            ->join('vehicle_turo_listings listings', 'listings.fleet_vehicle_id = fv.id AND listings.is_active = 1', 'left')
            ->where('fv.deleted_at', null)
            ->orderBy('fv.fleet_number IS NULL', 'ASC', false)
            ->orderBy('fv.fleet_number', 'ASC')
            ->orderBy('fv.fleet_code', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $id): ?array
    {
        $row = $this->db->table('fleet_vehicles fv')
            ->select('fv.*, vsp.model_year, vsp.vehicle_body_style_id, vsp.exterior_vehicle_color_id, vsp.interior_vehicle_color_id, vsp.battery_description, vsp.seating_capacity')
            ->select('vm.name AS model_name, vma.name AS make_name, listings.turo_vehicle_id')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id')
            ->join('vehicle_makes vma', 'vma.id = vm.vehicle_make_id')
            ->join('vehicle_turo_listings listings', 'listings.fleet_vehicle_id = fv.id AND listings.is_active = 1', 'left')
            ->where('fv.id', $id)->where('fv.deleted_at', null)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function formOptions(?int $currentInteriorColorId = null): array
    {
        $interiorColors = $this->db->table('vehicle_colors')->select('id, name')->whereIn('code', ['black', 'white', 'tan']);
        if ($currentInteriorColorId !== null) {
            $interiorColors->orWhere('id', $currentInteriorColorId);
        }

        return [
            'companies' => $this->options('companies', 'name', true),
            'body_styles' => $this->options('vehicle_body_styles'),
            'exterior_colors' => $this->options('vehicle_colors'),
            'interior_colors' => $interiorColors->orderBy('name', 'ASC')->get()->getResultArray(),
            'trim_levels' => $this->options('vehicle_trim_levels'),
            'drivetrains' => $this->options('vehicle_drivetrains'),
            'statuses' => $this->options('vehicle_statuses'),
        ];
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function create(array $data, ?int $actorUserId = null, ?string $turoVehicleId = null): array
    {
        $errors = $this->validate($data, null, null);
        if ($turoVehicleId !== null && trim($turoVehicleId) !== '' && $this->listings()->findActiveByTuroVehicleId($turoVehicleId) !== null) {
            $errors['turo_vehicle_id'] = 'That Turo vehicle is already mapped.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->transBegin();
        try {
            $vehicleSpecId = $this->vehicleSpecId($data);
            $now = date('Y-m-d H:i:s');
            $this->db->table('fleet_vehicles')->insert([
                'company_id' => (int) $data['company_id'],
                'vehicle_spec_id' => $vehicleSpecId,
                'vehicle_trim_level_id' => (int) $data['vehicle_trim_level_id'],
                'vehicle_drivetrain_id' => (int) $data['vehicle_drivetrain_id'],
                'vehicle_status_id' => (int) $data['vehicle_status_id'],
                'fleet_number' => (int) $data['fleet_number'],
                'fleet_code' => trim((string) $data['fleet_code']),
                'display_name' => trim((string) $data['display_name']),
                'vin' => $this->nullable($data['vin'] ?? null),
                'license_plate' => $this->nullable($data['license_plate'] ?? null),
                'purchase_date' => $this->nullable($data['purchase_date'] ?? null),
                'in_service_date' => $this->nullable($data['in_service_date'] ?? null),
                'out_of_service_date' => $this->nullable($data['out_of_service_date'] ?? null),
                'odometer_miles' => $this->nullableInt($data['odometer_miles'] ?? null),
                'sort_order' => (int) ($data['sort_order'] ?? $data['fleet_number']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();

            if ($turoVehicleId !== null && trim($turoVehicleId) !== '') {
                $this->listings()->createMapping($turoVehicleId, $id, 'Created from unknown Turo vehicle onboarding.', $actorUserId);
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Vehicle creation transaction failed.');
            }

            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return ['success' => false, 'errors' => ['database' => $exception->getMessage()]];
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function update(int $id, array $data): array
    {
        $existing = $this->vehicle($id);
        if ($existing === null) {
            return ['success' => false, 'errors' => ['vehicle' => 'Vehicle not found.']];
        }

        if ($existing['fleet_number'] !== null && (int) ($data['fleet_number'] ?? 0) !== (int) $existing['fleet_number']) {
            return ['success' => false, 'errors' => ['fleet_number' => 'An assigned fleet number cannot be changed through ordinary editing.']];
        }

        $errors = $this->validate($data, $id, (int) $existing['interior_vehicle_color_id']);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->transBegin();
        try {
            $this->db->table('fleet_vehicles')->where('id', $id)->update([
                    'company_id' => (int) $data['company_id'],
                'vehicle_spec_id' => $this->vehicleSpecId($data),
                'vehicle_trim_level_id' => (int) $data['vehicle_trim_level_id'],
                'vehicle_drivetrain_id' => (int) $data['vehicle_drivetrain_id'],
                'vehicle_status_id' => (int) $data['vehicle_status_id'],
                'fleet_number' => (int) $data['fleet_number'],
                'fleet_code' => trim((string) $data['fleet_code']),
                'display_name' => trim((string) $data['display_name']),
                'vin' => $this->nullable($data['vin'] ?? null),
                'license_plate' => $this->nullable($data['license_plate'] ?? null),
                'purchase_date' => $this->nullable($data['purchase_date'] ?? null),
                'in_service_date' => $this->nullable($data['in_service_date'] ?? null),
                'out_of_service_date' => $this->nullable($data['out_of_service_date'] ?? null),
                'odometer_miles' => $this->nullableInt($data['odometer_miles'] ?? null),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Vehicle update transaction failed.');
            }

            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return ['success' => false, 'errors' => ['database' => $exception->getMessage()]];
        }
    }

    /** @return array<string, string> */
    private function validate(array $data, ?int $ignoreId, ?int $currentInteriorColorId): array
    {
        $errors = [];
        foreach (['company_id', 'fleet_number', 'fleet_code', 'display_name', 'model_year', 'make_name', 'model_name', 'vehicle_body_style_id', 'exterior_vehicle_color_id', 'interior_vehicle_color_id', 'vehicle_trim_level_id', 'vehicle_drivetrain_id', 'vehicle_status_id'] as $field) {
            if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if ((int) $data['fleet_number'] < 1) {
            $errors['fleet_number'] = 'Fleet number must be a positive whole number.';
        }
        $year = (int) $data['model_year'];
        if ($year < 1900 || $year > ((int) date('Y') + 2)) {
            $errors['model_year'] = 'Enter a valid model year.';
        }
        foreach (['company_id' => 'companies', 'vehicle_body_style_id' => 'vehicle_body_styles', 'exterior_vehicle_color_id' => 'vehicle_colors', 'interior_vehicle_color_id' => 'vehicle_colors', 'vehicle_trim_level_id' => 'vehicle_trim_levels', 'vehicle_drivetrain_id' => 'vehicle_drivetrains', 'vehicle_status_id' => 'vehicle_statuses'] as $field => $table) {
            if ($this->db->table($table)->where('id', (int) $data[$field])->countAllResults() !== 1) {
                $errors[$field] = 'Choose a valid option.';
            }
        }
        $interiorColorId = (int) $data['interior_vehicle_color_id'];
        $preferredInterior = $this->db->table('vehicle_colors')->where('id', $interiorColorId)->whereIn('code', ['black', 'white', 'tan'])->countAllResults() === 1;
        if (! $preferredInterior && $interiorColorId !== $currentInteriorColorId) {
            $errors['interior_vehicle_color_id'] = 'Choose Black, White, or Tan.';
        }
        $this->unique($errors, 'fleet_code', trim((string) $data['fleet_code']), $ignoreId);
        $vin = $this->nullable($data['vin'] ?? null);
        if ($vin !== null) {
            $this->unique($errors, 'vin', $vin, $ignoreId);
        }
        $number = $this->db->table('fleet_vehicles')->where('company_id', (int) $data['company_id'])->where('fleet_number', (int) $data['fleet_number']);
        if ($ignoreId !== null) {
            $number->where('id !=', $ignoreId);
        }
        if ($number->countAllResults() > 0) {
            $errors['fleet_number'] = 'That fleet number is already assigned for this company.';
        }

        return $errors;
    }

    private function unique(array &$errors, string $field, string $value, ?int $ignoreId): void
    {
        $builder = $this->db->table('fleet_vehicles')->where($field, $value);
        if ($ignoreId !== null) {
            $builder->where('id !=', $ignoreId);
        }
        if ($builder->countAllResults() > 0) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is already in use.';
        }
    }

    private function vehicleSpecId(array $data): int
    {
        $makeId = $this->firstOrCreateCatalog('vehicle_makes', ['code' => $this->code((string) $data['make_name'])], ['name' => trim((string) $data['make_name'])]);
        $modelId = $this->firstOrCreateCatalog('vehicle_models', ['vehicle_make_id' => $makeId, 'code' => $this->code((string) $data['model_name'])], ['name' => trim((string) $data['model_name'])]);
        $key = [
            'vehicle_model_id' => $modelId,
            'model_year' => (int) $data['model_year'],
            'vehicle_body_style_id' => (int) $data['vehicle_body_style_id'],
            'exterior_vehicle_color_id' => (int) $data['exterior_vehicle_color_id'],
            'interior_vehicle_color_id' => (int) $data['interior_vehicle_color_id'],
            'battery_description' => trim((string) ($data['battery_description'] ?? '')),
            'seating_capacity' => (int) ($data['seating_capacity'] ?? 5),
        ];

        return $this->firstOrCreateCatalog('vehicle_specs', $key, []);
    }

    private function firstOrCreateCatalog(string $table, array $key, array $data): int
    {
        $existing = $this->db->table($table)->where($key)->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table($table)->insert(array_merge($key, $data, ['created_at' => $now, 'updated_at' => $now]));

        return (int) $this->db->insertID();
    }

    /** @return array<int, array<string, mixed>> */
    private function options(string $table, string $label = 'name', bool $activeOnly = false): array
    {
        $builder = $this->db->table($table)->select("id, {$label} AS name");
        if ($table === 'vehicle_statuses') {
            $builder->select('code');
        }
        if ($activeOnly) {
            $builder->where('is_active', true)->where('deleted_at', null);
        }

        return $builder->orderBy('name', 'ASC')->get()->getResultArray();
    }

    private function listings(): VehicleTuroListingRepository
    {
        return $this->listings ?? new VehicleTuroListingRepository($this->db);
    }

    private function code(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '', '_');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $this->nullable($value) === null ? null : (int) $value;
    }
}

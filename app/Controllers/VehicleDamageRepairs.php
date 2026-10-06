<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleDamageRepairs extends BaseController
{
    public function index(int $vehicle): string
    {
        return $this->render('index', $this->context($vehicle));
    }
    public function new(int $vehicle): string
    {
        $context = $this->context($vehicle);
        if ((int) $this->request->getGet('selected_item_id') > 0) {
            try {
                $context['aliasPreview'] = Services::vehicleDamageRepairService()->conditionPreview($context['companyId'], $vehicle, (int) $this->request->getGet('selected_item_id'));
            } catch (\InvalidArgumentException) {
                throw PageNotFoundException::forPageNotFound();
            }
        }
        return $this->render('new', $context);
    }

    public function conditionPreview(int $vehicle): string
    {
        $context = $this->context($vehicle);
        try {
            $preview = Services::vehicleDamageRepairService()->conditionPreview($context['companyId'], $vehicle, (int) $this->request->getGet('selected_item_id'));
        } catch (\InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->render('condition_preview', $context + ['preview' => $preview]);
    }

    public function create(int $vehicle): RedirectResponse
    {
        $data = $this->request->getPost();
        $data['conditions'] = is_array($data['conditions'] ?? null) ? array_values(array_filter($data['conditions'], static fn (mixed $row): bool => is_array($row) && ($row['canonical_confirmed'] ?? '') === '1')) : [];
        $result = Services::vehicleDamageRepairService()->createJob($this->company(), $vehicle, $data, $this->actor());
        return $this->result($vehicle, $result, '/fleet/vehicles/' . $vehicle . '/damage-repairs/new');
    }

    public function show(int $vehicle, int $job): string
    {
        return $this->render('show', $this->jobContext($vehicle, $job));
    }
    public function correctDetails(int $v, int $j): RedirectResponse
    {
        return $this->command('correctJobDetails', $v, $j);
    }
    public function addCondition(int $v, int $j): RedirectResponse
    {
        return $this->command('addCondition', $v, $j);
    }
    public function withdrawCondition(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('withdrawCondition', $v, $j, $m);
    }
    public function recordResult(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('recordMembershipResult', $v, $j, $m);
    }
    public function schedule(int $v, int $j): RedirectResponse
    {
        return $this->command('schedule', $v, $j);
    }
    public function start(int $v, int $j): RedirectResponse
    {
        return $this->command('start', $v, $j);
    }
    public function defer(int $v, int $j): RedirectResponse
    {
        return $this->command('defer', $v, $j);
    }
    public function resume(int $v, int $j): RedirectResponse
    {
        return $this->command('resume', $v, $j);
    }
    public function cancel(int $v, int $j): RedirectResponse
    {
        return $this->command('cancel', $v, $j);
    }
    public function complete(int $v, int $j): RedirectResponse
    {
        return $this->command('complete', $v, $j);
    }
    public function reopenJob(int $v, int $j): RedirectResponse
    {
        return $this->command('reopenJob', $v, $j);
    }

    public function repairPreview(int $v, int $j, int $m): string
    {
        $context = $this->jobContext($v, $j);
        $member = $this->ownedMember($context, $m);
        $condition = Services::vehicleDamageRepository()->item($context['companyId'], $v, (int) $member['vehicle_damage_item_id']);
        return $this->render('confirm_repair', $context + ['member' => $member, 'condition' => $condition]);
    }

    public function confirmRepair(int $v, int $j, int $m): RedirectResponse
    {
        return $this->command('confirmConditionRepaired', $v, $j, $m, '/fleet/vehicles/' . $v . '/damage-repairs/' . $j . '/conditions/' . $m . '/confirm-repair');
    }

    public function reopenConditionPreview(int $vehicle, int $item): string
    {
        $context = $this->context($vehicle);
        $condition = Services::vehicleDamageRepository()->item($context['companyId'], $vehicle, $item);
        if ($condition === null || $condition['status_code'] !== 'repaired' || $condition['current_condition_item_id'] !== null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $latest = Services::vehicleDamageRepairRepository()->latestConditionEvent($context['companyId'], $item, 'repaired');
        $repair = null;
        if (! empty($latest['repair_job_event_id'])) {
            $event = Services::vehicleDamageRepairRepository()->event($context['companyId'], $vehicle, (int) $latest['repair_job_event_id']);
            if ($event !== null) {
                $job = Services::vehicleDamageRepairRepository()->job($context['companyId'], $vehicle, (int) $event['vehicle_damage_repair_job_id']);
                $repair = ['job' => $job, 'membership_id' => $event['vehicle_damage_repair_job_item_id']];
            }
        }
        return $this->render('reopen_condition', $context + ['condition' => $condition, 'repairContext' => $repair]);
    }

    public function reopenCondition(int $vehicle, int $item): RedirectResponse
    {
        $result = Services::vehicleDamageService()->reopenRepairedCondition($this->company(), $vehicle, $item, $this->request->getPost(), $this->actor());
        return $this->result($vehicle, $result, '/fleet/vehicles/' . $vehicle . '/damage/' . $item . '/reopen', isset($this->request->getPost()['job_id']));
    }

    private function command(string $method, int $vehicle, int $job, ?int $member = null, ?string $failure = null): RedirectResponse
    {
        $data = $this->request->getPost();
        if ($member !== null) {
            $data['membership_id'] = $member;
        }
        $result = Services::vehicleDamageRepairService()->{$method}($this->company(), $vehicle, $job, $data, $this->actor());
        return $this->result($vehicle, $result, $failure ?? '/fleet/vehicles/' . $vehicle . '/damage-repairs/' . $job);
    }

    private function result(int $vehicle, array $result, string $failure, bool $jobResult = true): RedirectResponse
    {
        if (! $result['success']) {
            return CoreServices::redirectresponse()->to($failure)->with('damage_work_errors', $result['errors'])->with('damage_work_data', $this->request->getPost());
        }
        $target = $jobResult ? '/fleet/vehicles/' . $vehicle . '/damage-repairs/' . $result['id'] : '/fleet/vehicles/' . $vehicle . '#vehicle-damage';
        $message = ($result['replayed'] ?? false) ? 'Already saved. This retry created no additional history.' : ($jobResult ? 'Work history saved. Review physical condition separately.' : 'Condition reopened. Prior repair history is preserved.');
        return CoreServices::redirectresponse()->to($target)->with($jobResult ? 'damage_work_notice' : 'vehicle_damage_notice', $message);
    }

    private function context(int $vehicle): array
    {
        $company = $this->company();
        $owned = Services::vehicleDamageRepository()->vehicle($company, $vehicle);
        if ($owned === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        Services::vehicleDamageRepairRepository()->requireReady();
        $damage = Services::vehicleDamageReadService()->workspace($company, $vehicle);
        $conditions = array_merge($damage['current'], $damage['history']);
        return ['companyId' => $company, 'vehicle' => $owned, 'vehicleDamage' => $damage, 'work' => $damage['work'], 'conditionsById' => array_column($conditions, null, 'id'), 'fingerprints' => Services::vehicleDamageRepairRepository()->fingerprints($company, $conditions), 'vendors' => Services::vehicleDamageRepairRepository()->vendors($company), 'assets' => Services::assetManifestService()->appAssets(), 'notice' => CoreServices::session()->getFlashdata('damage_work_notice'), 'errors' => CoreServices::session()->getFlashdata('damage_work_errors') ?? [], 'formData' => CoreServices::session()->getFlashdata('damage_work_data') ?? []];
    }

    private function jobContext(int $vehicle, int $job): array
    {
        $context = $this->context($vehicle);
        $record = Services::vehicleDamageRepairRepository()->job($context['companyId'], $vehicle, $job);
        if ($record === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $context + ['job' => $record, 'members' => Services::vehicleDamageRepairRepository()->members($context['companyId'], $vehicle, $job), 'events' => Services::vehicleDamageRepairRepository()->events($context['companyId'], $vehicle, $job)];
    }

    private function ownedMember(array $context, int $id): array
    {
        foreach ($context['members'] as $member) {
            if ((int) $member['id'] === $id && $member['withdrawn_at'] === null) {
                return $member;
            }
        }
        throw PageNotFoundException::forPageNotFound();
    }

    private function company(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Work requires exactly one active fleet company.');
        }
        return $ids[0];
    }

    private function actor(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }
        return (int) $user->id;
    }

    private function render(string $template, array $data): string
    {
        return CoreServices::renderer()->setData($data + ['template' => $template])->render('vehicle_damage_repairs/page');
    }
}

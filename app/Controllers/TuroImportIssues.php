<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class TuroImportIssues extends BaseController
{
    public function index(): string
    {
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        return view('turo_import_issues/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'navigation' => $this->navigation(),
            'review' => service('turoImportIssueService')->review($this->filters(), $page),
            'notice' => session()->getFlashdata('turo_import_issue_notice'),
            'error' => session()->getFlashdata('turo_import_issue_error'),
        ]);
    }

    public function resolve(int $id): RedirectResponse
    {
        $resolved = service('turoImportIssueService')->resolve($id, $this->request->getPost('resolution_note'));

        return redirect()->back()->with(
            $resolved ? 'turo_import_issue_notice' : 'turo_import_issue_error',
            $resolved ? 'Issue marked resolved.' : 'That import issue could not be resolved.'
        );
    }

    public function reopen(int $id): RedirectResponse
    {
        $reopened = service('turoImportIssueService')->reopen($id, $this->request->getPost('resolution_note'));

        return redirect()->back()->with(
            $reopened ? 'turo_import_issue_notice' : 'turo_import_issue_error',
            $reopened ? 'Issue reopened.' : 'That import issue could not be reopened.'
        );
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'status' => $this->request->getGet('status'),
            'severity' => $this->request->getGet('severity'),
            'batch_id' => $this->request->getGet('batch_id'),
            'vehicle' => $this->request->getGet('vehicle'),
            'category' => $this->request->getGet('category'),
            'from' => $this->request->getGet('from'),
            'to' => $this->request->getGet('to'),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'true'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Decision Support', 'href' => '/#decision-support', 'active' => 'false'],
            ['label' => 'Fleet', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Reservations', 'href' => '/#fleet-timeline', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '/#financial-snapshot', 'active' => 'false'],
            ['label' => 'Maintenance', 'href' => '/#fleet-health', 'active' => 'false'],
        ];
    }
}

<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProjectTripIncidentalReviews extends BaseCommand
{
    protected $group = 'Operations';
    protected $name = 'incidentals:project';
    protected $description = 'Creates missing completed-trip incidental follow-ups after the activation cutoff.';
    protected $options = ['--user' => 'Optional Shield user id for creation audit provenance.'];

    public function run(array $params): int
    {
        $actor = $params['user'] ?? CLI::getOption('user');
        $created = \Config\Services::tripIncidentalReviewService()->projectEligible(is_numeric($actor) ? (int) $actor : null);
        if ($created > 0) {
            CLI::write("Created {$created} trip incidental review(s).", 'green');
        }
        return EXIT_SUCCESS;
    }
}

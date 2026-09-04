<?php

namespace App\Services\Fleet;

use Config\MovementIntelligence;

class PlanningHorizonService
{
    public function __construct(private readonly ?MovementIntelligence $config = null)
    {
    }

    public function classify(?\DateTimeImmutable $tripStartsAt, ?\DateTimeImmutable $asOf = null): string
    {
        if ($tripStartsAt === null) {
            return 'none';
        }
        $asOf ??= new \DateTimeImmutable();
        $hours = max(0, ($tripStartsAt->getTimestamp() - $asOf->getTimestamp()) / 3600);
        $policy = $this->config ?? new MovementIntelligence();
        if ($hours < $policy->immediateHours) {
            return 'immediate';
        }
        if ($hours < $policy->nearTermHours) {
            return 'near_term';
        }
        if ($hours <= $policy->mediumTermHours) {
            return 'medium_term';
        }
        return 'distant';
    }
}

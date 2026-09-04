<?php

namespace App\Services\Fleet;

use Config\MovementIntelligence;

class LocationClassificationService
{
    public const CLASSES = ['home', 'airport_hnl', 'waikiki_hotel', 'other_delivery', 'unknown'];

    public function __construct(private readonly ?MovementIntelligence $config = null)
    {
    }

    /** @return array<string, mixed> */
    public function classify(?string $sourceText, ?string $airportCode = null, ?int $airportId = null, ?int $workflowId = null): array
    {
        $text = trim((string) $sourceText);
        if (strtoupper(trim((string) $airportCode)) === 'HNL') {
            return $this->result('airport_hnl', $text, 'structured_airport', 'classified', $airportId, $workflowId);
        }
        $alias = $this->settings()->locationAliases[$this->normalize($text)] ?? null;
        if ($text !== '' && in_array($alias, self::CLASSES, true) && $alias !== 'unknown') {
            return $this->result($alias, $text, 'exact_alias', 'classified', $airportId, $workflowId);
        }
        return $this->result('unknown', $text === '' ? null : $text, 'unclassified', 'pending', $airportId, $workflowId);
    }

    /** @return array<string, mixed> */
    public function explicit(string $locationClass, ?string $detail): array
    {
        if (! in_array($locationClass, self::CLASSES, true)) {
            throw new \InvalidArgumentException('Choose a valid operational location.');
        }
        return $this->result($locationClass, trim((string) $detail) ?: null, 'operator', 'classified', null, null);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    /** @return array<string, mixed> */
    private function result(string $class, ?string $text, string $source, string $status, ?int $airportId, ?int $workflowId): array
    {
        return ['location_class' => $class, 'source_text' => $text, 'classification_source' => $source, 'classification_status' => $status, 'airport_id' => $airportId, 'airport_movement_workflow_id' => $workflowId];
    }

    private function settings(): MovementIntelligence
    {
        return $this->config ?? new MovementIntelligence();
    }
}

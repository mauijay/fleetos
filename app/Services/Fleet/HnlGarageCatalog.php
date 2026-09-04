<?php

namespace App\Services\Fleet;

class HnlGarageCatalog
{
    private const DEFINITIONS = [
        'international' => [
            'code' => 'international',
            'name' => 'International Garage',
            'color' => 'Blue',
            'levels' => 8,
            'rows' => ['F', 'G', 'H', 'J'],
            'approved_turo_garage' => true,
        ],
        'terminal_1' => [
            'code' => 'terminal_1',
            'name' => 'Terminal 1 Garage',
            'color' => 'Green',
            'levels' => 8,
            'rows' => ['A', 'B', 'C', 'D', 'E'],
            'approved_turo_garage' => false,
        ],
        'terminal_2' => [
            'code' => 'terminal_2',
            'name' => 'Terminal 2 Garage',
            'color' => 'Red',
            'levels' => 6,
            'rows' => ['K', 'L', 'M', 'N'],
            'approved_turo_garage' => false,
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string, mixed>|null */
    public function definition(?string $code): ?array
    {
        $canonical = $this->canonicalCode($code);

        return $canonical === null ? null : self::DEFINITIONS[$canonical];
    }

    public function garageForRow(?string $row): ?string
    {
        $normalized = strtoupper(trim((string) $row));
        foreach (self::DEFINITIONS as $code => $definition) {
            if (in_array($normalized, $definition['rows'], true)) {
                return $code;
            }
        }

        return null;
    }

    /** @return array{garage_code:string,level:int,row:string} */
    public function validate(?string $garageCode, mixed $level, ?string $row): array
    {
        $normalizedRow = strtoupper(trim((string) $row));
        $derivedGarage = $this->garageForRow($normalizedRow);
        $canonicalGarage = $this->canonicalCode($garageCode) ?? $derivedGarage;

        if ($canonicalGarage === null || $derivedGarage === null) {
            throw new \InvalidArgumentException('Choose a valid HNL garage and row.');
        }
        if ($canonicalGarage !== $derivedGarage) {
            throw new \InvalidArgumentException('The selected row does not belong to the selected HNL garage.');
        }

        $normalizedLevel = filter_var($level, FILTER_VALIDATE_INT);
        $definition = self::DEFINITIONS[$canonicalGarage];
        if ($normalizedLevel === false || $normalizedLevel < 1 || $normalizedLevel > $definition['levels']) {
            throw new \InvalidArgumentException('Choose a valid parking level for the selected HNL garage.');
        }

        return ['garage_code' => $canonicalGarage, 'level' => $normalizedLevel, 'row' => $normalizedRow];
    }

    /** @return array{garage_code:string,level:int,row:string}|null */
    public function parseLegacyDetail(?string $detail): ?array
    {
        $value = trim((string) $detail);
        if (preg_match('/^(International Garage|Terminal 1 Garage|Terminal 2 Garage) L([1-8]) R([A-N])$/', $value, $matches) !== 1) {
            return null;
        }

        try {
            return $this->validate($matches[1], $matches[2], $matches[3]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{garage_line:string,position_line:string,approved_turo_garage:bool}|null */
    public function presentation(?string $garageCode, mixed $level, ?string $row): ?array
    {
        try {
            $parking = $this->validate($garageCode, $level, $row);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $definition = self::DEFINITIONS[$parking['garage_code']];

        return [
            'garage_line' => $definition['name'] . ' · ' . $definition['color'],
            'position_line' => 'Level ' . $parking['level'] . ' · Row ' . $parking['row'],
            'approved_turo_garage' => $definition['approved_turo_garage'],
        ];
    }

    /** @param array{garage_code:string,level:int,row:string} $parking */
    public function locationDetail(array $parking): string
    {
        $validated = $this->validate($parking['garage_code'], $parking['level'], $parking['row']);
        $definition = self::DEFINITIONS[$validated['garage_code']];

        return $definition['name'] . ' L' . $validated['level'] . ' R' . $validated['row'];
    }

    private function canonicalCode(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        $aliases = [
            'international garage' => 'international',
            'hnl international parking garage' => 'international',
            'terminal 1 garage' => 'terminal_1',
            'terminal 2 garage' => 'terminal_2',
        ];
        $normalized = $aliases[$normalized] ?? $normalized;

        return isset(self::DEFINITIONS[$normalized]) ? $normalized : null;
    }
}

<?php
namespace App\Services;

class RezliveTestCaseMatcherService
{
    protected static $cases = [
        1 => [
            'rooms' => 1,
            'adults' => [2],
            'children' => [0],
            'nights' => 1,
        ],
        2 => [
            'rooms' => 1,
            'adults' => [3],
            'children' => [0],
            'nights' => 1,
        ],
        3 => [
            'rooms' => 1,
            'adults' => [2],
            'children' => [2],
            'child_ages' => [[2, 3]],
            'nights' => 2,
        ],
        4 => [
            'rooms' => 2,
            'adults' => [2, 3],
            'children' => [0, 0],
            'nights' => 1,
        ],
        5 => [
            'rooms' => 2,
            'adults' => [2, 2],
            'children' => [1, 2],
            'child_ages' => [[5], [3, 7]],
            'nights' => 1,
        ],
        6 => [
            'rooms' => 2,
            'adults' => [2, 2],
            'children' => [0, 0],
            'nights' => 3,
        ],
        7 => [
            'rooms' => 2,
            'adults' => [2, 2],
            'children' => [2, 2],
            'child_ages' => [[3, 4], [5, 6]],
            'nights' => 1,
        ],
        8 => [
            'rooms' => 2,
            'adults' => [3, 3],
            'children' => [0, 0],
            'nights' => 1,
        ],
        9 => [
            'rooms' => 2,
            'adults' => [3, 4],
            'children' => [0, 0],
            'nights' => 1,
        ],
        10 => [
            'rooms' => 3,
            'adults' => [2, 3, 3],
            'children' => [0, 0, 0],
            'nights' => 1,
        ],
        11 => [
            'rooms' => 3,
            'adults' => [2, 2, 3],
            'children' => [0, 1, 0],
            'child_ages' => [[], [3], []],
            'nights' => 1,
        ],
        12 => [
            'rooms' => 1,
            'adults' => [4],
            'children' => [0],
            'nights' => 1,
        ],
        13 => [
            'rooms' => 3,
            'adults' => [3, 3, 3],
            'children' => [0, 0, 0],
            'nights' => 2,
        ],
        14 => [
            'rooms' => 3,
            'adults' => [2, 2, 2],
            'children' => [0, 1, 2],
            'child_ages' => [[], [4], [5, 6]],
            'nights' => 10,
        ],
        15 => [
            'rooms' => 3,
            'adults' => [1, 2, 2],
            'children' => [0, 2, 2],
            'child_ages' => [[], [3, 5], [6, 12]],
            'nights' => 1,
        ],
    ];

    // public static function detectCase(array $params): ?int
    // {
    //     $params = self::normalizeRezliveParams($params);
    //     $requestedNights = self::calculateNights($params['check_in'], $params['check_out']);
    //     $rooms = $params['rooms'];
    //     $adults = array_values($params['adults']);
    //     $children = array_values($params['children']);
    //     $childAges = [];

    //     for ($i = 0; $i < $rooms; $i++) {
    //         $roomIndex = $i + 1;
    //         $childAges[] = $params['child_ages'][$roomIndex] ?? [];
    //     }

    //     foreach (self::$cases as $caseNumber => $rule) {
    //         if (
    //             $rooms === $rule['rooms'] &&
    //             $requestedNights === $rule['nights'] &&
    //             $adults === $rule['adults'] &&
    //             $children === $rule['children']
    //         ) {
    //             if (!isset($rule['child_ages'])) {
    //                 return $caseNumber;
    //             }

    //             if (self::compareChildAges($rule['child_ages'], $childAges)) {
    //                 return $caseNumber;
    //             }
    //         }
    //     }

    //     return null;
    // }

    public static function detectCase(array $params): ?int
    {
        $params = self::normalizeChildAges($params);
        // dd($params);
        $requestedNights = self::calculateNights($params['check_in'], $params['check_out']);
        $rooms = (int) ($params['rooms'] ?? 0);

        // Safely normalize adults and children arrays
        $adults = is_array($params['adults']) 
            ? array_map('intval', array_values($params['adults'])) 
            : array_fill(0, $rooms, 0);

        $children = is_array($params['children']) 
            ? array_map('intval', array_values($params['children'])) 
            : array_fill(0, $rooms, 0);

        // Normalize child_ages per room
        $childAges = [];
        for ($i = 0; $i < $rooms; $i++) {
            $roomIndex = $i + 1;
            $childAges[] = isset($params['child_ages'][$roomIndex]) && is_array($params['child_ages'][$roomIndex])
                ? array_map('intval', array_values($params['child_ages'][$roomIndex]))
                : [];
        }

        // Define test cases
        $testCases = [
            1 => ['rooms' => 1, 'adults' => [2], 'children' => [0], 'child_ages' => [[]], 'nights' => 1],
            2 => ['rooms' => 1, 'adults' => [3], 'children' => [0], 'child_ages' => [[]], 'nights' => 1],
            3 => ['rooms' => 1, 'adults' => [2], 'children' => [2], 'child_ages' => [[2,3]], 'nights' => 2],
            4 => ['rooms' => 2, 'adults' => [2, 3], 'children' => [0, 0], 'child_ages' => [[], []], 'nights' => 1],
            5 => ['rooms' => 2, 'adults' => [2, 2], 'children' => [1, 2], 'child_ages' => [[5], [3, 7]], 'nights' => 1],
            6 => ['rooms' => 2, 'adults' => [2, 2], 'children' => [0, 0], 'child_ages' => [[], []], 'nights' => 3],
            7 => ['rooms' => 2, 'adults' => [2, 2], 'children' => [2, 2], 'child_ages' => [[3, 4], [5, 6]], 'nights' => 1],
            8 => ['rooms' => 2, 'adults' => [3, 3], 'children' => [0, 0], 'child_ages' => [[], []], 'nights' => 1],
            9 => ['rooms' => 2, 'adults' => [3, 4], 'children' => [0, 0], 'child_ages' => [[], []], 'nights' => 1],
            10 => ['rooms' => 3, 'adults' => [2, 3, 3], 'children' => [0, 0, 0], 'child_ages' => [[], [], []], 'nights' => 1],
            11 => ['rooms' => 3, 'adults' => [2, 2, 3], 'children' => [0, 1, 0], 'child_ages' => [[], [3], []], 'nights' => 1],
            12 => ['rooms' => 1, 'adults' => [4], 'children' => [0], 'child_ages' => [[]], 'nights' => 1],
            13 => ['rooms' => 3, 'adults' => [3, 3, 3], 'children' => [0, 0, 0], 'child_ages' => [[], [], []], 'nights' => 2],
            14 => ['rooms' => 3, 'adults' => [2, 2, 2], 'children' => [0, 1, 2], 'child_ages' => [[], [4], [5,6]], 'nights' => 10],
            15 => ['rooms' => 3, 'adults' => [1, 2, 2], 'children' => [0, 2, 2], 'child_ages' => [[], [3, 5], [6, 12]], 'nights' => 1],
        ];

        // Compare each case with normalized input
        foreach ($testCases as $caseNumber => $case) {
            if (
                $case['rooms'] === $rooms &&
                $case['nights'] === $requestedNights &&
                json_encode($case['adults']) === json_encode($adults) &&
                json_encode($case['children']) === json_encode($children) &&
                json_encode($case['child_ages']) === json_encode($childAges)
            ) {
                return $caseNumber;
            }
        }

        return null;
    }

    public static function normalizeChildAges(array $data): array
    {
        $normalized = $data;

        // Ensure child_ages exists and is an array
        if (!isset($normalized['child_ages']) || !is_array($normalized['child_ages'])) {
            $normalized['child_ages'] = [];
        }

        // Loop through each room number
        for ($i = 1; $i <= $normalized['rooms']; $i++) {
            if (!isset($normalized['child_ages'][$i])) {
                $normalized['child_ages'][$i] = [];
            }
        }
        ksort($normalized['child_ages']);

        return $normalized;
    }

    public static function normalizeRezliveParams(array $params): array
    {
        $rooms = $params['rooms'];

        $params['adults'] = is_array($params['adults']) ? $params['adults'] : array_fill(1, $rooms, (int) $params['adults']);
        $params['children'] = is_array($params['children']) ? $params['children'] : array_fill(1, $rooms, (int) $params['children']);
        $params['child_ages'] = is_array($params['child_ages'] ?? null) ? $params['child_ages'] : [];

        return $params;
    }

    protected static function calculateNights($checkIn, $checkOut): int
    {
        $in = \Carbon\Carbon::createFromFormat('d/m/Y', $checkIn);
        $out = \Carbon\Carbon::createFromFormat('d/m/Y', $checkOut);
        return $in->diffInDays($out);
    }

    protected static function compareChildAges(array $expected, array $actual): bool
    {
        foreach ($expected as $index => $ages) {
            $a1 = array_values($ages);
            $a2 = array_values($actual[$index] ?? []);
            sort($a1);
            sort($a2);
            if ($a1 !== $a2) return false;
        }
        return true;
    }
}

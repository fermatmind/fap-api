<?php

namespace App\Support\Stats;

class JsonSchemaGuard
{
    /**
     * @return array<int, string>
     */
    public static function validateNormsFile(array $data): array
    {
        $errors = [];

        self::requireKeys($data, ['meta', 'buckets'], '$', $errors);

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        self::requireKeys($meta, ['norm_id', 'version'], '$.meta', $errors);

        if (!isset($data['buckets']) || !is_array($data['buckets'])) {
            $errors[] = '$.buckets must be an array';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public static function validateScoringSpec(array $data): array
    {
        $errors = [];

        self::requireKeys($data, ['version'], '$', $errors);
        if (!isset($data['dimensions']) || !is_array($data['dimensions'])) {
            $errors[] = '$.dimensions must be an array';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public static function validateInterpretationSpec(array $data): array
    {
        $errors = [];

        self::requireKeys($data, ['version'], '$', $errors);
        if (!isset($data['thresholds']) || !is_array($data['thresholds'])) {
            $errors[] = '$.thresholds must be an array';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public static function validateQualityChecks(array $data): array
    {
        $errors = [];

        self::requireKeys($data, ['version', 'checks'], '$', $errors);
        if (!isset($data['checks']) || !is_array($data['checks'])) {
            $errors[] = '$.checks must be an array';
        }

        return $errors;
    }

    /**
     * @param array<int, string> $errors
     */
    private static function requireKeys(array $data, array $keys, string $path, array &$errors): void
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) {
                $errors[] = $path . ' missing key: ' . $k;
            }
        }
    }
}

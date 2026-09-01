<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

final class RobotsPolicyEvaluator
{
    public function allows(string $robots, string $path, string $agent = 'FermatMindCompetitiveEvidence'): bool
    {
        $groups = $this->groups($robots);
        $agent = strtolower($agent);
        $specificity = -1;
        $rules = [];
        foreach ($groups as $group) {
            $matches = array_filter($group['agents'], static fn (string $candidate): bool => $candidate === '*' || str_contains($agent, $candidate));
            if ($matches === []) {
                continue;
            }
            $groupSpecificity = max(array_map(static fn (string $candidate): int => $candidate === '*' ? 0 : strlen($candidate), $matches));
            if ($groupSpecificity > $specificity) {
                $specificity = $groupSpecificity;
                $rules = $group['rules'];
            } elseif ($groupSpecificity === $specificity) {
                $rules = array_merge($rules, $group['rules']);
            }
        }
        if ($specificity < 0) {
            return true;
        }

        $winner = null;
        foreach ($rules as $rule) {
            if ($rule['pattern'] === '' || ! $this->matches($path, $rule['pattern'])) {
                continue;
            }
            $length = strlen(str_replace(['*', '$'], '', $rule['pattern']));
            if ($winner === null || $length > $winner['length'] || $length === $winner['length'] && $rule['allow']) {
                $winner = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $winner === null || $winner['allow'];
    }

    /** @return list<array{agents:list<string>,rules:list<array{allow:bool,pattern:string}>}> */
    private function groups(string $robots): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $rulesStarted = false;
        $flush = static function () use (&$groups, &$agents, &$rules, &$rulesStarted): void {
            if ($agents !== []) {
                $groups[] = ['agents' => array_values(array_unique($agents)), 'rules' => $rules];
            }
            $agents = [];
            $rules = [];
            $rulesStarted = false;
        };
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($rulesStarted) {
                    $flush();
                }
                $agents[] = strtolower($value);
            } elseif (($field === 'allow' || $field === 'disallow') && $agents !== []) {
                $rulesStarted = true;
                $rules[] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }
        $flush();

        return $groups;
    }

    private function matches(string $path, string $pattern): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $expression = '#^'.str_replace('\\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#u';

        return preg_match($expression, $path) === 1;
    }
}

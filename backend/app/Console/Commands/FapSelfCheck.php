<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FapSelfCheck extends Command
{
    /**
     * 运行方式：
     * php artisan fap:self-check
     * php artisan fap:self-check --pkg=MBTI-CN-v0.2.1-TEST
     */
    protected $signature = 'fap:self-check {--pkg= : Override MBTI content package folder name}';

    protected $description = 'Quick self-check for MBTI content package (questions.json + type_profiles.json)';

    public function handle(): int
    {
        $pkg = $this->option('pkg') ?: env('MBTI_CONTENT_PACKAGE', 'MBTI-CN-v0.2.1-TEST');

        $this->info("FAP Self Check");
        $this->line("Package: <comment>{$pkg}</comment>");
        $this->line(str_repeat('-', 60));

        $ok = true;

        // 1) questions.json
        $questionsPath = $this->contentPackagePath($pkg, 'questions.json');
        [$qOk, $qMsg] = $this->checkQuestions($questionsPath);
        $ok = $ok && $qOk;
        $this->printSectionResult('questions.json', $qOk, $qMsg);

        // 2) type_profiles.json
        $profilesPath = $this->contentPackagePath($pkg, 'type_profiles.json');
        [$pOk, $pMsg] = $this->checkTypeProfiles($profilesPath);
        $ok = $ok && $pOk;
        $this->printSectionResult('type_profiles.json', $pOk, $pMsg);

        $this->line(str_repeat('-', 60));
        if ($ok) {
            $this->info("✅ SELF-CHECK PASSED");
            return 0;
        }

        $this->error("❌ SELF-CHECK FAILED (see errors above)");
        return 1;
    }

    /**
     * 内容包文件路径（统一入口）
     * Laravel base_path() = backend/，内容包在仓库根目录 content_packages/
     */
    private function contentPackagePath(string $pkg, string $file): string
    {
        return base_path("../content_packages/{$pkg}/{$file}");
    }

    private function checkQuestions(string $path): array
    {
        if (!is_file($path)) {
            return [false, ["File not found: {$path}"]];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [false, ["File empty/unreadable: {$path}"]];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [false, ["Invalid JSON: {$path}"]];
        }

        $items = isset($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            return [false, ["Invalid items structure: expect array or {items:[]}"]];
        }

        // active filter + sort by order
        $items = array_values(array_filter($items, fn($q) => ($q['is_active'] ?? true) === true));
        usort($items, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $errors = [];

        if (count($items) !== 144) {
            $errors[] = "Active questions must be 144, got: " . count($items);
        }

        $validDims = ['EI','SN','TF','JP','AT'];
        $seenQid = [];
        $orders = [];

        foreach ($items as $i => $q) {
            $idx = $i + 1;

            $qid = $q['question_id'] ?? null;
            if (!$qid) $errors[] = "#{$idx}: missing question_id";
            if ($qid && isset($seenQid[$qid])) $errors[] = "#{$idx}: duplicate question_id {$qid}";
            if ($qid) $seenQid[$qid] = true;

            $order = $q['order'] ?? null;
            if (!is_int($order) && !is_numeric($order)) $errors[] = "#{$idx}: invalid order";
            if (is_numeric($order)) $orders[] = (int)$order;

            $dim = $q['dimension'] ?? null;
            if (!in_array($dim, $validDims, true)) $errors[] = "#{$idx} ({$qid}): invalid dimension {$dim}";

            $text = $q['text'] ?? null;
            if (!is_string($text) || trim($text) === '') $errors[] = "#{$idx} ({$qid}): missing text";

            // internal scoring fields (for storeAttempt)
            $keyPole = $q['key_pole'] ?? null;
            if (!is_string($keyPole) || $keyPole === '') {
                $errors[] = "#{$idx} ({$qid}): missing key_pole";
            }

            $direction = $q['direction'] ?? null;
            if (!in_array((int)$direction, [1, -1], true)) {
                $errors[] = "#{$idx} ({$qid}): direction must be 1 or -1";
            }

            $opts = $q['options'] ?? null;
            if (!is_array($opts) || count($opts) < 2) {
                $errors[] = "#{$idx} ({$qid}): options invalid";
                continue;
            }

            $needCodes = ['A','B','C','D','E'];
            $optMap = [];
            foreach ($opts as $o) {
                $c = strtoupper((string)($o['code'] ?? ''));
                if ($c === '') continue;
                $optMap[$c] = $o;
            }

            foreach ($needCodes as $c) {
                if (!isset($optMap[$c])) {
                    $errors[] = "#{$idx} ({$qid}): missing option code {$c}";
                    continue;
                }
                $t = $optMap[$c]['text'] ?? null;
                if (!is_string($t) || trim($t) === '') {
                    $errors[] = "#{$idx} ({$qid}): option {$c} missing text";
                }
                if (!array_key_exists('score', $optMap[$c]) || !is_numeric($optMap[$c]['score'])) {
                    $errors[] = "#{$idx} ({$qid}): option {$c} missing numeric score";
                }
            }
        }

        // order sanity (optional, but good)
        if (count($orders) > 0) {
            $min = min($orders);
            $max = max($orders);
            if ($min !== 1 || $max !== 144) {
                $errors[] = "Order range should be 1..144, got {$min}..{$max}";
            }
        }

        if (!empty($errors)) {
            return [false, $errors];
        }

        return [true, ["OK (144 active questions, A~E options + scoring fields present)"]];
    }

    private function checkTypeProfiles(string $path): array
    {
        if (!is_file($path)) {
            return [false, ["File not found: {$path}"]];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [false, ["File empty/unreadable: {$path}"]];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [false, ["Invalid JSON: {$path}"]];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) {
            return [false, ["Invalid items: expect {items:{...}}"]];
        }

        $errors = [];

        // expected 32 keys
        $expected = $this->expectedTypeCodes32();
        $keys = array_keys($items);

        // key format check
        foreach ($keys as $k) {
            if (!preg_match('/^(E|I)(S|N)(T|F)(J|P)-(A|T)$/', $k)) {
                $errors[] = "Invalid type key format: {$k}";
            }
        }

        // missing / extra
        $missing = array_values(array_diff($expected, $keys));
        $extra   = array_values(array_diff($keys, $expected));

        if (count($missing) > 0) {
            $errors[] = "Missing types: " . implode(', ', $missing);
        }
        if (count($extra) > 0) {
            $errors[] = "Extra types: " . implode(', ', $extra);
        }

        if (count($items) !== 32) {
            $errors[] = "items count must be 32, got: " . count($items);
        }

        // required fields
        foreach ($expected as $code) {
            if (!isset($items[$code]) || !is_array($items[$code])) {
                continue;
            }
            $p = $items[$code];

            if (($p['type_code'] ?? null) !== $code) {
                $errors[] = "{$code}: type_code mismatch";
            }
            if (!isset($p['type_name']) || !is_string($p['type_name']) || trim($p['type_name']) === '') {
                $errors[] = "{$code}: missing type_name";
            }
            if (!isset($p['tagline']) || !is_string($p['tagline']) || trim($p['tagline']) === '') {
                $errors[] = "{$code}: missing tagline";
            }
            // optional but recommended fields
            if (isset($p['keywords']) && !is_array($p['keywords'])) {
                $errors[] = "{$code}: keywords must be array";
            }
        }

        if (!empty($errors)) {
            return [false, $errors];
        }

        return [true, ["OK (32 types, all required fields present)"]];
    }

    private function expectedTypeCodes32(): array
    {
        $first = ['E','I'];
        $second = ['S','N'];
        $third = ['T','F'];
        $fourth = ['J','P'];
        $suffix = ['A','T'];

        $out = [];
        foreach ($first as $a) {
            foreach ($second as $b) {
                foreach ($third as $c) {
                    foreach ($fourth as $d) {
                        foreach ($suffix as $s) {
                            $out[] = "{$a}{$b}{$c}{$d}-{$s}";
                        }
                    }
                }
            }
        }
        sort($out);
        return $out;
    }

    private function printSectionResult(string $name, bool $ok, array $messages): void
    {
        if ($ok) {
            $this->info("✅ {$name}");
            foreach ($messages as $m) {
                $this->line("  - {$m}");
            }
            return;
        }

        $this->error("❌ {$name}");
        foreach ($messages as $m) {
            $this->line("  - {$m}");
        }
    }
}
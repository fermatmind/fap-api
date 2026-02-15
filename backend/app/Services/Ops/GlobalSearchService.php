<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

class GlobalSearchService
{
    /**
     * @return array{query:string,items:list<array<string,mixed>>,elapsed_ms:int}
     */
    public function search(string $query): array
    {
        $startedAt = microtime(true);
        $needle = trim($query);
        if ($needle === '') {
            return [
                'query' => '',
                'items' => [],
                'elapsed_ms' => 0,
            ];
        }

        $items = [];

        if (\App\Support\SchemaBaseline::hasTable('orders')) {
            $orders = DB::table('orders')
                ->select(['org_id', 'order_no', 'status', 'updated_at'])
                ->where('order_no', 'like', '%'.$needle.'%')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();

            foreach ($orders as $row) {
                $items[] = [
                    'type' => 'order',
                    'label' => (string) $row->order_no,
                    'subtitle' => 'status='.(string) ($row->status ?? ''),
                    'org_id' => (int) ($row->org_id ?? 0),
                    'masked' => false,
                    'url' => '/ops/orders',
                ];
            }
        }

        if (\App\Support\SchemaBaseline::hasTable('attempts')) {
            $attempts = DB::table('attempts')
                ->select(['org_id', 'id', 'updated_at'])
                ->where('id', 'like', '%'.$needle.'%')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();

            foreach ($attempts as $row) {
                $items[] = [
                    'type' => 'attempt',
                    'label' => (string) $row->id,
                    'subtitle' => 'attempt_id',
                    'org_id' => (int) ($row->org_id ?? 0),
                    'masked' => false,
                    'url' => '/ops/order-lookup',
                ];
            }
        }

        if (\App\Support\SchemaBaseline::hasTable('shares')) {
            $shares = DB::table('shares')
                ->select(['org_id', 'id', 'updated_at'])
                ->where('id', 'like', '%'.$needle.'%')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();

            foreach ($shares as $row) {
                $items[] = [
                    'type' => 'share',
                    'label' => (string) $row->id,
                    'subtitle' => 'share_id',
                    'org_id' => (int) ($row->org_id ?? 0),
                    'masked' => false,
                    'url' => '/ops/order-lookup',
                ];
            }
        }

        if (\App\Support\SchemaBaseline::hasTable('users')) {
            $users = DB::table('users')
                ->select(['id', 'email', 'updated_at'])
                ->whereNotNull('email')
                ->where('email', 'like', '%'.$needle.'%')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();

            foreach ($users as $row) {
                $email = trim((string) ($row->email ?? ''));
                if ($email === '') {
                    continue;
                }

                $items[] = [
                    'type' => 'user_email',
                    'label' => $this->maskEmail($email),
                    'subtitle' => 'user_id='.(string) $row->id,
                    'org_id' => 0,
                    'masked' => true,
                    'url' => '/ops/order-lookup',
                ];
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'query' => $needle,
            'items' => array_slice($items, 0, 30),
            'elapsed_ms' => $elapsedMs,
        ];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        if (strlen($name) <= 2) {
            $maskedName = substr($name, 0, 1).'*';
        } else {
            $maskedName = substr($name, 0, 1).str_repeat('*', max(1, strlen($name) - 2)).substr($name, -1);
        }

        return $maskedName.'@'.$domain;
    }
}

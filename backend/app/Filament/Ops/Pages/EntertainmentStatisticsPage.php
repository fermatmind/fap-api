<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Services\Analytics\EntertainmentActivity;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class EntertainmentStatisticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'entertainment-statistics';

    protected static string $view = 'filament.ops.pages.entertainment-statistics';

    public string $fromDate = '';

    public string $toDate = '';

    public string $test = '*';

    public string $locale = '*';

    public string $role = '*';

    public int $offset = 0;

    public int $totalRows = 0;

    public array $catalog = [];

    public string $catalogSource = 'baseline';

    public array $rows = [];

    public array $summary = [];

    public ?string $warning = null;

    public static function canAccess(): bool
    {
        return OpsMetricsAccess::canViewTestMetrics();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('entertainment-activity.title');
    }

    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->toDate = CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $this->fromDate = CarbonImmutable::now('Asia/Shanghai')->subDays(6)->toDateString();
        $this->applyFilters();
    }

    public function applyFilters(): void
    {
        $this->offset = 0;
        $this->refreshRows();
    }

    public function movePage(int $direction): void
    {
        $this->offset = max(0, min(max(0, $this->totalRows - 1), $this->offset + ($direction > 0 ? 50 : -50)));
        $this->refreshRows();
    }

    private function refreshRows(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = app(EntertainmentActivity::class)->catalogState();
        $this->catalog = $state['items'];
        $this->catalogSource = $state['source'];
        $this->validate([
            'fromDate' => ['required', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:fromDate', 'before_or_equal:'.CarbonImmutable::now('Asia/Shanghai')->toDateString()],
            'test' => [Rule::in(['*', ...array_column($this->catalog, 'slug')])],
            'locale' => [Rule::in(['*', 'en', 'zh'])],
            'role' => [Rule::in(['*', 'single', 'creator', 'friend', 'solo'])],
        ]);
        $start = CarbonImmutable::parse($this->fromDate, 'Asia/Shanghai')->startOfDay();
        $end = CarbonImmutable::parse($this->toDate, 'Asia/Shanghai')->addDay()->startOfDay();
        $this->warning = null;
        $this->rows = [];
        $this->summary = array_fill_keys(EntertainmentActivity::EVENTS, 0);
        if ($start->diffInDays($end) > 90) {
            $this->warning = __('entertainment-activity.range_limit');
            $this->totalRows = 0;

            return;
        }
        if (! SchemaBaseline::hasTable('events')) {
            $this->warning = __('entertainment-activity.unavailable');

            return;
        }
        $expressions = [];
        foreach (['slug', 'version', 'role', 'method', 'excluded'] as $field) {
            $extract = "json_extract(meta_json, '$.$field')";
            $expressions[$field] = DB::connection()->getDriverName() === 'sqlite' ? 'CAST('.$extract.' AS TEXT)' : 'JSON_UNQUOTE('.$extract.')';
        }
        $query = DB::table('events')->where('org_id', 0)
            ->whereIn('event_code', EntertainmentActivity::EVENTS)
            ->where('occurred_at', '>=', $start->utc())->where('occurred_at', '<', $end->utc())
            ->whereRaw($expressions['excluded']." IN ('false', '0')")
            ->select(['event_code', 'locale'])->selectRaw('COUNT(*) as count');
        foreach (['slug', 'version', 'role', 'method'] as $field) {
            $query->selectRaw($expressions[$field].' as '.$field)->groupByRaw($expressions[$field]);
        }
        if ($this->test !== '*') {
            $query->whereRaw($expressions['slug'].' = ?', [$this->test]);
        }
        if ($this->role !== '*') {
            $query->whereRaw($expressions['role'].' = ?', [$this->role]);
        }
        if ($this->locale !== '*') {
            $query->where('locale', $this->locale);
        }
        $counts = [];
        foreach ($query->groupBy('event_code', 'locale')->get() as $event) {
            $key = $event->slug.'|'.$event->locale.'|'.$event->role;
            $counts[$key][$event->event_code] = ($counts[$key][$event->event_code] ?? 0) + (int) $event->count;
            $counts[$key]['versions'][$event->version] = true;
            if ($event->event_code === 'fun_share') {
                $counts[$key]['methods'][$event->method] = ($counts[$key]['methods'][$event->method] ?? 0) + (int) $event->count;
            }
            $this->summary[$event->event_code] += (int) $event->count;
        }
        $all = [];
        foreach ($this->catalog as $test) {
            if ($this->test !== '*' && $this->test !== $test['slug']) {
                continue;
            }
            foreach ($this->locale === '*' ? ['en', 'zh'] : [$this->locale] as $locale) {
                foreach ($test['roles'] as $role) {
                    if ($this->role !== '*' && $this->role !== $role) {
                        continue;
                    }
                    $row = $counts[$test['slug'].'|'.$locale.'|'.$role] ?? [];
                    $all[] = ['slug' => $test['slug'], 'title' => $test['title'][app()->getLocale() === 'en' ? 'en' : 'zh'],
                        'locale' => $locale, 'role' => $role, 'privacy' => $test['privacy'],
                        'versions' => implode(', ', array_keys($row['versions'] ?? [])), 'methods' => $row['methods'] ?? [],
                        'counts' => array_replace(array_fill_keys(EntertainmentActivity::EVENTS, 0), array_intersect_key($row, $this->summary))];
                }
            }
        }
        $this->totalRows = count($all);
        $this->rows = array_slice($all, $this->offset, 50);
    }
}

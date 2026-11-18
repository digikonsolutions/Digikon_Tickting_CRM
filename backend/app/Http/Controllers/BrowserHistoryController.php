<?php

namespace App\Http\Controllers;

use App\Models\BrowserHistoryEvent;
use App\Services\MonitoringPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Browser tab history.
 *
 * - Employees: `status` and `sync` only. They can add their own events (after accepting the notice that
 *   lists "browser tab history") and nothing else: no read, edit or delete.
 * - Administrators: `adminSummary` and `adminEvents`, strictly read-only. There is no route, policy ability
 *   or model operation that lets anyone edit or delete a history row.
 */
class BrowserHistoryController extends Controller
{
    private const MAX_BATCH = 200;

    private const VISIT_TYPES = ['navigated', 'tab_opened'];

    /* ---- employee (extension) --------------------------------------------------------------- */

    public function status(Request $request, MonitoringPolicy $policy): JsonResponse
    {
        return response()->json([
            'tracking_allowed' => $this->trackingAllowed($request, $policy),
            'policy_version' => $policy->version(),
        ]);
    }

    public function sync(Request $request, MonitoringPolicy $policy): JsonResponse
    {
        if (! $this->trackingAllowed($request, $policy)) {
            return response()->json([
                'message' => 'Browser history is not recorded until you accept the current monitoring notice.',
                'consent_required' => true,
            ], 403);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'events.*.client_event_id' => ['required', 'string', 'max:64'],
            'events.*.event_type' => ['required', Rule::in(BrowserHistoryEvent::TYPES)],
            'events.*.tab_session_id' => ['required', 'string', 'max:64'],
            'events.*.tab_id' => ['nullable', 'integer', 'min:0'],
            'events.*.url' => ['required', 'string', 'max:4096'],
            'events.*.title' => ['nullable', 'string', 'max:1000'],
            'events.*.favicon_url' => ['nullable', 'string', 'max:500'],
            'events.*.duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);

        $userId = $request->user()->id;
        $earliest = CarbonImmutable::now()->subDays((int) config('activity.retention_days'));
        $latest = CarbonImmutable::now()->addMinutes(5);
        $rows = [];
        $skipped = 0;

        foreach ($data['events'] as $event) {
            $clean = $this->sanitizeUrl($event['url']);
            $occurredAt = CarbonImmutable::parse($event['occurred_at']);
            // Only plain web pages: never chrome://, file://, extension pages, or an unreadable URL.
            if ($clean === null || $occurredAt->lessThan($earliest) || $occurredAt->greaterThan($latest)) {
                $skipped++;

                continue;
            }
            $favicon = $event['favicon_url'] ?? null;
            $rows[] = [
                'user_id' => $userId,
                'client_event_id' => $event['client_event_id'],
                'event_type' => $event['event_type'],
                'tab_session_id' => $event['tab_session_id'],
                'tab_id' => $event['tab_id'] ?? null,
                'url' => $clean['url'],
                'domain' => $clean['domain'],
                'title' => isset($event['title']) ? mb_substr(trim($event['title']), 0, 255) : null,
                'favicon_url' => is_string($favicon) && str_starts_with($favicon, 'https://') ? $favicon : null,
                'duration_seconds' => $event['duration_seconds'] ?? null,
                'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s.v'),
            ];
        }

        // The unique (user_id, client_event_id) index makes a re-sent batch a no-op for rows already stored.
        $inserted = $rows === [] ? 0 : (int) BrowserHistoryEvent::query()->insertOrIgnore($rows);

        return response()->json([
            'accepted' => $inserted,
            'duplicates' => count($rows) - $inserted,
            'skipped' => $skipped,
        ]);
    }

    /* ---- admin: read only ------------------------------------------------------------------- */

    public function adminSummary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', BrowserHistoryEvent::class);
        $query = $this->filtered($request);

        $visits = (clone $query)->whereIn('event_type', self::VISIT_TYPES)->count();
        $uniqueDomains = (int) (clone $query)->distinct()->count('domain');
        $tabsOpened = (clone $query)->where('event_type', 'tab_opened')->count();
        $seconds = (int) (clone $query)->sum('duration_seconds');

        $topDomains = (clone $query)->reorder()
            ->selectRaw("domain, SUM(CASE WHEN event_type IN ('navigated','tab_opened') THEN 1 ELSE 0 END) AS visits, COALESCE(SUM(duration_seconds), 0) AS seconds")
            ->groupBy('domain')->orderByDesc('visits')->orderByDesc('seconds')->limit(10)->get()
            ->map(fn ($row): array => ['domain' => $row->domain, 'visits' => (int) $row->visits, 'seconds' => (int) $row->seconds])->values();

        $daily = (clone $query)->reorder()
            ->selectRaw("DATE(occurred_at) AS day, SUM(CASE WHEN event_type IN ('navigated','tab_opened') THEN 1 ELSE 0 END) AS visits, COALESCE(SUM(duration_seconds), 0) AS seconds")
            ->groupByRaw('DATE(occurred_at)')->orderBy('day')->get()
            ->map(fn ($row): array => ['date' => (string) $row->day, 'visits' => (int) $row->visits, 'seconds' => (int) $row->seconds])->values();

        return response()->json([
            'totals' => ['visits' => $visits, 'unique_domains' => $uniqueDomains, 'tabs_opened' => $tabsOpened, 'seconds' => $seconds],
            'top_domains' => $topDomains,
            'daily' => $daily,
        ]);
    }

    public function adminEvents(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', BrowserHistoryEvent::class);
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 50;

        $page = $this->filtered($request)
            ->with('user:id,name,photo_path,avatar_url')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'events' => $page->getCollection()->map(fn (BrowserHistoryEvent $event): array => [
                'id' => $event->id,
                'employee' => ['id' => $event->user_id, 'name' => $event->user?->name, 'photo_url' => $event->user?->photoUrl()],
                'event_type' => $event->event_type,
                'tab_session_id' => $event->tab_session_id,
                'tab_id' => $event->tab_id,
                'url' => $event->url,
                'domain' => $event->domain,
                'title' => $event->title,
                'favicon_url' => $event->favicon_url,
                'duration_seconds' => $event->duration_seconds,
                'occurred_at' => $event->occurred_at->toISOString(),
            ])->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /* ---- helpers ---------------------------------------------------------------------------- */

    private function trackingAllowed(Request $request, MonitoringPolicy $policy): bool
    {
        return $policy->allows('browser_history') && $policy->hasConsented($request->user());
    }

    /**
     * @return Builder<BrowserHistoryEvent>
     */
    private function filtered(Request $request): Builder
    {
        $f = $request->validate([
            'employee_id' => ['sometimes', 'integer'],
            'agency_id' => ['sometimes', 'integer'],
            'domain' => ['sometimes', 'string', 'max:255'],
            'q' => ['sometimes', 'string', 'max:200'],
            'event_type' => ['sometimes', Rule::in(BrowserHistoryEvent::TYPES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = (string) config('app.timezone');

        return BrowserHistoryEvent::query()
            ->when($f['employee_id'] ?? null, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($f['agency_id'] ?? null, function (Builder $q, int $agencyId): void {
                // Employees assigned to this agency at the time of the event.
                $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('employee_assignments as ea')
                    ->whereColumn('ea.employee_id', 'browser_history_events.user_id')
                    ->where('ea.agency_id', $agencyId)
                    ->whereColumn('ea.started_at', '<=', 'browser_history_events.occurred_at')
                    ->where(fn ($w) => $w->whereNull('ea.ended_at')->orWhereColumn('ea.ended_at', '>=', 'browser_history_events.occurred_at')));
            })
            ->when($f['domain'] ?? null, fn (Builder $q, string $d) => $q->where('domain', 'like', '%'.addcslashes(strtolower($d), '%_\\').'%'))
            ->when($f['q'] ?? null, fn (Builder $q, string $s) => $q->where(fn ($w) => $w
                ->where('domain', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('url', 'like', '%'.addcslashes($s, '%_\\').'%')))
            ->when($f['event_type'] ?? null, fn (Builder $q, string $t) => $q->where('event_type', $t))
            ->when($f['from'] ?? null, fn (Builder $q, string $d) => $q->where('occurred_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn (Builder $q, string $d) => $q->where('occurred_at', '<=', CarbonImmutable::parse($d, $tz)->endOfDay()->utc()));
    }

    /**
     * Keeps scheme, host and path only: the query string and fragment (tokens, search terms, ids) are
     * dropped before anything is stored. Returns null for anything that is not a normal http(s) page.
     *
     * @return array{url: string, domain: string}|null
     */
    private function sanitizeUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        $clean = strtolower($parts['scheme']).'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');

        return ['url' => mb_substr($clean, 0, 2048), 'domain' => mb_substr($host, 0, 255)];
    }
}

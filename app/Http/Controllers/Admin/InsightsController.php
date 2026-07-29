<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppEvent;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\View\View;

/**
 * Instance-wide product analytics (SuperAdmin only): who's active, which
 * features get used, which screens get seen, and where generation volume
 * comes from. Reads two sources — app_events (feature usage + page views,
 * see AppEvent::record) and credit_transactions (the volume/spend ledger,
 * never duplicated into events). Everything is grouped aggregate queries
 * over a fixed 30-day window; no N+1, no client-side chart library.
 */
class InsightsController extends Controller
{
    private const WINDOW_DAYS = 30;

    public function index(): View
    {
        $since = now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        $charges = CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(characters), 0) as chars, COUNT(*) as calls')
            ->first();

        $tiles = [
            'active_7' => User::where('last_active_at', '>=', now()->subDays(7))->count(),
            'active_30' => User::where('last_active_at', '>=', now()->subDays(30))->count(),
            'users_total' => User::count(),
            'characters_30' => (int) $charges->chars,
            'calls_30' => (int) $charges->calls,
            'page_views_30' => AppEvent::where('name', AppEvent::PAGE_VIEW)->where('created_at', '>=', $since)->count(),
        ];

        // Daily trend, zero-filled so quiet days render as gaps rather than
        // silently compressing the x-axis. DATE() compiles on MySQL and the
        // SQLite test driver alike.
        $byDay = AppEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as events, COUNT(DISTINCT user_id) as users')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $date = $since->copy()->addDays($i);
            $key = $date->toDateString();
            $daily[] = [
                'date' => $date,
                'events' => (int) ($byDay[$key]->events ?? 0),
                'users' => (int) ($byDay[$key]->users ?? 0),
            ];
        }

        $features = AppEvent::query()
            ->where('created_at', '>=', $since)
            ->where('name', '!=', AppEvent::PAGE_VIEW)
            ->selectRaw('name, COUNT(*) as events, COUNT(DISTINCT user_id) as users')
            ->groupBy('name')
            ->orderByDesc('events')
            ->get();

        $screens = AppEvent::query()
            ->where('name', AppEvent::PAGE_VIEW)
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as views, COUNT(DISTINCT user_id) as users')
            ->addSelect('meta->route as route')
            ->groupBy('meta->route')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $volumeBySource = CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->where('created_at', '>=', $since)
            ->selectRaw('source, COALESCE(SUM(characters), 0) as chars, COUNT(*) as calls')
            ->groupBy('source')
            ->orderByDesc('chars')
            ->get();

        $volumeByModel = CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->where('created_at', '>=', $since)
            ->selectRaw('model, COALESCE(SUM(characters), 0) as chars, COUNT(*) as calls')
            ->groupBy('model')
            ->orderByDesc('chars')
            ->get();

        $recent = AppEvent::query()
            ->with('user:id,name')
            ->where('name', '!=', AppEvent::PAGE_VIEW)
            ->latest('created_at')
            ->limit(25)
            ->get();

        return view('admin.insights', [
            'windowDays' => self::WINDOW_DAYS,
            'tiles' => $tiles,
            'daily' => $daily,
            'features' => $features,
            'screens' => $screens,
            'volumeBySource' => $volumeBySource,
            'volumeByModel' => $volumeByModel,
            'recent' => $recent,
        ]);
    }
}

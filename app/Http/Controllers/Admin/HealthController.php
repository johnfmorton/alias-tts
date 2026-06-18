<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HealthStatus;
use App\Http\Controllers\Controller;
use App\Models\Voice;
use App\Services\Health\HealthCheckResult;
use App\Services\Health\HealthReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HealthController extends Controller
{
    public function index(Request $request, HealthReport $report): View
    {
        // ?deep=1 also makes live calls (validate the Replicate token, probe the
        // queue) — same as `php artisan tts:doctor --deep`.
        $deep = $request->boolean('deep');
        $results = $report->run($deep);

        $summary = [
            'pass' => $this->count($results, HealthStatus::Pass),
            'warn' => $this->count($results, HealthStatus::Warn),
            'fail' => $this->count($results, HealthStatus::Fail),
        ];

        return view('admin.health.index', [
            'results' => $results,
            'summary' => $summary,
            'deep' => $deep,
            'voices' => Voice::orderBy('name')->get(),
        ]);
    }

    /** @param  array<int, HealthCheckResult>  $results */
    private function count(array $results, HealthStatus $status): int
    {
        return count(array_filter($results, fn (HealthCheckResult $r) => $r->status === $status));
    }
}

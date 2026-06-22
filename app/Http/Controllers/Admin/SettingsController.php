<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private SettingsManager $settings) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'groups' => $this->settings->groupedForView(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$rules, $pathByField] = $this->rulesAndMap();

        $validated = $request->validate($rules);

        // Map flat HTML field names back to config paths. A checkbox that's absent
        // means "off", so booleans are read explicitly rather than from $validated.
        $values = [];
        foreach ($pathByField as $field => $path) {
            $type = $this->settings->managed()[$path]['type'] ?? null;

            if ($type === 'bool') {
                $values[$path] = $request->boolean($field);
            } elseif (array_key_exists($field, $validated)) {
                $values[$path] = $validated[$field];
            }
        }

        $this->settings->save($values);

        return redirect()->route('admin.settings.index')->with('success', 'Settings saved.');
    }

    /**
     * Validation rules built from the registry (unlocked keys only) plus a
     * field-name → config-path map. Field names swap dots for underscores so
     * they're valid HTML input names.
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    private function rulesAndMap(): array
    {
        $rules = [];
        $map = [];

        foreach ($this->settings->managed() as $path => $entry) {
            if ($entry['locked'] ?? false) {
                continue; // pinned in .env — never editable
            }

            $field = str_replace('.', '_', $path);
            $map[$field] = $path;

            $rules[$field] = match ($entry['type']) {
                'bool' => ['sometimes', 'boolean'],
                'enum' => ['required', 'in:'.implode(',', $entry['options'])],
                'int' => ['required', 'integer', 'min:'.$entry['min'], 'max:'.$entry['max']],
                'float' => ['required', 'numeric', 'between:'.$entry['min'].','.$entry['max']],
                default => ['nullable', 'string'],
            };
        }

        return [$rules, $map];
    }
}

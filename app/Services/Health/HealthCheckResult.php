<?php

namespace App\Services\Health;

use App\Enums\HealthStatus;

/**
 * One line of a health report: a stable key (so callers — CLI, web page, JSON
 * monitor — can target a specific check), a status, a human label, and detail.
 */
final class HealthCheckResult
{
    public function __construct(
        public readonly string $key,
        public readonly HealthStatus $status,
        public readonly string $label,
        public readonly string $detail,
    ) {}

    public function isFailure(): bool
    {
        return $this->status === HealthStatus::Fail;
    }

    /** @return array{key: string, status: string, label: string, detail: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'label' => $this->label,
            'detail' => $this->detail,
        ];
    }
}

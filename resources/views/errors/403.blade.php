<x-error-page code="403" respelling="four oh three" category="forbidden" title="No access to this page">
    {{ ($exception?->getMessage() ?: null) ?? "Your account doesn't have access to this page. If you think it should, ask an administrator." }}
</x-error-page>

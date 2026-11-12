<x-filament-panels::page>
    <pre class="overflow-x-auto rounded-xl bg-gray-100 p-4 text-sm dark:bg-gray-900">{{ json_encode($this->jsonContent(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
</x-filament-panels::page>

<?php

return [
    'models' => [],
    'install_schema' => true,
    'navigation_group' => null,
    'trash_retention_days' => (int) env('TGS_TRASH_RETENTION_DAYS', 60),
    'embedded_canvas_grace_hours' => (int) env('TGS_EMBEDDED_CANVAS_GRACE_HOURS', 24),
    'named_canvas_cache_keys' => [],
];

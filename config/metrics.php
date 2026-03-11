<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Restrict Metrics Endpoint
    |--------------------------------------------------------------------------
    |
    | When true, /api/metrics is only accessible from private IPs (localhost,
    | Docker network 172.16-31.x.x, 10.x.x.x, 192.168.x.x). Set to false to
    | allow all (e.g. local dev without Docker).
    |
    */
    'restrict' => env('METRICS_RESTRICT', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed IPs (optional override)
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of IPs or CIDR ranges. When set, replaces the
    | default private ranges. Example: "127.0.0.1,172.19.0.0/16"
    |
    */
    'allowed_ips' => array_filter(array_map('trim', explode(',', env('METRICS_ALLOWED_IPS', '')))),

];

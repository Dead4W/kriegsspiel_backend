<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsRestrictMiddleware
{
    /**
     * Private IP ranges (Docker, localhost, LAN).
     */
    private const PRIVATE_RANGES = [
        '127.0.0.0/8',      // loopback
        '10.0.0.0/8',       // private
        '172.16.0.0/12',    // Docker default (172.16-31)
        '192.168.0.0/16',   // private LAN
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('metrics.restrict', true)) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null) {
            return response('Forbidden', 403);
        }

        if ($this->isAllowed($ip)) {
            return $next($request);
        }

        return response('Forbidden', 403);
    }

    private function isAllowed(string $ip): bool
    {
        $allowedIps = config('metrics.allowed_ips');

        if (!empty($allowedIps)) {
            foreach ((array) $allowedIps as $range) {
                if ($this->ipInRange($ip, trim($range))) {
                    return true;
                }
            }
            return false;
        }

        foreach (self::PRIVATE_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            return $this->ipInCidr($ip, $range);
        }

        return $ip === $range;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }
}

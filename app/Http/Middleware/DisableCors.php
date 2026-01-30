<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Response;

class DisableCors
{

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $headers = [
            'Access-Control-Allow-Origin'      => '*',
            'Access-Control-Allow-Methods'     => '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Headers'     => 'X-Requested-With,Content-Type,X-Token-Auth,Authorization',
            'Accept'                           => 'application/json',
        ];

        // Illuminate\Http\Response
        if (method_exists($response, 'header')) {
            foreach ($headers as $k => $v) {
                $response->header($k, $v);
            }
        }
        // Symfony\Component\HttpFoundation\Response (StreamedResponse, BinaryFileResponse, etc)
        elseif ($response instanceof Response) {
            foreach ($headers as $k => $v) {
                $response->headers->set($k, $v);
            }
        }

        return $response;
    }

}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyController extends Controller
{

    public function image(Request $request)
    {
        $url = $request->query('url');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return response('Invalid url', 400);
        }

        // whitelist to avoid SSRF
        if (!str_starts_with($url, 'https://')) {
            return response('Only https allowed', 403);
        }

        $headers = [];

        // forward Range header for streaming
        if ($request->headers->has('Range')) {
            $headers['Range'] = $request->headers->get('Range');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) {
                echo $data;
                flush();
                return strlen($data);
            },
        ]);

        $response = new StreamedResponse(function () use ($ch) {
            curl_exec($ch);
            curl_close($ch);
        });

        // fetch headers first
        $head = get_headers($url, true);

        if (isset($head['Content-Type'])) {
            $response->headers->set('Content-Type', $head['Content-Type']);
        }

        if (isset($head['Content-Length'])) {
            $response->headers->set('Content-Length', $head['Content-Length']);
        }

        // VERY IMPORTANT
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Length, Content-Range');

        return $response;
    }

    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = "$k: $v";
        }
        return $out;
    }

}

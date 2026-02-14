<?php

namespace Pikant\LaravelHttpReplay;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ResponseSerializer
{
    /**
     * @return array{status: int, headers: array<string, list<string>>, body: mixed, recorded_at: string, request: array{method: string, url: string, attributes: array<string, mixed>}}
     */
    public function serialize(Request $request, Response $response): array
    {
        $body = $response->body();
        $decoded = json_decode($body, true);

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $decoded !== null ? $decoded : $body,
            'recorded_at' => now()->toIso8601String(),
            'request' => [
                'method' => $request->method(),
                'url' => $request->url(),
                'attributes' => $request->attributes(),
            ],
        ];
    }

    /**
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed}  $data
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function deserialize(array $data)
    {
        $body = $data['body'];
        if (is_array($body)) {
            $body = json_encode($body) ?: '';
        }

        $headers = [];
        foreach ($data['headers'] ?? [] as $name => $value) {
            $headers[$name] = is_array($value) ? $value[0] : $value;
        }

        return Http::response($body, $data['status'], $headers);
    }
}

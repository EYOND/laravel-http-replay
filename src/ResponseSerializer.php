<?php

namespace EYOND\LaravelHttpReplay;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class ResponseSerializer
{
    /**
     * @param  bool|list<string>  $responseHeaders
     * @return array{status: int, headers: array<string, list<string>>, body: mixed, body_encoding?: 'base64', recorded_at: string, request: array{method: string, url: string, attributes: array<string, mixed>}}
     */
    public function serialize(Request $request, Response $response, bool|array $responseHeaders = true): array
    {
        $body = $response->body();

        $data = [
            'status' => $response->status(),
            'headers' => $this->selectHeaders($response->headers(), $responseHeaders),
            'body' => $this->serializeBody($body),
            'recorded_at' => now()->toIso8601String(),
            'request' => [
                'method' => $request->method(),
                'url' => $request->url(),
                'attributes' => $request->attributes(),
            ],
        ];

        if (! $this->isUtf8($body)) {
            $data['body_encoding'] = 'base64';
        }

        return $data;
    }

    /**
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}  $data
     */
    public function deserialize(array $data): PromiseInterface
    {
        $body = $this->deserializeBody($data);
        if (is_array($body)) {
            $body = json_encode($body) ?: '';
        }

        $headers = [];
        foreach ($data['headers'] ?? [] as $name => $value) {
            $headers[$name] = is_array($value) ? $value[0] : $value;
        }

        return Http::response($body, $data['status'], $headers);
    }

    protected function serializeBody(string $body): mixed
    {
        if (! $this->isUtf8($body)) {
            return base64_encode($body);
        }

        $decoded = json_decode($body, true);

        return $decoded !== null ? $decoded : $body;
    }

    /**
     * @param  array{body: mixed, body_encoding?: string}  $data
     */
    protected function deserializeBody(array $data): mixed
    {
        if (($data['body_encoding'] ?? null) !== 'base64') {
            return $data['body'];
        }

        if (! is_string($data['body'])) {
            throw new UnexpectedValueException('Base64-encoded replay bodies must be strings.');
        }

        $body = base64_decode($data['body'], true);

        if ($body === false) {
            throw new UnexpectedValueException('Replay body contains invalid base64 data.');
        }

        return $body;
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  bool|list<string>  $selection
     * @return array<string, list<string>>
     */
    protected function selectHeaders(array $headers, bool|array $selection): array
    {
        if ($selection === true) {
            return $headers;
        }

        if ($selection === false || $selection === []) {
            return [];
        }

        $selectedNames = array_fill_keys(array_map(strtolower(...), $selection), true);

        return array_filter(
            $headers,
            fn (string $name): bool => isset($selectedNames[strtolower($name)]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}

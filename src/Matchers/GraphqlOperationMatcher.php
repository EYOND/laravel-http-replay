<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use JsonException;

final class GraphqlOperationMatcher implements NameMatcher
{
    public function resolve(Request $request): string
    {
        [$operationName, $document] = $this->graphqlBody($request->body());

        if ($operationName !== null) {
            return $operationName;
        }

        if ($document !== null && ($namedOperation = $this->namedOperation($document)) !== null) {
            return $namedOperation;
        }

        $eyondRequest = $request->header('X-EYOND-Request')[0] ?? null;
        if (is_string($eyondRequest) && $eyondRequest !== '') {
            return $eyondRequest;
        }

        if ($document !== null && $this->looksAnonymous($document)) {
            return 'anonymous';
        }

        return 'unknown';
    }

    /**
     * @return array{?string, ?string}
     */
    protected function graphqlBody(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [null, null];
        }

        if (! is_array($data)) {
            return [null, null];
        }

        $operationName = $data['operationName'] ?? null;
        $document = $data['query'] ?? null;

        return [
            is_string($operationName) && $operationName !== '' ? $operationName : null,
            is_string($document) && $document !== '' ? $document : null,
        ];
    }

    protected function namedOperation(string $document): ?string
    {
        preg_match_all(
            '/[_A-Za-z][_0-9A-Za-z]*|[{}()\[\]@]/',
            $this->withoutCommentsAndStrings($document),
            $matches,
        );

        $tokens = $matches[0];
        $atDefinitionStart = true;
        $selectionDepth = 0;
        $parenthesisDepth = 0;
        $bracketDepth = 0;

        foreach ($tokens as $index => $token) {
            if ($selectionDepth === 0 && $atDefinitionStart) {
                if (in_array($token, ['query', 'mutation', 'subscription'], true)) {
                    $operationName = $tokens[$index + 1] ?? null;

                    return is_string($operationName) && preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $operationName) === 1
                        ? $operationName
                        : null;
                }

                $atDefinitionStart = false;
            }

            if ($token === '(') {
                $parenthesisDepth++;
            } elseif ($token === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
            } elseif ($token === '[') {
                $bracketDepth++;
            } elseif ($token === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
            } elseif ($token === '{' && $parenthesisDepth === 0 && $bracketDepth === 0) {
                $selectionDepth++;
            } elseif ($token === '}' && $selectionDepth > 0) {
                $selectionDepth--;

                if ($selectionDepth === 0) {
                    $atDefinitionStart = true;
                }
            }
        }

        return null;
    }

    protected function looksAnonymous(string $document): bool
    {
        $document = ltrim($this->withoutCommentsAndStrings($document), " \t\n\r\0\x0B,");

        return str_starts_with($document, '{')
            || preg_match('/^(?:query|mutation|subscription)\b/', $document) === 1;
    }

    protected function withoutCommentsAndStrings(string $document): string
    {
        $filtered = '';
        $length = strlen($document);

        for ($position = 0; $position < $length; $position++) {
            if (substr($document, $position, 3) === '"""') {
                $filtered .= '   ';
                $position += 2;

                while (++$position < $length) {
                    if ($document[$position] === '\\' && substr($document, $position + 1, 3) === '"""') {
                        $filtered .= '    ';
                        $position += 3;

                        continue;
                    }

                    if (substr($document, $position, 3) === '"""') {
                        $filtered .= '   ';
                        $position += 2;

                        break;
                    }

                    $filtered .= str_contains("\r\n", $document[$position]) ? $document[$position] : ' ';
                }

                continue;
            }

            if ($document[$position] === '#') {
                while ($position < $length && ! str_contains("\r\n", $document[$position])) {
                    $filtered .= ' ';
                    $position++;
                }

                if ($position < $length) {
                    $filtered .= $document[$position];
                }

                continue;
            }

            if ($document[$position] === '"') {
                $filtered .= ' ';

                while (++$position < $length) {
                    if ($document[$position] === '\\') {
                        $filtered .= ' ';

                        if (++$position < $length) {
                            $filtered .= ' ';
                        }

                        continue;
                    }

                    $filtered .= str_contains("\r\n", $document[$position]) ? $document[$position] : ' ';

                    if ($document[$position] === '"' || str_contains("\r\n", $document[$position])) {
                        break;
                    }
                }

                continue;
            }

            $filtered .= $document[$position];
        }

        return $filtered;
    }
}

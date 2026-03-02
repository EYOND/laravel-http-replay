# Move bail check before HTTP request

## Context

The bail feature prevents tests from accidentally recording new HTTP fakes in CI. Currently, the bail check happens in `handleResponseReceived()` (line 340 of `ReplayBuilder.php`) — **after** the real HTTP request has already been sent. This defeats the purpose: bail should prevent the request entirely, not let it fire and then throw.

The TODO at line 339 confirms this:
```
// TODO: if we should bail, prevent request instead of bailing afterwards
```

## Changes

### 1. `src/ReplayBuilder.php`

**Move bail check into `handleRequest()`** — right before `return null` (line 317), which is the point where the builder gives up looking for a stored response and lets the real HTTP call through:

```php
// No stored response — bail if active, otherwise mark for recording
if ($this->shouldBail()) {
    throw new ReplayBailException(
        "Http Replay has no stored response for [{$this->saveDirectory}/{$baseFilename}] and bail mode is active. "
        .'Run tests locally to record new fakes.'
    );
}

$key = $this->recordingKey($request);
$this->pendingRecordings[$key] = ($this->pendingRecordings[$key] ?? 0) + 1;

return null;
```

**Remove the bail block from `handleResponseReceived()`** (lines 338-348). The `$matchBy`/`$filename` resolve that was duplicated for the bail message is no longer needed there.

Note: the error message changes slightly — it now says "has no stored response for" instead of "attempted to write" since we catch it before the request, not after.

### 2. `tests/ReplayBuilderTest.php`

Update both bail tests to invoke `handleRequest()` instead of `handleResponseReceived()`. The tests currently:
- Set up `pendingRecordings` manually and call `handleResponseReceived()`

They should instead:
- NOT set up `pendingRecordings` (that's an internal detail of the recording flow)
- Call `handleRequest()` with a request that has no stored response, which is the natural trigger

## Verification

```bash
composer test        # all tests pass
composer analyse     # PHPStan level 5 clean
composer format      # Pint formatting
```

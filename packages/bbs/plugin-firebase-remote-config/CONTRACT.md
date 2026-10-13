# Native response contract, version 1

NativePHP 4.2.0's `BridgeResponse.success(data)` returns the data map directly.
PHP accepts that direct object and also a `data` envelope for bridge compatibility.
Operation errors are represented in data with controlled codes, not exceptions.

All names and types below are required, including explicit nulls. Additional
fields are rejected at the PHP boundary. All timestamps are positive Unix epoch
milliseconds when known, otherwise null. They must be safe JSON integers.
Transport limits and typed value limits are defined in RemoteConfigContract.

## Availability

`IsAvailable` returns:

```json
{"platform":"android","available":true,"errorCode":null}
```

An unavailable response has `available:false` and a known non-null errorCode.
Availability reports native capability, not network reachability or fetch success.

## Correlated control failure

If runtime access or persistence cannot establish an operation outcome, mutation
and GetStatus responses may instead carry this exact shape:

```json
{"id":"123e4567-e89b-42d3-a456-426614174000","operation":"Fetch","success":false,"errorCode":"PERSIST_FAILED"}
```

For GetStatus control failures, operation is null because the native layer does
not invent a stored operation. PHP requires exact ID and expected-operation
correlation, false as a boolean, no extra fields, and one of PERSIST_FAILED,
NATIVE_UNAVAILABLE, LIMIT_EXCEEDED or INVALID_NATIVE_RESPONSE. It converts the
reply to the existing PHP-only unconfirmed state with the ID/code retained.
The native layer does not emit an unconfirmed operation record or persist a
control failure as history. A write/SDK call may already have happened, so this
must not be presented as terminal rejection, not_found or success. Poll the
same ID with a bound; persistence/runtime failures may require process restart.

## Values

`GetValues` succeeds with exactly the keys and types requested:

```json
{
  "success":true,
  "values":{
    "welcome_message":{"type":"string","value":"Welcome","source":"default"},
    "show_media_optimizer":{"type":"boolean","value":true,"source":"remote"},
    "media_image_quality":{"type":"integer","value":0,"source":"static"}
  },
  "errorCode":null
}
```

The JSON illustrates the schema only. Static integer fallback is 0 and the
application will use its local quality 80 after range validation rejects 0.
A successful reply must describe the actual chosen source/value.
Sources are `remote`, `default`, `static`. Decimal values are finite numbers.
The native reader converts the exact SDK getValue wrapper and reports that
wrapper's source. Invalid conversion fails with INVALID_VALUE; application
fallback remains separate from SDK-sourced values.
When one entry cannot be safely returned, reject the collection rather than
returning an incomplete set or mislabeling a fallback as remote.

Failure:

```json
{"success":false,"values":{},"errorCode":"DEFAULTS_NOT_READY"}
```

## Operation status

Configure, SetDefaults, Fetch, Activate, FetchAndActivate and GetStatus use:

```json
{
  "id":"123e4567-e89b-42d3-a456-426614174000",
  "operation":"Fetch",
  "accepted":true,
  "status":"pending",
  "terminal":false,
  "success":false,
  "fetched":null,
  "activated":null,
  "startedAtMs":null,
  "finishedAtMs":null,
  "throttleEndTimeMs":null,
  "errorCode":null
}
```

| Status | accepted | terminal | success | Error |
| --- | --- | --- | --- | --- |
| pending, running | true | false | false | null |
| succeeded, unchanged | true | true | true | null |
| failed | true | true | false | known code |
| timed_out | true | true | false | OPERATION_TIMEOUT |
| interrupted | true | true | false | PROCESS_INTERRUPTED |
| rejected | false | true | false | known code |
| not_found | false | true | false | RESULT_NOT_FOUND |

Rules:

- A start reply must match both requested ID and operation. GetStatus must match
  its requested ID. Native operation names are the five mutation names above.
- Running and accepted terminal states require startedAtMs. Accepted terminal
  states require finishedAtMs >= startedAtMs. Active states have no finishedAtMs.
- A rejected request has null fetched, activated, startedAtMs and finishedAtMs.
- A not_found result is legal only for GetStatus. Its operation is null, and all
  outcome flags and timestamps are null.
- Successful Fetch sets fetched=true and activated=null. Successful Configure
  and SetDefaults leave both outcome flags null.
- Activate has fetched=null. A new activation is succeeded/activated=true;
  no new activation is unchanged/activated=false.
- FetchAndActivate records fetched=true after fetch succeeds. It can then be
  running while activation is pending. Its successful terminal status is
  succeeded/activated=true or unchanged/activated=false.
- Failed operations never claim activated=true. A timeout may leave activation
  outcome unknown; the coordinator must account for late SDK callbacks.
- throttleEndTimeMs is non-null only with THROTTLED. It represents SDK guidance,
  not a promise that another immediate call will succeed.
- `unconfirmed` is synthesized by PHP for missing/invalid acknowledgements or
  polling replies. Native must not emit it. It retains the request ID with
  accepted=false, terminal=false and an errorCode. It is not proof of rejection.

## SDK info

`GetInfo` success:

```json
{
  "success":true,
  "ready":true,
  "defaultsReady":true,
  "lastFetchStatus":"success",
  "lastSuccessfulFetchAtMs":1760000000000,
  "minimumFetchIntervalSeconds":43200,
  "fetchTimeoutSeconds":60,
  "activeOperationId":null,
  "sdkBusy":false,
  "errorCode":null
}
```

lastFetchStatus: no_fetch_yet, success, failure, throttled. A successful SDK fetch
requires a lastSuccessfulFetchAtMs; later failure may retain an earlier successful
timestamp. A successful SDK fetch may use cached data and does not prove a
network request occurred or that parameters changed.

defaultsReady cannot be true while ready=false. A non-null activeOperationId
requires sdkBusy=true. sdkBusy=true with null activeOperationId is permitted
when a plugin deadline has ended the tracked operation but an SDK task is still
in flight. The native coordinator must prevent new conflicting mutations until
that task settles.

Failure:

```json
{"success":false,"errorCode":"NOT_INITIALIZED"}
```

## Error codes

The complete allowlist is RemoteConfigErrorCode.ALL. Do not return SDK exception
messages, configuration contents, credentials, paths, or internal diagnostics.
# Durable history contract

Only accepted operation records are persisted. Admission and transitions are
saved before returning success. A retained ID with identical validated typed
input replays its record; a changed request using that ID conflicts. Different
overlapping accepted mutations are busy. Native SDK concurrency after a
timeout is managed separately by the coordinator.

History retains at most 50 records within a 65,536-byte canonical versioned
file. Terminal records are pruned oldest-first to admit a new ID; an active
record is never pruned. Replay protection is limited to retained IDs. Evicted
IDs return not_found; callers must not reuse old IDs.

Status storage contains only SHA-256 request fingerprints and result fields,
never typed defaults or SDK configuration caches. On restart, pending/running
records are durably marked interrupted before polling. Pending recovery uses
the recovery timestamp for both start and finish; running recovery preserves
its known start/fetch progress. It does not infer successful activation.

Corruption/unsupported schema fails closed and preserves the file. A failed
write freezes that store instance until restart reloads the durable state.
Complete-result compare-and-replace rejects stale/terminal updates. One runtime
must own this worker-only, synchronized store in private no-backup storage.

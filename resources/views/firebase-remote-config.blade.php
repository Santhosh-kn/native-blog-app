@extends('layouts.app')
@section('title', 'Remote Config')
@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">Back</a>
@endsection
@section('content')
<style>
    .rc-demo { display: grid; gap: 12px; }
    .rc-demo h2 { margin: 0 0 10px; font-size: 17px; }
    .rc-demo p { font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
    .rc-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .rc-demo button:disabled { opacity: .5; }
    .rc-table { overflow-x: auto; }
    .rc-table table { border-collapse: collapse; width: 100%; font-size: 13px; }
    .rc-table th, .rc-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .rc-table td { overflow-wrap: anywhere; max-width: 240px; }
    .rc-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
    .rc-fields select { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
    .rc-demo dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); font-size: 13px; gap: 8px; }
    .rc-demo dd { margin: 0; overflow-wrap: anywhere; }
    #rc-message { min-height: 24px; }
</style>
<div class="rc-demo">
    <section class="card">
        <h2>Effective app settings</h2>
        <p>These values are read from activated settings. Fetching alone does not change them.</p>
        <div class="rc-table"><table>
            <thead><tr><th>Parameter</th><th>Current value</th><th>Source</th><th>Local default</th></tr></thead>
            <tbody>
            @foreach ($remoteConfig['values'] as $key => $entry)
                <tr>
                    <th scope="row">{{ $key }}<br><small>{{ $entry['type'] }}</small></th>
                    <td><span data-rc-value="{{ $key }}">{{ is_bool($entry['value']) ? ($entry['value'] ? 'true' : 'false') : $entry['value'] }}</span>
                        <p data-rc-reason="{{ $key }}">{{ $entry['fallbackReason'] }}</p></td>
                    <td data-rc-source="{{ $key }}">{{ $entry['source'] }}</td>
                    <td>{{ is_bool($remoteConfig['defaults'][$key]['value']) ? ($remoteConfig['defaults'][$key]['value'] ? 'true' : 'false') : $remoteConfig['defaults'][$key]['value'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <p>remote: Firebase activated value · default: registered Firebase default · static: SDK fallback · local: validated PHP fallback.</p>
    </section>
    <section class="card">
        <h2>Fetch and activation</h2>
        <div class="rc-actions">
            <button type="button" class="btn btn-primary" data-rc-operation="Fetch" disabled>Fetch</button>
            <button type="button" class="btn btn-secondary" data-rc-operation="Activate" disabled>Activate</button>
            <button type="button" class="btn btn-secondary" data-rc-operation="FetchAndActivate" disabled>Fetch and activate</button>
        </div>
        <p>Use Fetch, check its result, then Activate to apply it. Fetch and activate performs both stages explicitly. Home navigation follows the activated feature flag; protected routes still require authentication and device unlock.</p>
        <p id="rc-message" role="status" aria-live="polite">Reading cached settings. Local defaults remain usable.</p>
        <dl>
            <dt>Readiness</dt><dd id="rc-ready">Checking</dd>
            <dt>Last fetch</dt><dd id="rc-fetch-status">Unknown</dd>
            <dt>Last successful fetch</dt><dd id="rc-fetch-time">None</dd>
            <dt>Last operation</dt><dd id="rc-operation">None</dd>
            <dt>Started</dt><dd id="rc-started">None</dd>
            <dt>Finished</dt><dd id="rc-finished">None</dd>
            <dt>Fetch completed</dt><dd id="rc-fetched">Unknown</dd>
            <dt>Activation changed values</dt><dd id="rc-activated">Unknown</dd>
            <dt>Retry after throttling</dt><dd id="rc-throttle">None</dd>
        </dl>
        <div class="rc-actions">
            <button type="button" class="btn btn-secondary" id="rc-refresh">Refresh values and status</button>
            <button type="button" class="btn btn-secondary" data-rc-operation="SetDefaults" disabled>Register local defaults</button>
        </div>
    </section>
    <section class="card">
        <h2>Fetch settings</h2>
        <p>Startup reads the cache and registers defaults; it never fetches or activates. The normal fetch interval is 12 hours. Development mode permits explicit immediate fetches, but Firebase can still throttle them.</p>
        <div class="rc-fields">
            <label>Fetch interval<select id="rc-interval"><option value="43200">Normal: 12 hours</option><option value="0">Development: 0 seconds</option></select></label>
            <label>Fetch timeout (1–120 seconds)<input id="rc-timeout" type="number" min="1" max="120" step="1" value="60"></label>
        </div>
        <p id="rc-settings">Reading current fetch settings.</p>
        <button type="button" class="btn btn-secondary" data-rc-operation="Configure" disabled>Apply fetch settings</button>
        <p>Image quality is validated between 1 and 100 and sampled only for new image jobs. Existing media jobs keep their submitted options. PNG does not receive a quality parameter.</p>
    </section>
</div>
@include('partials.firebase-remote-config', ['remoteConfigMode' => 'demo'])
@endsection

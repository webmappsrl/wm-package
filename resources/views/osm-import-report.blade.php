<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('OSM import report') }}</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #1e293b;
            --border: #334155;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --ok: #22c55e;
            --warn: #f59e0b;
            --bad: #ef4444;
            --link: #38bdf8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }

        .wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1.25rem 4rem;
        }

        .top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 1.35rem;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.02em;
        }

        a.btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 0.85rem;
            border-radius: 0.375rem;
            background: var(--panel);
            border: 1px solid var(--border);
            color: var(--link);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
        }

        a.btn:hover {
            border-color: var(--link);
        }

        .badge-dry {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(245, 158, 11, 0.15);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .banner {
            border-radius: 0.5rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            border: 1px solid var(--border);
        }

        .banner.success {
            background: rgba(34, 197, 94, 0.08);
            border-color: rgba(34, 197, 94, 0.35);
            color: #bbf7d0;
        }

        .banner.warning {
            background: rgba(245, 158, 11, 0.08);
            border-color: rgba(245, 158, 11, 0.35);
            color: #fde68a;
        }

        .banner.danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fecaca;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            padding: 0.85rem 1rem;
        }

        .stat .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 0.25rem;
        }

        .stat .value {
            font-size: 1.5rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .stat .value.ok {
            color: var(--ok);
        }

        .stat .value.warn {
            color: var(--warn);
        }

        .stat .value.bad {
            color: var(--bad);
        }

        section {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            padding: 1rem 1.15rem;
            margin-bottom: 1.25rem;
        }

        section h2 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--muted);
            margin: 0 0 0.85rem 0;
            font-weight: 600;
        }

        .cat-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }

        .cat-row:last-child {
            border-bottom: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th,
        td {
            text-align: left;
            padding: 0.5rem 0.6rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        code {
            font-size: 0.8rem;
            background: rgba(15, 23, 42, 0.8);
            padding: 0.15rem 0.35rem;
            border-radius: 0.25rem;
        }

        a.ref-link {
            color: var(--link);
            text-decoration: none;
        }

        a.ref-link:hover {
            text-decoration: underline;
        }

        a.ref-link code {
            color: inherit;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.25);
        }

        .footnote {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 2rem;
        }

        @media (max-width: 520px) {
            .grid {
                grid-template-columns: repeat(2, 1fr);
            }

            table {
                font-size: 0.8rem;
            }

            th,
            td {
                padding: 0.4rem 0.35rem;
            }
        }
    </style>
</head>

<body>
    @php
    /** @var array $report */
    $status = $report['status'] ?? 'success';
    $dry = !empty($report['dry_run']);
    @endphp
    <div class="wrap">
        <div class="top">
            <div>
                <h1>{{ __('OSM import report') }}</h1>
                @if($dry)
                <div class="badge-dry">{{ __('Dry run — no database changes will be saved.') }}</div>
                @endif
            </div>
            <a class="btn" href="{{ $backUrl }}">{{ __('Go back') }}</a>
        </div>

        @if(($report['truncated_beyond_limit'] ?? 0) > 0)
        <div class="banner warning">{{ __(':count OSM IDs were skipped because they exceeded the per-run limit (WM_OSM_IMPORT_MAX_IDS_PER_RUN). Import the remaining IDs in another run.', ['count' => (int) $report['truncated_beyond_limit']]) }}</div>
        @endif

        @if($status === 'success')
        <div class="banner success">{{ __('Import completed successfully.') }}</div>
        @elseif($status === 'warning')
        <div class="banner warning">{{ __('Completed with some errors. Review skipped rows below.') }}</div>
        @else
        <div class="banner danger">{{ __('All requested nodes failed. See the table below for details.') }}</div>
        @endif

        <h2 style="font-size:0.8rem;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);margin:0 0 0.75rem 0;">{{ __('Summary') }}</h2>
        <div class="grid">
            <div class="stat">
                <div class="label">{{ __('Requested OSM IDs') }}</div>
                <div class="value">{{ (int) ($report['requested'] ?? 0) }}</div>
            </div>
            <div class="stat">
                <div class="label">{{ __('Created') }}</div>
                <div class="value ok">{{ (int) ($report['created'] ?? 0) }}</div>
            </div>
            <div class="stat">
                <div class="label">{{ __('Updated') }}</div>
                <div class="value ok">{{ (int) ($report['updated'] ?? 0) }}</div>
            </div>
            <div class="stat">
                <div class="label">{{ __('Skipped') }}</div>
                <div class="value {{ ($report['skipped'] ?? 0) > 0 ? 'warn' : '' }}">{{ (int) ($report['skipped'] ?? 0) }}</div>
            </div>
            <div class="stat">
                <div class="label">{{ __('New taxonomies') }}</div>
                <div class="value">{{ (int) ($report['new_taxonomies'] ?? 0) }}</div>
            </div>
        </div>

        @if(!empty($report['categories']))
        <section>
            <h2>{{ __('Errors by category') }}</h2>
            @foreach($report['categories'] as $row)
            <div class="cat-row">
                <span>{{ $row['label'] }}</span>
                <strong style="font-variant-numeric: tabular-nums;">{{ (int) $row['count'] }}</strong>
            </div>
            @endforeach
        </section>
        @endif

        @if(!empty($report['failure_samples']))
        <section>
            <h2>{{ __('Sample failures') }}</h2>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Reference') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Message') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['failure_samples'] as $f)
                        @php
                        $refUrl = $f['osm_url'] ?? (preg_match('#^node/(\d+)$#', (string) ($f['node'] ?? ''), $m) ? 'https://www.openstreetmap.org/node/'.$m[1] : null);
                        @endphp
                        <tr>
                            <td>
                                @if($refUrl)
                                <a href="{{ $refUrl }}" class="ref-link" target="_blank" rel="noopener noreferrer"><code>{{ $f['node'] }}</code></a>
                                @else
                                <code>{{ $f['node'] }}</code>
                                @endif
                            </td>
                            <td>{{ $f['category'] }}</td>
                            <td>{{ $f['message'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(($report['failure_more'] ?? 0) > 0)
            <p style="margin:0.75rem 0 0;font-size:0.85rem;color:var(--muted);">
                {{ __('More failures (:count) are not shown in this table.', ['count' => (int) $report['failure_more']]) }}
            </p>
            @endif
        </section>
        @endif

        <p class="footnote">{{ __('This page expires after about :minutes minutes.', ['minutes' => (int) $ttlMinutes]) }}</p>
    </div>
</body>

</html>

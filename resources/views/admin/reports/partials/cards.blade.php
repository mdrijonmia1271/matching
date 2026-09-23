{{-- $cards: list of [label, value, colour class, hint|null]. Screen only: a print copy is just the table. --}}
@unless($printing ?? false)
    @php
        // Full class names so Tailwind finds them when it scans this file.
        $columns = [2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4', 5 => 'xl:grid-cols-5', 6 => 'xl:grid-cols-6'][min(max(count($cards), 2), 6)];
    @endphp
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 {{ $columns }}">
        @foreach($cards as [$label, $value, $color, $hint])
            <div class="card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</p>
                @if($hint)<p class="mt-0.5 text-xs text-slate-400">{{ $hint }}</p>@endif
            </div>
        @endforeach
    </div>

    @include('admin.reports.partials.table-bar')
@endunless

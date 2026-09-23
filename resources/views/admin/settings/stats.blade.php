@extends('layouts.admin')

@section('title', 'Stats strip')
@section('heading', 'Stats strip')

@section('content')
    <form method="POST" action="{{ route('admin.settings.stats.update') }}" class="max-w-4xl space-y-6">
        @csrf @method('PUT')

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Counters</h2>
            <p class="mt-1 text-sm text-slate-500">
                The row of tiles under the categories on the home page. Each tile counts something in the shop on its
                own, so the figure stays true as the catalogue grows &mdash; or you can type a fixed value instead.
            </p>

            <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
                <input type="hidden" name="home_stats_enabled" value="0">
                <input type="checkbox" name="home_stats_enabled" value="1" @checked(old('home_stats_enabled', $settings['home_stats_enabled'])) class="mt-0.5 rounded text-brand-600 focus:ring-brand-500">
                <span>Show the counters<span class="block text-xs text-slate-500">Turn off to hide the whole row.</span></span>
            </label>

            @php $tiles = old('stats', $settings['home_stats']); @endphp
            <div class="mt-5 space-y-4">
                @foreach($tiles as $index => $tile)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-4">
                        <div>
                            <label for="stats_{{ $index }}_label" class="label">Label</label>
                            <input id="stats_{{ $index }}_label" name="stats[{{ $index }}][label]" type="text" required maxlength="40" value="{{ $tile['label'] }}" class="input">
                        </div>
                        <div>
                            <label for="stats_{{ $index }}_icon" class="label">Icon</label>
                            <select id="stats_{{ $index }}_icon" name="stats[{{ $index }}][icon]" class="input">
                                @foreach($icons as $key => $name)
                                    <option value="{{ $key }}" @selected($tile['icon'] === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="stats_{{ $index }}_source" class="label">Figure</label>
                            <select id="stats_{{ $index }}_source" name="stats[{{ $index }}][source]" class="input">
                                @foreach($sources as $key => $name)
                                    <option value="{{ $key }}" @selected($tile['source'] === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="stats_{{ $index }}_value" class="label">Fixed text</label>
                            <input id="stats_{{ $index }}_value" name="stats[{{ $index }}][value]" type="text" maxlength="20" value="{{ $tile['value'] }}" class="input" placeholder="e.g. 5,000+">
                            <p class="mt-1 text-xs text-slate-500">Only used when the figure is set to fixed text.</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">Save stats strip</button>
            <a href="{{ route('home') }}" target="_blank" rel="noopener" class="text-sm text-slate-500 hover:text-slate-800">Preview home page</a>
        </div>
    </form>
@endsection

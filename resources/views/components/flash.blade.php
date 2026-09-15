@php
    $flashes = [
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'error' => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
@endphp

@foreach($flashes as $key => $classes)
    @if(session($key))
        <div x-data="{ show: true }" x-show="show" x-transition
             class="mt-4 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ $classes }}">
            <span class="flex-1">{{ session($key) }}</span>
            <button @click="show = false" class="shrink-0 opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
        </div>
    @endif
@endforeach

@if($errors->any())
    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <p class="font-semibold">Please fix the following:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

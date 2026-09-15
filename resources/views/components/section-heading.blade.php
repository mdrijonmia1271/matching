@props(['title', 'link' => null, 'linkLabel' => 'View all'])

<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">{{ $title }}</h2>
        <span class="mt-2 block h-1 w-14 rounded-full bg-brand-600"></span>
    </div>

    @if($link)
        <a href="{{ $link }}" class="group inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-brand-600">
            {{ $linkLabel }}
            <svg class="h-4 w-4 transition group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="M5 12h14m-6-6 6 6-6 6"/>
            </svg>
        </a>
    @endif
</div>

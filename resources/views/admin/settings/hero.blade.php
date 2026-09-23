@extends('layouts.admin')

@section('title', 'Hero section')
@section('heading', 'Hero section')

@section('content')
    <form method="POST" action="{{ route('admin.settings.hero.update') }}" enctype="multipart/form-data" class="max-w-4xl space-y-6">
        @csrf @method('PUT')

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Pictures</h2>
            <p class="mt-1 text-sm text-slate-500">
                Up to {{ $slots }} pictures on the right of the hero; with more than one it fades from one to the next.
                Leave all of them empty and the newest featured product's picture is used instead.
            </p>

            @php $images = $settings['hero_images']; @endphp
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @for($slot = 0; $slot < $slots; $slot++)
                    {{-- `preview` holds a blob URL for the file just chosen, so the
                         slot shows the new picture before the form is even saved. --}}
                    <div class="rounded-lg border border-slate-200 p-3" x-data="{ preview: null }">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Picture {{ $slot + 1 }}</p>

                        <div class="mt-2 flex h-40 items-center justify-center overflow-hidden rounded bg-slate-100">
                            <img x-show="preview" x-cloak :src="preview" alt="" class="h-full w-full object-cover">

                            <div x-show="! preview" class="flex h-full w-full items-center justify-center">
                                @if(! empty($images[$slot]))
                                    <img src="{{ asset('storage/' . $images[$slot]) }}" alt="Hero picture {{ $slot + 1 }}" class="h-full w-full object-cover">
                                @else
                                    <span class="text-xs text-slate-400">Empty</span>
                                @endif
                            </div>
                        </div>

                        <p x-show="preview" x-cloak class="mt-2 text-xs text-brand-600">New picture &mdash; save to apply.</p>

                        <input name="hero_images[{{ $slot }}]" type="file" accept="image/png,image/jpeg,image/webp"
                               class="input mt-3 p-2 text-xs"
                               @change="
                                   if (preview) URL.revokeObjectURL(preview);
                                   preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null;
                               ">

                        @if(! empty($images[$slot]))
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                <input type="checkbox" name="remove_hero_images[{{ $slot }}]" value="1" class="rounded text-rose-600 focus:ring-rose-500"> Remove
                            </label>
                        @endif
                    </div>
                @endfor
            </div>
            <p class="mt-3 text-xs text-slate-500">PNG, JPG or WebP, max 3 MB each. Tall pictures work best &mdash; around 800&times;1000 pixels.</p>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Headline</h2>
            <p class="mt-1 text-sm text-slate-500">The two lines of text at the top of the home page.</p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="hero_title" class="label">Title</label>
                    <input id="hero_title" name="hero_title" type="text" required maxlength="80" value="{{ old('hero_title', $settings['hero_title']) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Shown in capitals, so type it normally.</p>
                </div>
                <div>
                    <label for="hero_subtitle" class="label">Script line</label>
                    <input id="hero_subtitle" name="hero_subtitle" type="text" maxlength="80" value="{{ old('hero_subtitle', $settings['hero_subtitle']) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">The handwritten line under the title. Leave empty to hide it.</p>
                </div>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Offer card</h2>
            <p class="mt-1 text-sm text-slate-500">The dark card beside the headline.</p>

            <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
                <input type="hidden" name="hero_offer_enabled" value="0">
                <input type="checkbox" name="hero_offer_enabled" value="1" @checked(old('hero_offer_enabled', $settings['hero_offer_enabled'])) class="mt-0.5 rounded text-brand-600 focus:ring-brand-500">
                <span>Show the offer card<span class="block text-xs text-slate-500">Turn off while no promotion is running; the rest of the hero stays as it is.</span></span>
            </label>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="hero_offer_kicker" class="label">Top line</label>
                    <input id="hero_offer_kicker" name="hero_offer_kicker" type="text" maxlength="30" value="{{ old('hero_offer_kicker', $settings['hero_offer_kicker']) }}" class="input" placeholder="Up to">
                </div>
                <div>
                    <label for="hero_offer_value" class="label">Discount</label>
                    <input id="hero_offer_value" name="hero_offer_value" type="text" maxlength="10" value="{{ old('hero_offer_value', $settings['hero_offer_value']) }}" class="input" placeholder="50">
                    <p class="mt-1 text-xs text-slate-500">The big number.</p>
                </div>
                <div>
                    <label for="hero_offer_suffix" class="label">After the number</label>
                    <input id="hero_offer_suffix" name="hero_offer_suffix" type="text" maxlength="6" value="{{ old('hero_offer_suffix', $settings['hero_offer_suffix']) }}" class="input" placeholder="%">
                    <p class="mt-1 text-xs text-slate-500">Printed smaller, e.g. % or Tk.</p>
                </div>
                <div>
                    <label for="hero_offer_off" class="label">Under the number</label>
                    <input id="hero_offer_off" name="hero_offer_off" type="text" maxlength="20" value="{{ old('hero_offer_off', $settings['hero_offer_off']) }}" class="input" placeholder="Off">
                </div>
                <div class="sm:col-span-2">
                    <label for="hero_offer_label" class="label">Bottom line</label>
                    <input id="hero_offer_label" name="hero_offer_label" type="text" maxlength="40" value="{{ old('hero_offer_label', $settings['hero_offer_label']) }}" class="input" placeholder="On new arrivals">
                </div>
                <div>
                    <label for="hero_offer_button" class="label">Button text</label>
                    <input id="hero_offer_button" name="hero_offer_button" type="text" maxlength="30" value="{{ old('hero_offer_button', $settings['hero_offer_button']) }}" class="input" placeholder="Shop now">
                    <p class="mt-1 text-xs text-slate-500">Leave empty to hide the button.</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="hero_offer_link" class="label">Button link</label>
                    <input id="hero_offer_link" name="hero_offer_link" type="text" maxlength="300" value="{{ old('hero_offer_link', $settings['hero_offer_link']) }}" class="input" placeholder="{{ route('shop.index', ['on_sale' => 1]) }}">
                    <p class="mt-1 text-xs text-slate-500">Leave empty to send shoppers to the products that are on sale.</p>
                </div>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">Save hero section</button>
            <a href="{{ route('home') }}" target="_blank" rel="noopener" class="text-sm text-slate-500 hover:text-slate-800">Preview home page</a>
        </div>
    </form>
@endsection

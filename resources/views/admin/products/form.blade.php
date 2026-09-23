@extends('layouts.admin')

@section('title', $product->exists ? 'Edit product' : 'New product')
@section('heading', $product->exists ? 'Edit product' : 'New product')

@section('content')
    @php
        $blank = ['id' => null, 'color' => '', 'size' => '', 'sku' => '', 'barcode' => '', 'cost_price' => '', 'price' => '', 'sale_price' => '', 'opening_stock' => '', 'low_stock_threshold' => '', 'is_active' => true];
        $existing = $product->variants->keyBy('id');

        // After a validation error, rebuild the rows exactly as they were submitted.
        $source = old('variants', $product->variants->map(fn ($variant) => [
            'id' => $variant->id, 'color' => $variant->color, 'size' => $variant->size, 'sku' => $variant->sku,
            'barcode' => $variant->barcode, 'cost_price' => $variant->cost_price, 'price' => $variant->price,
            'sale_price' => $variant->sale_price, 'low_stock_threshold' => $variant->low_stock_threshold,
            'is_active' => $variant->is_active,
        ])->all());

        $rows = collect($source)->values()->map(function ($row, $i) use ($blank, $existing) {
            $row = array_merge($blank, array_map(fn ($value) => $value ?? '', (array) $row));
            $id = filled($row['id']) ? (int) $row['id'] : null;

            return array_merge($row, [
                'key' => 'row' . $i,
                'id' => $id,
                'is_active' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
                'stock' => $id ? $existing->get($id)?->stock : null,
            ]);
        });

        if ($rows->isEmpty()) {
            $rows = collect([array_merge($blank, ['key' => 'row0', 'stock' => null])]);
        }

        $config = [
            'hasVariants' => (bool) old('has_variants', $product->has_variants),
            'category' => (string) old('category_id', $product->category_id),
            'subcategory' => (string) old('subcategory_id', $product->subcategory_id),
            'tree' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'children' => $c->children->map->only(['id', 'name'])->values()])->values(),
            'rows' => $rows->all(),
            'stockUrl' => route('admin.stock.create', ['variant' => '__ID__']),
            'canAdjust' => auth()->user()->can('inventory.adjust'),
        ];

        $defaultThreshold = \App\Support\Settings::get('low_stock_threshold');
    @endphp

    <script>
        function productForm(config) {
            return {
                ...config,
                colors: '',
                sizes: '',
                counter: config.rows.length,
                get subcategories() {
                    const match = this.tree.find(category => String(category.id) === String(this.category));
                    return match ? match.children : [];
                },
                stockLink(id) {
                    return this.stockUrl.replace('__ID__', id);
                },
                blankRow(color = '', size = '') {
                    return { key: 'new' + (this.counter++), id: null, color, size, sku: '', barcode: '', cost_price: '', price: '', sale_price: '', opening_stock: '', low_stock_threshold: '', is_active: true, stock: null };
                },
                generate() {
                    const split = value => value.split(',').map(part => part.trim()).filter(Boolean);
                    const colors = split(this.colors);
                    const sizes = split(this.sizes);

                    if (! colors.length && ! sizes.length) return;

                    for (const color of (colors.length ? colors : [''])) {
                        for (const size of (sizes.length ? sizes : [''])) {
                            const same = row => String(row.color).trim().toLowerCase() === color.toLowerCase()
                                && String(row.size).trim().toLowerCase() === size.toLowerCase();

                            if (this.rows.some(same)) continue;

                            // Label an unnamed row (such as the original default variant) before adding new ones.
                            const unlabeled = this.rows.find(row => ! String(row.color).trim() && ! String(row.size).trim());

                            if (unlabeled) {
                                unlabeled.color = color;
                                unlabeled.size = size;
                            } else {
                                this.rows.push(this.blankRow(color, size));
                            }
                        }
                    }

                    this.colors = '';
                    this.sizes = '';
                },
                addRow() {
                    this.rows.push(this.blankRow());
                },
                removeRow(index) {
                    const row = this.rows[index];

                    if (row.id && Number(row.stock) !== 0) {
                        alert('This variant still has ' + row.stock + ' in stock. Bring its stock to 0 or untick Active instead.');
                        return;
                    }

                    if (row.id && ! confirm('Remove this variant? If it has sales or stock history it will be archived instead of deleted.')) return;

                    this.rows.splice(index, 1);

                    if (! this.rows.length) this.rows.push(this.blankRow());
                },
            };
        }

        /**
         * The gallery picker: files arrive by dropping them on the box or by
         * picking them, previews sit right under it, and thumbnails — saved
         * ones and new ones alike — can be dragged into the order the shop
         * should show them in.
         *
         * Two things carry that order to the server: the file input, whose
         * FileList is rebuilt (via DataTransfer) on every change, and the
         * hidden `image_order[]` fields listing the saved images by id.
         */
        function galleryPicker(saved, max, main) {
            return {
                saved,
                max,
                /** The main image already on the product, and the one just chosen to replace it. */
                mainSaved: main,
                mainPick: null,
                picks: [],
                note: '',
                warn: false,
                dragging: false,
                held: null,
                get mainUrl() {
                    return this.mainPick || this.mainSaved;
                },
                pickMain(event) {
                    const file = (event.target.files || [])[0];

                    if (this.mainPick) URL.revokeObjectURL(this.mainPick);

                    this.mainPick = file && file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
                },
                /** Drops the new choice and shows the saved image again. */
                clearMain() {
                    if (this.mainPick) URL.revokeObjectURL(this.mainPick);

                    this.mainPick = null;
                    this.$refs.main.value = '';
                },
                /** Slots left on the product, counting what is already saved. */
                get free() {
                    return Math.max(0, this.max - this.saved.length);
                },
                get room() {
                    return Math.max(0, this.free - this.picks.length);
                },
                drop(event) {
                    this.dragging = false;
                    this.add(event.dataTransfer.files);
                },
                /** New files are added to the ones already chosen, not swapped for them. */
                add(list) {
                    const arriving = Array.from(list || []);
                    const images = arriving.filter(file => file.type.startsWith('image/'));
                    const kept = this.picks.map(pick => pick.file);
                    const skipped = { type: arriving.length - images.length, dupe: 0, full: 0 };

                    for (const file of images) {
                        const same = other => other.name === file.name
                            && other.size === file.size
                            && other.lastModified === file.lastModified;

                        if (kept.some(same)) {
                            skipped.dupe++;
                        } else if (kept.length >= this.free) {
                            skipped.full++;
                        } else {
                            kept.push(file);
                        }
                    }

                    this.reset(kept);
                    this.explain(skipped);
                },
                explain(skipped) {
                    const reasons = [];

                    if (skipped.type) reasons.push(skipped.type + ' not an image');
                    if (skipped.dupe) reasons.push(skipped.dupe + ' already chosen');
                    if (skipped.full) reasons.push(skipped.full + ' over the limit of ' + this.max);

                    this.warn = reasons.length > 0;
                    this.note = reasons.length ? 'Skipped: ' + reasons.join(', ') + '.' : '';
                },
                remove(index) {
                    const kept = this.picks.map(pick => pick.file);
                    kept.splice(index, 1);

                    this.note = '';
                    this.warn = false;
                    this.reset(kept);
                },
                reset(files) {
                    this.picks.forEach(pick => URL.revokeObjectURL(pick.url));
                    this.picks = files.map((file, i) => ({
                        key: i + '-' + file.name + '-' + file.lastModified,
                        file,
                        name: file.name,
                        url: URL.createObjectURL(file),
                    }));

                    this.syncInput();
                },
                syncInput() {
                    const bag = new DataTransfer();
                    this.picks.forEach(pick => bag.items.add(pick.file));
                    this.$refs.input.files = bag.files;
                },
                rows(list) {
                    return list === 'saved' ? this.saved : this.picks;
                },
                grab(list, index) {
                    this.held = { list, index };
                },
                /**
                 * Reordering happens while the thumbnail is dragged over its
                 * neighbour, so the row always shows where it would land.
                 * Saved images and new files are separate lists: one is rows in
                 * the database, the other files not uploaded yet.
                 */
                shift(list, index) {
                    if (! this.held || this.held.list !== list || this.held.index === index) return;

                    const rows = this.rows(list);
                    const [moved] = rows.splice(this.held.index, 1);

                    rows.splice(index, 0, moved);
                    this.held.index = index;

                    if (list === 'picks') this.syncInput();
                },
                move(list, index, step) {
                    const rows = this.rows(list);
                    const to = index + step;

                    if (to < 0 || to >= rows.length) return;

                    const [moved] = rows.splice(index, 1);

                    rows.splice(to, 0, moved);

                    if (list === 'picks') this.syncInput();
                },
            };
        }
    </script>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
          class="grid gap-6 xl:grid-cols-[1fr_340px]"
          x-data="productForm(@js($config))">
        @csrf
        @if($product->exists) @method('PUT') @endif

        <div class="min-w-0 space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Basics</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="label">Product name</label>
                        <input id="name" name="name" type="text" required maxlength="180" value="{{ old('name', $product->name) }}" class="input">
                    </div>

                    <div>
                        <label for="slug" class="label">Slug <span class="text-slate-400">(auto if blank)</span></label>
                        <input id="slug" name="slug" type="text" maxlength="200" value="{{ old('slug', $product->slug) }}" class="input">
                    </div>

                    <div>
                        <label for="sku" class="label">Product SKU <span class="text-slate-400">(auto if blank)</span></label>
                        <input id="sku" name="sku" type="text" maxlength="80" value="{{ old('sku', $product->sku) }}" class="input font-mono">
                        <p class="mt-1 text-xs text-slate-500" x-show="hasVariants">Parent code. Each variant has its own SKU below.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="short_description" class="label">Short description</label>
                        <input id="short_description" name="short_description" type="text" maxlength="300"
                               value="{{ old('short_description', $product->short_description) }}" class="input">
                    </div>

                    <div class="sm:col-span-2">
                        <label for="description" class="label">Full description</label>
                        <textarea id="description" name="description" rows="6" class="input">{{ old('description', $product->description) }}</textarea>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="tags" class="label">Tags <span class="text-slate-400">(comma separated)</span></label>
                        <input id="tags" name="tags" type="text" maxlength="500" value="{{ old('tags', implode(', ', $product->tags ?? [])) }}" class="input" placeholder="eid, cotton, party wear">
                    </div>
                </div>
            </section>

            <section class="card p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Variants, stock &amp; barcodes</h2>
                        <p class="mt-1 text-sm text-slate-500">Stock is tracked per variant. Existing stock changes only from the Stock screen, so every change is recorded.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="hidden" name="has_variants" :value="hasVariants ? 1 : 0">
                        <input type="checkbox" x-model="hasVariants" class="rounded text-brand-600 focus:ring-brand-500">
                        Comes in sizes / colours
                    </label>
                </div>

                {{-- Product without options: a single default variant. --}}
                <template x-if="! hasVariants">
                    <div class="mt-5">
                        <p x-show="rows.length > 1" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            This product has several variants. Remove the extra variants before turning sizes and colours off.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <input type="hidden" name="variants[0][id]" :value="rows[0].id ?? ''">
                            <div>
                                <label class="label" for="single_barcode">Barcode</label>
                                <input id="single_barcode" type="text" name="variants[0][barcode]" x-model="rows[0].barcode" maxlength="64" class="input font-mono" placeholder="Scan, type or leave blank">
                            </div>
                            <div>
                                <template x-if="! rows[0].id">
                                    <div>
                                        <label class="label" for="single_opening">Opening stock</label>
                                        <input id="single_opening" type="number" min="0" name="variants[0][opening_stock]" x-model="rows[0].opening_stock" class="input" placeholder="0">
                                    </div>
                                </template>
                                <template x-if="rows[0].id">
                                    <div>
                                        <p class="label">In stock</p>
                                        <p class="flex items-center gap-3 py-2.5 text-sm">
                                            <span class="font-bold text-slate-900" x-text="rows[0].stock"></span>
                                            <a x-show="canAdjust" :href="stockLink(rows[0].id)" class="text-brand-600 hover:underline">Adjust stock</a>
                                        </p>
                                    </div>
                                </template>
                            </div>
                            <div>
                                <label class="label" for="single_threshold">Low stock alert at</label>
                                <input id="single_threshold" type="number" min="0" name="variants[0][low_stock_threshold]" x-model="rows[0].low_stock_threshold" class="input" placeholder="Default ({{ $defaultThreshold }})">
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Sizes / colours: one row per variant. --}}
                <template x-if="hasVariants">
                    <div class="mt-5">
                        <div class="grid gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_auto]">
                            <div>
                                <label class="label" for="gen_colors">Colours</label>
                                <input id="gen_colors" x-model="colors" type="text" class="input" placeholder="Black, White, Maroon" @keydown.enter.prevent="generate()">
                            </div>
                            <div>
                                <label class="label" for="gen_sizes">Sizes</label>
                                <input id="gen_sizes" x-model="sizes" type="text" class="input" placeholder="S, M, L, XL" @keydown.enter.prevent="generate()">
                            </div>
                            <div class="flex items-end">
                                <button type="button" @click="generate()" class="btn-secondary w-full">Add combinations</button>
                            </div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[1000px] text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2">Colour</th>
                                        <th class="px-2 py-2">Size</th>
                                        <th class="px-2 py-2">SKU</th>
                                        <th class="px-2 py-2">Barcode</th>
                                        <th class="px-2 py-2">Purchase</th>
                                        <th class="px-2 py-2">Selling</th>
                                        <th class="px-2 py-2">Sale</th>
                                        <th class="px-2 py-2">Stock</th>
                                        <th class="px-2 py-2">Alert at</th>
                                        <th class="px-2 py-2 text-center">Active</th>
                                        <th class="px-2 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <template x-for="(row, index) in rows" :key="row.key">
                                        <tr class="align-top">
                                            <td class="px-2 py-2">
                                                <input type="hidden" :name="`variants[${index}][id]`" :value="row.id ?? ''">
                                                <input type="text" :name="`variants[${index}][color]`" x-model="row.color" maxlength="40" class="input px-2 py-1.5" aria-label="Colour">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="text" :name="`variants[${index}][size]`" x-model="row.size" maxlength="40" class="input w-20 px-2 py-1.5" aria-label="Size">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="text" :name="`variants[${index}][sku]`" x-model="row.sku" maxlength="80" class="input px-2 py-1.5 font-mono text-xs" placeholder="Auto" aria-label="SKU">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="text" :name="`variants[${index}][barcode]`" x-model="row.barcode" maxlength="64" class="input px-2 py-1.5 font-mono text-xs" placeholder="Scan or blank" aria-label="Barcode">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="number" step="0.01" min="0" :name="`variants[${index}][cost_price]`" x-model="row.cost_price" class="input w-24 px-2 py-1.5" placeholder="Default" aria-label="Purchase price">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="number" step="0.01" min="0" :name="`variants[${index}][price]`" x-model="row.price" class="input w-24 px-2 py-1.5" placeholder="Default" aria-label="Selling price">
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="number" step="0.01" min="0" :name="`variants[${index}][sale_price]`" x-model="row.sale_price" class="input w-24 px-2 py-1.5" placeholder="—" aria-label="Sale price">
                                            </td>
                                            <td class="px-2 py-2">
                                                <template x-if="! row.id">
                                                    <input type="number" min="0" :name="`variants[${index}][opening_stock]`" x-model="row.opening_stock" class="input w-20 px-2 py-1.5" placeholder="0" aria-label="Opening stock">
                                                </template>
                                                <template x-if="row.id">
                                                    <a :href="canAdjust ? stockLink(row.id) : null" class="inline-block py-1.5 font-semibold text-slate-900 hover:text-brand-600" x-text="row.stock" title="Adjust stock"></a>
                                                </template>
                                            </td>
                                            <td class="px-2 py-2">
                                                <input type="number" min="0" :name="`variants[${index}][low_stock_threshold]`" x-model="row.low_stock_threshold" class="input w-20 px-2 py-1.5" placeholder="{{ $defaultThreshold }}" aria-label="Low stock alert">
                                            </td>
                                            <td class="px-2 py-2 text-center">
                                                <input type="hidden" :name="`variants[${index}][is_active]`" :value="row.is_active ? 1 : 0">
                                                <input type="checkbox" x-model="row.is_active" class="mt-2.5 rounded text-brand-600 focus:ring-brand-500" aria-label="Active">
                                            </td>
                                            <td class="px-2 py-2">
                                                <button type="button" @click="removeRow(index)" class="mt-1 rounded p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove variant">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                            <button type="button" @click="addRow()" class="text-sm font-medium text-brand-600 hover:underline">+ Add a variant</button>
                            <span>Blank SKUs are generated (e.g. KRT-BLK-M-001). Blank prices use the product prices.</span>
                        </div>
                    </div>
                </template>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="generate_barcodes" value="1" @checked(old('generate_barcodes')) class="rounded text-brand-600 focus:ring-brand-500">
                        Generate a barcode for every variant that does not have one
                    </label>
                    @if($product->exists)
                        <a href="{{ route('admin.barcodes.index', ['product' => $product->id]) }}" class="text-sm font-medium text-brand-600 hover:underline">Print barcode labels</a>
                    @endif
                </div>
            </section>

            @php
                $mainImage = $product->image ? asset('storage/' . $product->image) : '';

                $savedImages = $product->exists
                    ? $product->images->map(fn ($image) => ['id' => $image->id, 'url' => $image->url])->values()
                    : collect();
            @endphp

            <section class="card p-6" x-data="galleryPicker(@js($savedImages), {{ \App\Models\Product::MAX_GALLERY_IMAGES }}, @js($mainImage))">
                <h2 class="text-base font-bold text-slate-900">Images</h2>

                <div class="mt-5 grid gap-5 sm:grid-cols-[200px_1fr]">
                    <div>
                        <label for="image" class="label">Main image</label>
                        <input id="image" name="image" type="file" accept="image/*" class="input p-2"
                               x-ref="main" @change="pickMain($event)">

                        <div class="mt-3" x-show="mainUrl" x-cloak>
                            <div class="relative w-28">
                                <img :src="mainUrl" alt="" class="aspect-square w-28 rounded-lg object-cover ring-1"
                                     :class="mainPick ? 'ring-brand-300' : 'ring-slate-200'">
                                <button type="button" @click="clearMain()" x-show="mainPick"
                                        class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-rose-600 text-xs text-white"
                                        aria-label="Undo this choice">&times;</button>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-500"
                               x-text="mainPick ? 'New — replaces the current one when you save' : 'Current'"></p>
                        </div>
                    </div>

                    <div>
                        <label for="gallery" class="label">
                            Gallery <span class="text-slate-400">(up to {{ \App\Models\Product::MAX_GALLERY_IMAGES }})</span>
                        </label>

                        <button type="button" @click="$refs.input.click()"
                                @dragover.prevent="dragging = true"
                                @dragenter.prevent="dragging = true"
                                @dragleave.self.prevent="dragging = false"
                                @drop.prevent="drop($event)"
                                :class="dragging ? 'border-brand-500 bg-brand-50' : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50'"
                                class="flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-2 text-left transition">
                            <svg class="h-5 w-5 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>
                            </svg>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium leading-5 text-slate-700">Drop images here, or click to choose</span>
                                <span class="block text-xs leading-4 text-slate-500" x-text="room
                                    ? 'Up to ' + room + ' more · JPG or PNG, 2 MB each'
                                    : 'The gallery is full — remove one to add another.'"></span>
                            </span>
                        </button>

                        <input id="gallery" name="gallery[]" type="file" accept="image/*" multiple class="sr-only"
                               x-ref="input" @change="add($event.target.files)">

                        <p class="mt-2 text-xs" :class="warn ? 'text-rose-600' : 'text-slate-500'"
                           x-text="note || (picks.length ? picks.length + ' selected · ' + room + ' slot(s) left' : '')"
                           x-show="note || picks.length" x-cloak></p>

                        <p class="mt-3 text-xs text-slate-500" x-show="saved.length || picks.length" x-cloak>
                            Drag to reorder &mdash; the first one leads the shop gallery. The named ones are being added now.
                        </p>

                        {{-- Saved images and new files share one grid, so the whole gallery reads as one row of tiles. --}}
                        <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6"
                             x-show="saved.length || picks.length" x-cloak>
                            <template x-for="(image, index) in saved" :key="'saved-' + image.id">
                                <div draggable="true"
                                     @dragstart="grab('saved', index)"
                                     @dragover.prevent="shift('saved', index)"
                                     @dragend="held = null"
                                     :class="held && held.list === 'saved' && held.index === index ? 'opacity-40' : ''"
                                     class="cursor-move">
                                    <div class="relative">
                                        <img :src="image.url" alt="" class="aspect-square w-full rounded-lg object-cover ring-1 ring-slate-200">
                                        <span class="absolute left-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-slate-900/70 text-[10px] font-semibold text-white"
                                              x-text="index + 1"></span>
                                        <button type="button"
                                                @click="if (confirm('Remove this image?')) document.getElementById('del-img-' + image.id).submit()"
                                                class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-rose-600 text-xs text-white"
                                                aria-label="Remove this image">&times;</button>
                                    </div>
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" @click="move('saved', index, -1)" :disabled="index === 0"
                                                class="px-1 text-xs text-slate-400 hover:text-brand-600 disabled:opacity-30"
                                                aria-label="Move earlier">&larr;</button>
                                        <button type="button" @click="move('saved', index, 1)" :disabled="index === saved.length - 1"
                                                class="px-1 text-xs text-slate-400 hover:text-brand-600 disabled:opacity-30"
                                                aria-label="Move later">&rarr;</button>
                                    </div>

                                    {{-- The order the thumbnails sit in is what gets saved. --}}
                                    <input type="hidden" name="image_order[]" :value="image.id">
                                </div>
                            </template>

                            <template x-for="(pick, index) in picks" :key="pick.key">
                                <div draggable="true"
                                     @dragstart="grab('picks', index)"
                                     @dragover.prevent="shift('picks', index)"
                                     @dragend="held = null"
                                     :class="held && held.list === 'picks' && held.index === index ? 'opacity-40' : ''"
                                     class="cursor-move">
                                    <div class="relative">
                                        <img :src="pick.url" alt="" class="aspect-square w-full rounded-lg object-cover ring-1 ring-brand-300">
                                        <span class="absolute left-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-brand-600 text-[10px] font-semibold text-white"
                                              x-text="saved.length + index + 1"></span>
                                        <button type="button" @click="remove(index)"
                                                class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-rose-600 text-xs text-white"
                                                aria-label="Remove this image">&times;</button>
                                    </div>
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" @click="move('picks', index, -1)" :disabled="index === 0"
                                                class="px-1 text-xs text-slate-400 hover:text-brand-600 disabled:opacity-30"
                                                aria-label="Move earlier">&larr;</button>
                                        <button type="button" @click="move('picks', index, 1)" :disabled="index === picks.length - 1"
                                                class="px-1 text-xs text-slate-400 hover:text-brand-600 disabled:opacity-30"
                                                aria-label="Move later">&rarr;</button>
                                    </div>
                                    <p class="truncate text-center text-[11px] text-slate-500" x-text="pick.name" :title="pick.name"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Pricing</h2>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="cost_price" class="label">Purchase price <span class="text-slate-400">(cost)</span></label>
                        <input id="cost_price" name="cost_price" type="number" step="0.01" min="0"
                               value="{{ old('cost_price', $product->cost_price) }}" class="input">
                        <p class="mt-1 text-xs text-slate-500">Used for stock value and profit. Never shown to customers.</p>
                    </div>
                    <div>
                        <label for="price" class="label">Selling price</label>
                        <input id="price" name="price" type="number" step="0.01" min="0" required
                               value="{{ old('price', $product->price) }}" class="input">
                    </div>
                    <div>
                        <label for="sale_price" class="label">Discounted price <span class="text-slate-400">(optional)</span></label>
                        <input id="sale_price" name="sale_price" type="number" step="0.01" min="0"
                               value="{{ old('sale_price', $product->sale_price) }}" class="input">
                    </div>
                </div>
            </section>

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Organisation</h2>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="category_id" class="label">Category</label>
                        <select id="category_id" name="category_id" required class="input" x-model="category" @change="subcategory = ''">
                            <option value="">Select a category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="subcategory_id" class="label">Subcategory</label>
                        <select id="subcategory_id" name="subcategory_id" class="input" x-model="subcategory" :disabled="! subcategories.length">
                            <option value="">None</option>
                            <template x-for="child in subcategories" :key="child.id">
                                <option :value="String(child.id)" x-text="child.name" :selected="String(child.id) === String(subcategory)"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="brand_id" class="label">Brand</label>
                            <a href="{{ route('admin.brands.index') }}" class="mb-1.5 text-xs text-brand-600 hover:underline" target="_blank">Manage</a>
                        </div>
                        <select id="brand_id" name="brand_id" class="input">
                            <option value="">No brand</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active ?? true)) class="rounded text-brand-600 focus:ring-brand-500">
                        Active (visible in the shop)
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured)) class="rounded text-brand-600 focus:ring-brand-500">
                        Featured
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_new_arrival" value="1" @checked(old('is_new_arrival', $product->is_new_arrival)) class="rounded text-brand-600 focus:ring-brand-500">
                        New arrival
                    </label>
                </div>
            </section>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
                <a href="{{ route('admin.products.index') }}" class="btn-secondary">Cancel</a>
            </div>

            @if($product->exists)
                <p class="text-xs text-slate-400">Created {{ $product->created_at?->format('d M Y') }} &middot; updated {{ $product->updated_at?->diffForHumans() }}</p>
            @endif
        </aside>
    </form>

    @if($product->exists)
        @foreach($product->images as $image)
            <form id="del-img-{{ $image->id }}" method="POST" action="{{ route('admin.products.images.destroy', $image) }}" class="hidden">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif
@endsection

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** @return list<UploadedFile> */
    protected function images(int $count): array
    {
        return array_map(fn (int $i) => UploadedFile::fake()->image("shot-$i.jpg", 600, 800), range(1, $count));
    }

    /** Keeps the product's existing variant, so an update is only about the gallery. */
    protected function editPayload(Product $product, array $overrides = []): array
    {
        return $this->payload($overrides + [
            'variants' => [['id' => $product->variants()->value('id')]],
        ]);
    }

    protected function payload(array $overrides = []): array
    {
        $category = Category::firstOrCreate(['name' => 'Saree'], ['is_active' => true]);

        return array_merge([
            'category_id' => $category->id,
            'name' => 'Rang Bangladesh Saree',
            'price' => 2400,
            'is_active' => 1,
            'variants' => [['opening_stock' => 4]],
        ], $overrides);
    }

    public function test_six_gallery_images_can_be_uploaded_in_one_go(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(6)]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'));

        $product = Product::sole();

        $this->assertSame(6, $product->images()->count());
        $this->assertSame([0, 1, 2, 3, 4, 5], $product->images()->orderBy('sort_order')->pluck('sort_order')->all());

        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_more_than_six_at_once_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(7)]))
            ->assertSessionHasErrors('gallery');

        $this->assertSame(0, Product::count());
    }

    public function test_an_edit_cannot_push_the_gallery_past_six(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(4)]));

        $product = Product::sole();

        // Four are already there, so three more would make seven.
        $this->actingAs($staff)
            ->put(route('admin.products.update', $product), $this->editPayload($product, ['gallery' => $this->images(3)]))
            ->assertSessionHasErrors('gallery');

        $this->assertSame(4, $product->images()->count());
    }

    public function test_images_added_later_are_ordered_after_the_existing_ones(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(4)]));

        $product = Product::sole();

        $this->actingAs($staff)
            ->put(route('admin.products.update', $product), $this->editPayload($product, ['gallery' => $this->images(2)]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame([0, 1, 2, 3, 4, 5], $product->images()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_the_create_form_shows_an_empty_drop_box(): void
    {
        $this->actingAs($this->staff())
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee("galleryPicker([], 6, '')", false)
            ->assertSee('name="gallery[]"', false)
            ->assertSee('Drop images here, or click to choose')
            ->assertSee('@drop.prevent', false);
    }

    public function test_the_edit_form_hands_the_saved_images_to_the_picker(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(2)]));

        $product = Product::sole();

        $this->actingAs($staff)
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Drag to reorder')
            ->assertSee('name="image_order[]"', false)
            ->assertSee('\u0022id\u0022:' . $product->images()->value('id'), false);
    }

    public function test_saved_images_are_stored_in_the_order_the_form_posts(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(3)]));

        $product = Product::sole();
        $ids = $product->images()->orderBy('sort_order')->pluck('id')->all();
        $wanted = [$ids[2], $ids[0], $ids[1]];

        $this->actingAs($staff)
            ->put(route('admin.products.update', $product), $this->editPayload($product, ['image_order' => $wanted]))
            ->assertSessionHasNoErrors();

        $this->assertSame($wanted, $product->images()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_a_reorder_and_new_uploads_in_one_save_keep_the_new_ones_last(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(3)]));

        $product = Product::sole();
        $ids = $product->images()->orderBy('sort_order')->pluck('id')->all();

        $this->actingAs($staff)
            ->put(route('admin.products.update', $product), $this->editPayload($product, [
                'image_order' => [$ids[1], $ids[2], $ids[0]],
                'gallery' => $this->images(2),
            ]))
            ->assertSessionHasNoErrors();

        $saved = $product->images()->orderBy('sort_order')->pluck('id')->all();

        $this->assertSame([$ids[1], $ids[2], $ids[0]], array_slice($saved, 0, 3));
        $this->assertSame([0, 1, 2, 3, 4], $product->images()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_an_image_belonging_to_another_product_cannot_be_reordered(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload(['gallery' => $this->images(2)]));
        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload([
            'name' => 'Another Saree', 'gallery' => $this->images(2),
        ]));

        [$mine, $theirs] = Product::orderBy('id')->get()->all();
        $stranger = $theirs->images()->orderBy('sort_order')->first();
        $order = $mine->images()->orderBy('sort_order')->pluck('id')->all();

        $this->actingAs($staff)
            ->put(route('admin.products.update', $mine), $this->editPayload($mine, [
                'image_order' => [$stranger->id, ...array_reverse($order)],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($theirs->id, $stranger->fresh()->product_id);
        $this->assertSame(array_reverse($order), $mine->images()->orderBy('sort_order')->pluck('id')->all());
    }
    public function test_the_main_image_is_previewed_on_both_forms(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('pickMain($event)', false)
            ->assertSee("galleryPicker([], 6, '')", false);

        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 800),
        ]));

        $product = Product::sole();

        $this->actingAs($staff)
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('pickMain($event)', false)
            ->assertSee(str_replace('/', '\/', 'storage/' . $product->image), false);

        Storage::disk('public')->assertExists($product->image);
    }
}
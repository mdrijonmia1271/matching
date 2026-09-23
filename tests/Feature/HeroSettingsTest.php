<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeroSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'hero_title' => 'Winter is here',
            'hero_subtitle' => 'Handloom picks',
            'hero_offer_enabled' => '1',
            'hero_offer_kicker' => 'Flat',
            'hero_offer_value' => '30',
            'hero_offer_suffix' => '%',
            'hero_offer_off' => 'Off',
            'hero_offer_label' => 'On sarees',
            'hero_offer_button' => 'Grab it',
            'hero_offer_link' => '',
        ], $overrides);
    }

    public function test_the_home_page_shows_the_hero_copy_from_settings(): void
    {
        $this->actingAs($this->staff())
            ->put(route('admin.settings.hero.update'), $this->payload())
            ->assertSessionHas('success');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Winter is here')
            ->assertSee('Handloom picks')
            ->assertSee('Grab it')
            ->assertSee('On sarees')
            // An empty link falls back to the products that are on sale.
            ->assertSee(route('shop.index', ['on_sale' => 1]), false);
    }

    public function test_the_offer_card_can_be_switched_off(): void
    {
        $this->actingAs($this->staff())
            ->put(route('admin.settings.hero.update'), $this->payload(['hero_offer_enabled' => '0']));

        $this->get(route('home'))->assertOk()->assertDontSee('Grab it');
    }

    public function test_an_offer_without_a_value_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->from(route('admin.settings.hero.edit'))
            ->put(route('admin.settings.hero.update'), $this->payload(['hero_offer_value' => '']))
            ->assertRedirect(route('admin.settings.hero.edit'))
            ->assertSessionHas('error');

        $this->assertSame('50', Settings::get('hero_offer_value'));
    }

    public function test_uploaded_pictures_replace_the_product_picture_in_the_hero(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff())
            ->put(route('admin.settings.hero.update'), $this->payload([
                'hero_images' => [
                    UploadedFile::fake()->image('one.jpg'),
                    UploadedFile::fake()->image('two.jpg'),
                ],
            ]))
            ->assertSessionHas('success');

        $stored = Settings::get('hero_images');

        $this->assertCount(2, $stored);
        Storage::disk('public')->assertExists($stored[0]);

        $this->get(route('home'))->assertOk()->assertSee(asset('storage/' . $stored[1]), false);
    }

    public function test_removing_a_picture_deletes_the_file_and_closes_the_gap(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff())->put(route('admin.settings.hero.update'), $this->payload([
            'hero_images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ]));

        $before = Settings::get('hero_images');

        $this->actingAs($this->staff())->put(route('admin.settings.hero.update'), $this->payload([
            'remove_hero_images' => [0 => '1'],
        ]));

        $after = Settings::get('hero_images');

        $this->assertSame([$before[1]], $after);
        Storage::disk('public')->assertMissing($before[0]);
    }

    public function test_the_hero_falls_back_to_a_product_picture_when_no_file_is_uploaded(): void
    {
        $category = Category::create(['name' => 'Saree', 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Katan Saree',
            'price' => 4500,
            'stock' => 3,
            'is_active' => true,
            'is_featured' => true,
        ]);

        $this->assertSame([], Settings::get('hero_images'));

        $this->get(route('home'))->assertOk()->assertSee('Katan+Saree', false);
    }

    public function test_staff_without_the_settings_permission_cannot_edit_the_hero(): void
    {
        $sales = $this->staff('sales_staff');

        $this->actingAs($sales)->get(route('admin.settings.hero.edit'))->assertForbidden();
        $this->actingAs($sales)->put(route('admin.settings.hero.update'), $this->payload())->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected function logo(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 240, 80);
    }

    public function test_a_brand_cannot_be_created_without_a_logo(): void
    {
        $this->actingAs($this->staff())
            ->from(route('admin.brands.index'))
            ->post(route('admin.brands.store'), ['name' => 'Lotto'])
            ->assertRedirect(route('admin.brands.index'))
            ->assertSessionHasErrors('logo');

        $this->assertDatabaseMissing('brands', ['name' => 'Lotto']);
    }

    public function test_a_brand_is_created_with_its_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff())
            ->post(route('admin.brands.store'), ['name' => 'Lotto', 'logo' => $this->logo()])
            ->assertSessionHasNoErrors();

        $brand = Brand::where('name', 'Lotto')->firstOrFail();

        $this->assertNotNull($brand->logo);
        Storage::disk('public')->assertExists($brand->logo);
    }

    public function test_editing_keeps_the_existing_logo_and_replacing_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.brands.store'), ['name' => 'Lotto', 'logo' => $this->logo()]);
        $brand = Brand::where('name', 'Lotto')->firstOrFail();
        $first = $brand->logo;

        // No file sent: the brand already has one, so the name alone may be saved.
        $this->actingAs($admin)
            ->put(route('admin.brands.update', $brand), ['name' => 'Lotto Sports', 'is_active' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame($first, $brand->fresh()->logo);

        $this->actingAs($admin)->put(route('admin.brands.update', $brand), [
            'name' => 'Lotto Sports', 'is_active' => 1, 'logo' => $this->logo('new.png'),
        ])->assertSessionHasNoErrors();

        $second = $brand->fresh()->logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_an_svg_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff())
            ->post(route('admin.brands.store'), [
                'name' => 'Lotto',
                'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('logo');
    }

    public function test_the_home_page_lists_brands_from_z_to_a(): void
    {
        Brand::create(['name' => 'Apex', 'logo' => 'brands/apex.png', 'is_active' => true]);
        Brand::create(['name' => 'Zara', 'logo' => 'brands/zara.png', 'is_active' => true]);
        Brand::create(['name' => 'Lotto', 'logo' => 'brands/lotto.png', 'is_active' => true]);

        $this->assertSame(['Zara', 'Lotto', 'Apex'],
            $this->get(route('home'))->assertOk()->viewData('brands')->pluck('name')->all());
    }

    public function test_the_home_page_shows_only_active_brands_that_have_a_logo(): void
    {
        Storage::fake('public');

        $shown = Brand::create(['name' => 'Visible Brand', 'logo' => 'brands/a.png', 'is_active' => true]);
        $inactive = Brand::create(['name' => 'Hidden Brand', 'logo' => 'brands/b.png', 'is_active' => false]);
        $noLogo = Brand::create(['name' => 'Logoless Brand', 'is_active' => true]);

        $response = $this->get(route('home'))->assertOk();

        $response->assertSee($shown->name, false);
        $response->assertDontSee($inactive->name, false);
        $response->assertDontSee($noLogo->name, false);
    }
}

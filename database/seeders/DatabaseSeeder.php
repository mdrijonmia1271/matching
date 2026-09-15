<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the women's clothing demo store.
     *
     * Product photos ship with the repo in database/seeders/images and are
     * copied onto the public disk here, so `migrate:fresh --seed` always
     * produces the same browsable catalogue.
     */
    public function run(): void
    {
        $this->seedUsers();
        $this->seedCatalogue();
        $this->seedCoupons();
    }

    protected function seedUsers(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@matching.test'],
            [
                'name' => 'Store Admin',
                'password' => 'password',
                'phone' => '01700000000',
            ],
        );

        // Access columns are not mass assignable.
        $admin->forceFill([
            'is_admin' => true,
            'is_active' => true,
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
        ])->save();

        User::updateOrCreate(
            ['email' => 'customer@matching.test'],
            [
                'name' => 'Demo Customer',
                'password' => 'password',
                'phone' => '01800000000',
                'address' => 'House 12, Road 5, Dhanmondi, Dhaka',
            ],
        );
    }

    protected function seedCatalogue(): void
    {
        foreach ($this->catalogue() as $sortOrder => $group) {
            $category = Category::updateOrCreate(
                ['slug' => $group['slug']],
                [
                    'name' => $group['name'],
                    'description' => $group['description'],
                    'image' => $this->publishImage($group['products'][0]['image']),
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ],
            );

            foreach ($group['products'] as $data) {
                $product = Product::updateOrCreate(
                    ['slug' => $data['image']],
                    [
                        'category_id' => $category->id,
                        'name' => $data['name'],
                        'sku' => $data['sku'],
                        'short_description' => $data['short'],
                        'description' => $data['description'],
                        'price' => $data['price'],
                        'sale_price' => $data['sale'] ?? null,
                        'image' => $this->publishImage($data['image']),
                        'is_active' => true,
                        'is_featured' => $data['featured'] ?? false,
                        'views' => random_int(20, 900),
                    ],
                );

                // Seeding runs without model events, so new products get their default
                // variant here, with opening stock recorded through the stock ledger.
                if (! $product->variants()->withTrashed()->exists()) {
                    $variant = new ProductVariant(['sku' => $product->sku, 'is_active' => true]);
                    $variant->product_id = $product->id;
                    $variant->save();

                    app(StockService::class)->move($variant, 'in', $data['stock'], 'opening', 'Seeded opening stock');
                }
            }
        }
    }

    /** Copy a seed photo onto the public disk and return its stored path. */
    protected function publishImage(string $name): ?string
    {
        $source = database_path('seeders/images/' . $name . '.jpg');

        if (! File::exists($source)) {
            return null;
        }

        $path = 'products/' . $name . '.jpg';

        Storage::disk('public')->put($path, File::get($source));

        return $path;
    }

    protected function seedCoupons(): void
    {
        Coupon::updateOrCreate(
            ['code' => 'WELCOME10'],
            [
                'type' => 'percent',
                'value' => 10,
                'min_order' => 1000,
                'max_discount' => 500,
                'usage_limit' => 500,
                'is_active' => true,
            ],
        );

        Coupon::updateOrCreate(
            ['code' => 'EID500'],
            [
                'type' => 'fixed',
                'value' => 500,
                'min_order' => 4000,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array<int, array{slug: string, name: string, description: string, products: array<int, array<string, mixed>>}>
     */
    protected function catalogue(): array
    {
        $care = "\n\nFabric care\n- Dry clean recommended for the first wash\n- Wash dark shades separately\n- Iron on medium heat, avoid the embroidery\n\nDelivery\n- Inside Dhaka 1-2 days, outside Dhaka 3-4 days\n- Cash on delivery available, check the parcel before you accept it\n- 7-day exchange if the size does not fit";

        return [
            [
                'slug' => 'saree',
                'name' => 'Saree',
                'description' => 'Katan, jamdani, garad and soft silk sarees for weddings, Eid and everyday wear.',
                'products' => [
                    [
                        'name' => 'Mint Green Katan Silk Saree',
                        'sku' => 'MT-SAR-001',
                        'image' => 'saree-mint-katan',
                        'short' => 'Soft katan silk in mint green with a woven zari border and matching blouse piece.',
                        'description' => "A light katan silk saree in a cool mint shade, woven with a fine self-design body and a golden zari border that catches the light without feeling heavy." . $care,
                        'price' => 6500, 'sale' => 5200, 'stock' => 14, 'featured' => true,
                    ],
                    [
                        'name' => 'Purple Banarasi Silk Saree',
                        'sku' => 'MT-SAR-002',
                        'image' => 'saree-purple-jamdani',
                        'short' => 'Rich purple banarasi silk with dense gold booti work all over the body.',
                        'description' => "Deep purple banarasi silk with gold booti work across the body and a broad woven aanchal. A classic wedding-season pick that photographs beautifully under warm light." . $care,
                        'price' => 9800, 'sale' => 8300, 'stock' => 9, 'featured' => true,
                    ],
                    [
                        'name' => 'Coral Pink Kanjivaram Saree',
                        'sku' => 'MT-SAR-003',
                        'image' => 'saree-pink-orange-silk',
                        'short' => 'Coral and orange dual-tone silk with a contrast magenta blouse piece.',
                        'description' => "A dual-tone coral and orange silk saree with polka zari booti and a wide temple border. Comes with an unstitched contrast blouse piece." . $care,
                        'price' => 11500, 'stock' => 6,
                    ],
                    [
                        'name' => 'White & Red Garad Saree',
                        'sku' => 'MT-SAR-004',
                        'image' => 'saree-white-red-garad',
                        'short' => 'The classic Bengali garad korial - off-white body with a bold red border.',
                        'description' => "The saree every Bengali wardrobe needs. Off-white pure garad body with a red and gold border, perfect for Pahela Baishakh, pujo and family occasions." . $care,
                        'price' => 4800, 'sale' => 3900, 'stock' => 22, 'featured' => true,
                    ],
                    [
                        'name' => 'Navy Blue Jamdani Saree',
                        'sku' => 'MT-SAR-005',
                        'image' => 'saree-navy-jamdani',
                        'short' => 'Handloom jamdani in navy with silver motif work and a pink-gold border.',
                        'description' => "Handwoven jamdani from Narayanganj weavers, navy blue with silver paisley motifs and a pink-gold border. Light on the shoulder, easy to drape all evening." . $care,
                        'price' => 7200, 'stock' => 11,
                    ],
                ],
            ],
            [
                'slug' => 'salwar-kameez',
                'name' => 'Salwar Kameez',
                'description' => 'Ready and semi-stitched three piece sets in cotton, linen and georgette.',
                'products' => [
                    [
                        'name' => 'Red Embroidered Three Piece',
                        'sku' => 'MT-SLK-001',
                        'image' => 'three-piece-red-embroidered',
                        'short' => 'Red cotton kameez with white thread embroidery, printed dupatta and salwar.',
                        'description' => "A ready-to-wear three piece in breathable cotton. The red kameez carries white chikan-style thread work on the yoke and sleeves, paired with a soft printed dupatta." . $care,
                        'price' => 2850, 'sale' => 2280, 'stock' => 26, 'featured' => true,
                    ],
                    [
                        'name' => 'Rose Pink Chikankari Suit',
                        'sku' => 'MT-SLK-002',
                        'image' => 'three-piece-pink-chikan',
                        'short' => 'Flowy rose pink georgette suit with white chikankari and a sheer dupatta.',
                        'description' => "Layered georgette in rose pink with white chikankari flowers spread across the kameez and dupatta. Falls softly, ideal for daytime dawats and Eid visits." . $care,
                        'price' => 3900, 'stock' => 18,
                    ],
                    [
                        'name' => 'Scarlet Cotton Three Piece',
                        'sku' => 'MT-SLK-003',
                        'image' => 'three-piece-red-cotton',
                        'short' => 'Straight-cut scarlet kameez with side slits and a printed cotton dupatta.',
                        'description' => "A straight-cut scarlet kameez with embroidered placket and side slits, worn with cream trousers and a lightly printed cotton dupatta. Office-friendly and comfortable." . $care,
                        'price' => 2450, 'stock' => 31,
                    ],
                ],
            ],
            [
                'slug' => 'kurti-tops',
                'name' => 'Kurti & Tops',
                'description' => 'Everyday kurtis, co-ord sets and tunics in block print, cotton and rayon.',
                'products' => [
                    [
                        'name' => 'White Floral Block Print Kurti Set',
                        'sku' => 'MT-KUR-001',
                        'image' => 'kurti-white-floral',
                        'short' => 'Straight white kurti with pink block-print florals, lace hem and matching pants.',
                        'description' => "Hand block printed on soft white cotton, with a crochet lace hem and a mandarin placket. Comes as a set with matching straight pants." . $care,
                        'price' => 2200, 'sale' => 1760, 'stock' => 34, 'featured' => true,
                    ],
                    [
                        'name' => 'Blush Pink A-Line Kurti Set',
                        'sku' => 'MT-KUR-002',
                        'image' => 'kurti-blush-pink',
                        'short' => 'A-line blush kurti with all-over floral print and coordinated trousers.',
                        'description' => "An A-line kurti in blush pink covered in a fine floral print, with quilted panels and three-quarter sleeves. Sold with matching printed trousers." . $care,
                        'price' => 2400, 'stock' => 27,
                    ],
                    [
                        'name' => 'Coral Floral Co-ord Set',
                        'sku' => 'MT-KUR-003',
                        'image' => 'kurti-pink-coord',
                        'short' => 'Short coral tunic with a tie-neck bow and wide-leg matching trousers.',
                        'description' => "A modern co-ord set: a short tunic with a tie-neck bow and flared wide-leg trousers in the same coral floral print. Easy to dress up or down." . $care,
                        'price' => 2750, 'sale' => 2200, 'stock' => 20, 'featured' => true,
                    ],
                    [
                        'name' => 'Mustard Yellow Tiered Kurti',
                        'sku' => 'MT-KUR-004',
                        'image' => 'kurti-yellow-tiered',
                        'short' => 'Tiered mustard kurti with a colourful embroidered yoke and tassel tie.',
                        'description' => "A flowy tiered kurti in mustard yellow with a densely embroidered yoke in green, pink and orange, finished with a tassel tie at the neck." . $care,
                        'price' => 1850, 'stock' => 40,
                    ],
                ],
            ],
            [
                'slug' => 'lehenga-gown',
                'name' => 'Lehenga & Gown',
                'description' => 'Bridal and party lehengas, ghagra choli and floor-length gowns.',
                'products' => [
                    [
                        'name' => 'Marigold Zari Bridal Lehenga',
                        'sku' => 'MT-LEH-001',
                        'image' => 'lehenga-orange-zari',
                        'short' => 'Marigold silk lehenga with silver zardosi work and a maroon velvet dupatta.',
                        'description' => "A three-piece bridal set: marigold silk lehenga with silver zardosi and paisley work, an embroidered choli, and a maroon velvet dupatta with gota borders." . $care,
                        'price' => 28500, 'sale' => 24500, 'stock' => 4, 'featured' => true,
                    ],
                    [
                        'name' => 'Rose Pink Bridal Lehenga Choli',
                        'sku' => 'MT-LEH-002',
                        'image' => 'lehenga-pink-bridal',
                        'short' => 'Multi-colour thread and mirror work lehenga with a teal velvet choli.',
                        'description' => "Heavily worked bridal lehenga in rose pink with multi-colour resham and mirror work, teal velvet choli, and two dupattas for the head and shoulder drape." . $care,
                        'price' => 34000, 'stock' => 3,
                    ],
                    [
                        'name' => 'Maroon Velvet Bridal Lehenga',
                        'sku' => 'MT-LEH-003',
                        'image' => 'lehenga-maroon-bridal',
                        'short' => 'Maroon velvet lehenga with heavy gold zardosi and a net dupatta.',
                        'description' => "Deep maroon velvet with panelled gold zardosi across the flare, a matching embroidered choli and a soft net dupatta with scattered floral butis." . $care,
                        'price' => 31500, 'sale' => 27900, 'stock' => 5,
                    ],
                ],
            ],
            [
                'slug' => 'abaya-borka',
                'name' => 'Abaya & Borka',
                'description' => 'Dubai-cut abayas, borka and modest outerwear in crepe and nida fabric.',
                'products' => [
                    [
                        'name' => 'Black Embroidered Open Abaya',
                        'sku' => 'MT-ABY-001',
                        'image' => 'abaya-black-embroidered',
                        'short' => 'Front-open black crepe abaya with sage floral embroidery and lace cuffs.',
                        'description' => "Front-open abaya in premium black crepe, with sage-green floral thread work framing the neckline and lace-trimmed bell cuffs. Includes a matching hijab." . $care,
                        'price' => 4900, 'sale' => 3950, 'stock' => 16, 'featured' => true,
                    ],
                    [
                        'name' => 'Beige Flared Belted Abaya',
                        'sku' => 'MT-ABY-002',
                        'image' => 'abaya-beige-flared',
                        'short' => 'Umbrella-cut beige abaya with a fabric belt and tonal embroidery panels.',
                        'description' => "An umbrella-cut abaya in soft beige nida with vertical tonal embroidery panels and a self-fabric belt that shapes the waist. Comes with a matching scarf." . $care,
                        'price' => 5400, 'stock' => 12,
                    ],
                    [
                        'name' => 'Stone Grey Hooded Abaya',
                        'sku' => 'MT-ABY-003',
                        'image' => 'abaya-stone-hooded',
                        'short' => 'Minimal stone grey abaya with an attached hood and inner slip dress.',
                        'description' => "A two-layer set: a stone grey open abaya with an attached hood over a nude inner slip dress. Clean, minimal and easy to wear for travel or work." . $care,
                        'price' => 5900, 'sale' => 4720, 'stock' => 10, 'featured' => true,
                    ],
                    [
                        'name' => 'Navy & Nude Two-Piece Abaya',
                        'sku' => 'MT-ABY-004',
                        'image' => 'abaya-navy-nude',
                        'short' => 'Textured navy overcoat abaya layered over a nude inner dress.',
                        'description' => "A textured navy overcoat abaya with gold button detailing on the sleeve, layered over a nude inner dress. Sold as a complete two-piece set." . $care,
                        'price' => 6300, 'stock' => 8,
                    ],
                ],
            ],
            [
                'slug' => 'hijab-shawl',
                'name' => 'Hijab & Shawl',
                'description' => 'Chiffon and satin hijabs, handloom shawls and winter wraps.',
                'products' => [
                    [
                        'name' => 'White Chiffon Hijab',
                        'sku' => 'MT-HIJ-001',
                        'image' => 'hijab-white-chiffon',
                        'short' => 'Everyday white chiffon hijab, 180 x 75 cm, non-slip finish.',
                        'description' => "A soft chiffon hijab in clean white with a lightly textured finish that holds a pleat without pins slipping. Measures 180 x 75 cm." . $care,
                        'price' => 650, 'sale' => 520, 'stock' => 60,
                    ],
                    [
                        'name' => 'Mustard Satin Hijab',
                        'sku' => 'MT-HIJ-002',
                        'image' => 'hijab-mustard-satin',
                        'short' => 'Glossy mustard satin hijab with a smooth drape and rolled edges.',
                        'description' => "A glossy satin hijab in warm mustard, with hand-rolled edges and enough weight to drape smoothly over the shoulder. Measures 180 x 70 cm." . $care,
                        'price' => 780, 'stock' => 45,
                    ],
                    [
                        'name' => 'Cream Handloom Shawl',
                        'sku' => 'MT-SHW-001',
                        'image' => 'shawl-cream-handloom',
                        'short' => 'Handloom cotton shawl in cream with woven magenta and green stripes.',
                        'description' => "Woven on a handloom in soft cotton, cream with magenta and green stripe borders and a fringed edge. Light enough for late-autumn evenings." . $care,
                        'price' => 1450, 'stock' => 24, 'featured' => true,
                    ],
                    [
                        'name' => 'Blue Printed Pashmina Shawl',
                        'sku' => 'MT-SHW-002',
                        'image' => 'shawl-blue-printed',
                        'short' => 'Indigo pashmina shawl with an ornate paisley print and teal border.',
                        'description' => "A generously sized pashmina-blend shawl in indigo, printed with ornate paisley medallions and finished with a teal border." . $care,
                        'price' => 1950, 'sale' => 1560, 'stock' => 19,
                    ],
                    [
                        'name' => 'Taupe Knit Winter Shawl',
                        'sku' => 'MT-SHW-003',
                        'image' => 'shawl-taupe-knit',
                        'short' => 'Warm taupe knit shawl with pointelle detailing along the border.',
                        'description' => "A thick knit shawl in taupe with pointelle openwork along the border. Wide enough to wrap twice on a cold Dhaka morning." . $care,
                        'price' => 1650, 'stock' => 21,
                    ],
                ],
            ],
        ];
    }
}

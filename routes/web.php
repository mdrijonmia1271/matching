<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\VariantLookupController;
use App\Http\Controllers\Admin\BarcodeLabelController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\OrderPaymentController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StockController as AdminStockController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/product/{product}', [ShopController::class, 'show'])->name('shop.show');

Route::controller(CartController::class)->prefix('cart')->name('cart.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/add/{product}', 'store')->name('store');
    Route::patch('/item/{item}', 'update')->name('update');
    Route::delete('/item/{item}', 'destroy')->name('destroy');
    Route::delete('/', 'clear')->name('clear');
    Route::post('/coupon', 'applyCoupon')->middleware('throttle:20,1')->name('coupon.apply');
    Route::delete('/coupon', 'removeCoupon')->name('coupon.remove');
});

Route::controller(CheckoutController::class)->prefix('checkout')->name('checkout.')->group(function () {
    Route::get('/', 'show')->name('index');
    Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
    Route::get('/success/{order}', 'success')->name('success');
});

// Demo gateway screens. Every URL is signed by the gateway, so none can be forged.
Route::controller(PaymentController::class)->prefix('payment')->name('payment.')->middleware('signed')->group(function () {
    Route::get('/demo/{order}/{payment}', 'show')->name('demo.show');
    Route::post('/demo/{order}/{payment}/success', 'success')->name('demo.success');
    Route::post('/demo/{order}/{payment}/fail', 'fail')->name('demo.fail');
});

/*
|--------------------------------------------------------------------------
| Guest auth
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:30,1');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Customer account
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/account', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/account', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/account/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/{product}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
    Route::delete('/wishlist/{wishlist}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

    Route::post('/product/{product}/review', [ReviewController::class, 'store'])->name('reviews.store');
    Route::delete('/review/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
|
| `admin` lets active staff in; each controller then checks the specific
| permission (can:<permission>) for every action.
|
*/

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('categories', AdminCategoryController::class)->except('show');
    Route::resource('products', AdminProductController::class)->except('show');
    Route::patch('products/{product}/restore', [AdminProductController::class, 'restore'])
        ->withTrashed()->name('products.restore');
    Route::delete('product-images/{image}', [AdminProductController::class, 'destroyImage'])->name('products.images.destroy');
    Route::resource('brands', BrandController::class)->except(['show', 'create']);

    // Barcode / SKU / name lookup used by stock entry and the POS.
    Route::get('variants/search', VariantLookupController::class)->name('variants.search');

    Route::get('barcodes', [BarcodeLabelController::class, 'index'])->name('barcodes.index');
    Route::post('barcodes/print', [BarcodeLabelController::class, 'print'])->name('barcodes.print');
    Route::post('barcodes/generate', [BarcodeLabelController::class, 'generate'])->name('barcodes.generate');

    Route::get('stock', [AdminStockController::class, 'index'])->name('stock.index');
    Route::get('stock/entry', [AdminStockController::class, 'create'])->name('stock.create');
    Route::post('stock', [AdminStockController::class, 'store'])->name('stock.store');
    Route::get('stock/export', [AdminStockController::class, 'export'])->name('stock.export');
    Route::get('stock/{movement}', [AdminStockController::class, 'show'])->whereNumber('movement')->name('stock.show');

    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
    Route::get('inventory/count', [InventoryController::class, 'count'])->name('inventory.count');
    Route::post('inventory/count', [InventoryController::class, 'storeCount'])->name('inventory.count.store');

    Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
    Route::patch('orders/{order}/details', [AdminOrderController::class, 'updateDetails'])->name('orders.details');
    Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store'])->name('orders.payments.store');

    // Archived customers stay viewable, and editable so they can be corrected before restoring.
    Route::resource('customers', CustomerController::class)->withTrashed(['show', 'edit', 'update']);
    Route::patch('customers/{customer}/restore', [CustomerController::class, 'restore'])
        ->withTrashed()->name('customers.restore');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::post('accounts/entries', [AccountController::class, 'storeEntry'])->name('accounts.entries.store');
    Route::post('accounts/transfers', [AccountController::class, 'storeTransfer'])->name('accounts.transfers.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
    Route::get('accounts/{account}/export', [AccountController::class, 'export'])->whereNumber('account')->name('accounts.export');
    Route::get('accounts/{account}/edit', [AccountController::class, 'edit'])->whereNumber('account')->name('accounts.edit');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');

    Route::resource('coupons', AdminCouponController::class)->except('show');

    Route::resource('staff', StaffController::class)->except('show');
    Route::resource('roles', RoleController::class)->except('show');

    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity.index');
});

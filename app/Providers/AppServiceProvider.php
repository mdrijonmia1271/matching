<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CartService;
use App\Services\Payment\PaymentManager;
use App\Services\SettingsRepository;
use App\Support\Permissions;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CartService::class);
        $this->app->singleton(PaymentManager::class);
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL < 5.7.7 caps indexed varchar keys at 767 bytes.
        Schema::defaultStringLength(191);

        Paginator::useTailwind();

        // Prices render the same everywhere: the store currency with two decimals.
        Blade::directive('money', fn (string $expr) => "<?php echo \App\Support\Money::format({$expr}); ?>");

        // Every permission key in config/permissions.php is a gate, answered by
        // the user's role. `can:orders.view` middleware and @can both use this.
        Gate::before(function (User $user, string $ability) {
            return Permissions::exists($ability) ? $user->hasPermission($ability) : null;
        });

        Event::listen(Login::class, function (Login $event) {
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            if ($event->user->isStaff()) {
                AuditLogger::log('auth', 'login', $event->user, $event->user->name . ' logged in', user: $event->user);
            }
        });

        // Header badges are needed on every storefront page.
        View::composer('layouts.app', function ($view) {
            $view->with('headerCartCount', app(CartService::class)->itemCount())
                ->with('headerCategories', Category::active()->topLevel()->orderBy('sort_order')->orderBy('name')->get());
        });
    }
}

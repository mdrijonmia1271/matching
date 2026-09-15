<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers: one record per person the shop sells to, linked from their orders.
 *
 * Backfill (approved by the owner): registered accounts with orders become one
 * customer each; guest orders are matched by normalised phone number. A guest
 * order whose phone already belongs to a customer with a different name gets a
 * separate customer without a phone (with a note saying why), so one phone
 * number never belongs to two customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->nullable()->index();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('customer_group', 20)->default('retail');
            $table->decimal('opening_due', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });

        $this->backfill();
    }

    /** Links every order that has no customer yet. Safe to run again; public so tests can run it on their own orders. */
    public function backfill(): void
    {
        $now = now();

        $userIds = DB::table('orders')->whereNull('customer_id')->whereNotNull('user_id')
            ->distinct()->orderBy('user_id')->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = DB::table('users')->where('id', $userId)->first();

            if (! $user) {
                continue;
            }

            $customerId = DB::table('customers')->where('user_id', $userId)->value('id');

            if (! $customerId) {
                $first = DB::table('orders')->where('user_id', $userId)->orderBy('id')->first();
                $latest = DB::table('orders')->where('user_id', $userId)->orderByDesc('id')->first();

                $phone = Phone::normalise($user->phone ?? null) ?? Phone::normalise($latest->customer_phone);
                $note = null;

                if ($phone && DB::table('customers')->where('phone', $phone)->exists()) {
                    $note = 'Phone ' . $phone . ' already belonged to another customer when customers were introduced.';
                    $phone = null;
                }

                $customerId = DB::table('customers')->insertGetId([
                    'name' => $user->name,
                    'phone' => $phone,
                    'email' => $user->email,
                    'address' => ($user->address ?? null) ?: $latest->shipping_address,
                    'city' => $latest->shipping_city,
                    'customer_group' => 'retail',
                    'opening_due' => 0,
                    'notes' => $note,
                    'user_id' => $userId,
                    'created_at' => $first->created_at,
                    'updated_at' => $now,
                ]);
            }

            DB::table('orders')->where('user_id', $userId)->whereNull('customer_id')->update(['customer_id' => $customerId]);
        }

        foreach (DB::table('orders')->whereNull('customer_id')->orderBy('id')->get() as $order) {
            $phone = Phone::normalise($order->customer_phone);
            $owner = $phone ? DB::table('customers')->where('phone', $phone)->first() : null;

            if ($owner && $this->sameName($owner->name, $order->customer_name)) {
                $customerId = $owner->id;
            } elseif ($owner || ! $phone) {
                $customerId = $this->phonelessCustomer($order, $owner, $now);
            } else {
                $customerId = $this->insertFromOrder($order, $phone, null, $now);
            }

            DB::table('orders')->where('id', $order->id)->update(['customer_id' => $customerId]);
        }
    }

    /** Guest orders without a usable phone are grouped by name and email instead. */
    protected function phonelessCustomer(object $order, ?object $owner, $now): int
    {
        $existing = DB::table('customers')->whereNull('phone')->whereNull('user_id')
            ->where('email', $order->customer_email)
            ->get()
            ->first(fn (object $customer) => $this->sameName($customer->name, $order->customer_name));

        if ($existing) {
            return $existing->id;
        }

        $note = $owner
            ? 'Order phone ' . Phone::normalise($order->customer_phone) . ' belongs to customer ' . $owner->name . ', so it was not saved on this record. Confirm this customer\'s real number.'
            : null;

        return $this->insertFromOrder($order, null, $note, $now);
    }

    protected function insertFromOrder(object $order, ?string $phone, ?string $note, $now): int
    {
        return DB::table('customers')->insertGetId([
            'name' => trim($order->customer_name),
            'phone' => $phone,
            'email' => $order->customer_email,
            'address' => $order->shipping_address,
            'city' => $order->shipping_city,
            'customer_group' => 'retail',
            'opening_due' => 0,
            'notes' => $note,
            'user_id' => null,
            'created_at' => $order->created_at,
            'updated_at' => $now,
        ]);
    }

    protected function sameName(?string $a, ?string $b): bool
    {
        $clean = fn (?string $name) => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $name)));

        return $clean($a) === $clean($b);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};

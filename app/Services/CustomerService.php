<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;

/** Finds the customer behind a sale, creating one the first time they buy. */
class CustomerService
{
    /**
     * Registered buyers are matched by their account, everyone else by phone
     * number. Details already on file are never overwritten from checkout, only
     * blanks are filled, and an archived customer who buys again is restored.
     * Call inside the order transaction.
     */
    public function findOrCreateForCheckout(
        ?User $user,
        string $name,
        ?string $phone,
        ?string $email = null,
        ?string $address = null,
        ?string $city = null,
        bool $retried = false,
    ): Customer {
        $phone = Phone::normalise($phone);
        $details = ['name' => trim($name), 'email' => $email, 'address' => $address, 'city' => $city];

        if ($user && ($customer = Customer::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first())) {
            return $this->fillBlanks($customer, $details + ['phone' => $phone]);
        }

        $byPhone = $phone ? Customer::withTrashed()->where('phone', $phone)->lockForUpdate()->first() : null;

        // A guest record with this phone becomes the account's record when they register and buy.
        if ($byPhone && (! $user || $byPhone->user_id === null)) {
            if ($user) {
                $byPhone->forceFill(['user_id' => $user->id]);
            }

            return $this->fillBlanks($byPhone, $details);
        }

        // New customer. A phone that belongs to another account's customer stays on that record only.
        $customer = new Customer($details + ['phone' => $byPhone ? null : $phone, 'customer_group' => 'retail', 'opening_due' => 0]);
        $customer->forceFill(['user_id' => $user?->id]);

        try {
            $customer->save();
        } catch (UniqueConstraintViolationException $e) {
            // Another checkout with the same new phone (or account) saved first: use that record.
            if ($retried) {
                throw $e;
            }

            return $this->findOrCreateForCheckout($user, $name, $phone, $email, $address, $city, retried: true);
        }

        return $customer;
    }

    /** @param  array<string, string|null>  $details */
    protected function fillBlanks(Customer $customer, array $details): Customer
    {
        foreach ($details as $key => $value) {
            if (blank($value) || filled($customer->{$key})) {
                continue;
            }

            if ($key === 'phone' && Customer::withTrashed()->where('phone', $value)->whereKeyNot($customer->id)->exists()) {
                continue;
            }

            $customer->{$key} = $value;
        }

        if ($customer->trashed()) {
            $customer->deleted_at = null;
        }

        $customer->save();

        return $customer;
    }
}

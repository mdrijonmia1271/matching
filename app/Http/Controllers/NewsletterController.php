<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    /**
     * Sign an address up from the home page form.
     *
     * Errors go in a named bag and the notice in its own session key so the
     * message renders beside the form instead of in the page-top flash strip.
     */
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator, 'newsletter')
                ->withInput()
                ->withFragment('newsletter');
        }

        $email = mb_strtolower(trim($validator->validated()['email']));

        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);

        // An address that is already on the list is told so rather than being
        // silently re-dated; one that had left is simply switched back on.
        $alreadySubscribed = $subscriber->exists && $subscriber->isSubscribed();

        if (! $alreadySubscribed) {
            $subscriber->fill([
                'source' => 'home',
                'ip_address' => $request->ip(),
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ])->save();
        }

        return back()
            ->with('newsletter_status', $alreadySubscribed
                ? 'You are already on the list. Thank you!'
                : 'Thanks for subscribing. Check your inbox for new arrivals.')
            ->withFragment('newsletter');
    }
}

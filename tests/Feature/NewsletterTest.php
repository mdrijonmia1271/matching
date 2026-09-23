<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_subscribe_from_the_home_page(): void
    {
        $this->from(route('home'))
            ->post(route('newsletter.store'), ['email' => 'Rijon@Gmail.com'])
            ->assertRedirect(route('home').'#newsletter')
            ->assertSessionHas('newsletter_status');

        $subscriber = NewsletterSubscriber::sole();

        $this->assertSame('rijon@gmail.com', $subscriber->email);
        $this->assertNotNull($subscriber->subscribed_at);
        $this->assertNull($subscriber->unsubscribed_at);
    }

    public function test_an_invalid_address_is_rejected_without_being_stored(): void
    {
        $this->from(route('home'))
            ->post(route('newsletter.store'), ['email' => 'not-an-email'])
            ->assertRedirect(route('home').'#newsletter')
            ->assertSessionHasErrors('email', null, 'newsletter');

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_subscribing_twice_does_not_create_a_duplicate(): void
    {
        $this->post(route('newsletter.store'), ['email' => 'rijon@gmail.com']);
        $this->post(route('newsletter.store'), ['email' => 'rijon@gmail.com']);

        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_a_lapsed_subscriber_is_switched_back_on(): void
    {
        NewsletterSubscriber::create([
            'email' => 'rijon@gmail.com',
            'subscribed_at' => now()->subYear(),
            'unsubscribed_at' => now()->subMonth(),
        ]);

        $this->post(route('newsletter.store'), ['email' => 'rijon@gmail.com']);

        $this->assertNull(NewsletterSubscriber::sole()->unsubscribed_at);
    }
    public function test_staff_can_see_and_filter_the_subscriber_list(): void
    {
        NewsletterSubscriber::create(['email' => 'one@gmail.com', 'subscribed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'two@gmail.com', 'subscribed_at' => now(), 'unsubscribed_at' => now()]);

        $this->actingAs($this->staff())
            ->get(route('admin.newsletter.index'))
            ->assertOk()
            ->assertSee('one@gmail.com')
            ->assertDontSee('two@gmail.com');

        $this->actingAs($this->staff())
            ->get(route('admin.newsletter.index', ['status' => 'unsubscribed']))
            ->assertOk()
            ->assertSee('two@gmail.com')
            ->assertDontSee('one@gmail.com');
    }

    public function test_staff_can_unsubscribe_and_resubscribe_an_address(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rijon@gmail.com', 'subscribed_at' => now()]);
        $staff = $this->staff();

        $this->actingAs($staff)->patch(route('admin.newsletter.toggle', $subscriber))->assertRedirect();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);

        $this->actingAs($staff)->patch(route('admin.newsletter.toggle', $subscriber))->assertRedirect();
        $this->assertNull($subscriber->fresh()->unsubscribed_at);

        $this->assertSame(2, ActivityLog::where('module', 'newsletter')->count());
    }

    public function test_staff_can_delete_a_subscriber(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rijon@gmail.com', 'subscribed_at' => now()]);

        $this->actingAs($this->staff())->delete(route('admin.newsletter.destroy', $subscriber))->assertRedirect();

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_the_export_returns_the_filtered_rows_as_csv(): void
    {
        NewsletterSubscriber::create(['email' => 'one@gmail.com', 'subscribed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'two@gmail.com', 'subscribed_at' => now(), 'unsubscribed_at' => now()]);

        $response = $this->actingAs($this->staff())->get(route('admin.newsletter.export'));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('one@gmail.com', $csv);
        $this->assertStringNotContainsString('two@gmail.com', $csv);
    }

    public function test_staff_without_the_marketing_permission_are_blocked(): void
    {
        $this->actingAs($this->staff('warehouse_staff'))
            ->get(route('admin.newsletter.index'))
            ->assertForbidden();
    }
}
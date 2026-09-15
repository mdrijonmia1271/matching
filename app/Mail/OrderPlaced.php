<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class OrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    /** Signed link to the order confirmation, so guests can open it from the email. */
    public string $url;

    public function __construct(public Order $order)
    {
        $this->url = URL::temporarySignedRoute('checkout.success', now()->addDays(30), $order);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order ' . $this->order->order_number . ' confirmed - ' . Settings::get('store_name'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.order-placed');
    }
}

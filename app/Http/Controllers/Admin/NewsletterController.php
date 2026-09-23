<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Services\AuditLogger;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** The newsletter list: who signed up from the storefront, and who has left. */
class NewsletterController extends Controller implements HasMiddleware
{
    public const STATUSES = [
        'subscribed' => 'Subscribed',
        'unsubscribed' => 'Unsubscribed',
        'all' => 'All',
    ];

    public const SORTS = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'email' => 'Email A–Z',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:marketing.manage'),
            new Middleware('can:reports.export', only: ['export']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.newsletter.index', [
            'subscribers' => $this->query($request)->paginate(30)->withQueryString(),
            'stats' => [
                'subscribed' => NewsletterSubscriber::subscribed()->count(),
                'unsubscribed' => NewsletterSubscriber::unsubscribed()->count(),
                'this_month' => NewsletterSubscriber::subscribed()
                    ->where('subscribed_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
            'statuses' => self::STATUSES,
            'sorts' => self::SORTS,
            'status' => $this->status($request),
            'sort' => $this->sort($request),
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->cursor() as $subscriber) {
                yield [
                    $subscriber->email,
                    $subscriber->isSubscribed() ? 'Subscribed' : 'Unsubscribed',
                    $subscriber->source,
                    $subscriber->subscribed_at?->format('Y-m-d H:i') ?? '',
                    $subscriber->unsubscribed_at?->format('Y-m-d H:i') ?? '',
                ];
            }
        })();

        return CsvExport::download('newsletter-' . now()->format('Y-m-d-His') . '.csv',
            ['Email', 'Status', 'Source', 'Subscribed at', 'Unsubscribed at'], $rows);
    }

    /** One button that removes or restores, so the row stays a single toggle. */
    public function toggle(NewsletterSubscriber $newsletter)
    {
        if ($newsletter->isSubscribed()) {
            $newsletter->unsubscribe();
            AuditLogger::log('newsletter', 'unsubscribed', $newsletter, $newsletter->email);

            return back()->with('success', $newsletter->email . ' was removed from the list.');
        }

        $newsletter->resubscribe();
        AuditLogger::log('newsletter', 'resubscribed', $newsletter, $newsletter->email);

        return back()->with('success', $newsletter->email . ' was added back to the list.');
    }

    public function destroy(NewsletterSubscriber $newsletter)
    {
        $email = $newsletter->email;

        $newsletter->delete();
        AuditLogger::log('newsletter', 'deleted', null, $email);

        return back()->with('success', $email . ' was deleted.');
    }

    protected function query(Request $request): Builder
    {
        $query = NewsletterSubscriber::query()->search($request->input('q'));

        match ($this->status($request)) {
            'unsubscribed' => $query->unsubscribed(),
            'all' => null,
            default => $query->subscribed(),
        };

        match ($this->sort($request)) {
            'oldest' => $query->orderBy('subscribed_at')->orderBy('id'),
            'email' => $query->orderBy('email'),
            default => $query->orderByDesc('subscribed_at')->orderByDesc('id'),
        };

        return $query;
    }

    protected function status(Request $request): string
    {
        return array_key_exists((string) $request->input('status'), self::STATUSES)
            ? (string) $request->input('status')
            : 'subscribed';
    }

    protected function sort(Request $request): string
    {
        return array_key_exists((string) $request->input('sort'), self::SORTS)
            ? (string) $request->input('sort')
            : 'newest';
    }
}

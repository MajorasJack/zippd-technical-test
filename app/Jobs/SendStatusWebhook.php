<?php

namespace App\Jobs;

use App\Models\OrderStatusChange;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;

#[Tries(5)]
#[Backoff(10, 30, 60, 300)]
class SendStatusWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public OrderStatusChange $statusChange,
    ) {
    }

    /**
     * Send the status change to the partner's webhook URL, signed with the shared secret.
     *
     * A change already marked as delivered is skipped, so a retry after a success does no harm. The body
     * is encoded once and those exact bytes are signed and sent, so the partner can check the signature
     * against what it receives. Redirects are not followed, because Guzzle would turn the POST into a body-less
     * GET and report success. Any non-2xx status, a connection failure or a missing URL or secret throws, so
     * the queue retries with the backoff above and the alerts channel reports it once retries run out.
     */
    public function handle(): void
    {
        if ($this->statusChange->webhook_delivered_at !== null) {
            return;
        }

        $body = json_encode(
            [
                'event_id' => $this->statusChange->id,
                'order_id' => $this->statusChange->order_id,
                'partner_reference' => $this->statusChange->order->partner_reference,
                'from_status' => $this->statusChange->from_status?->value,
                'to_status' => $this->statusChange->to_status->value,
                'changed_at' => $this->statusChange->created_at->toIso8601String(),
            ],
            JSON_THROW_ON_ERROR,
        );

        Http::timeout(5)
            ->withoutRedirecting()
            ->withHeaders([
                'X-Event-Id' => (string) $this->statusChange->id,
                'X-Signature' => hash_hmac(
                    'sha256',
                    $body,
                    config()->string('services.partner.webhook_secret'),
                ),
            ])
            ->withBody($body)
            ->post(config()->string('services.partner.webhook_url'))
            ->throwUnlessStatus(fn (int $status): bool => $status >= 200 && $status < 300);

        $this->statusChange->update([
            'webhook_delivered_at' => now(),
            'webhook_failed_at' => null,
        ]);
    }

    /**
     * Mark the status change as failed to deliver once every retry is used up.
     *
     * Alerting happens in the central Queue::failing() listener, not here.
     */
    public function failed(): void
    {
        $this->statusChange->update(['webhook_failed_at' => now()]);
    }
}

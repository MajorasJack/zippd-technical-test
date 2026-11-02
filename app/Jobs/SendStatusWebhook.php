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

    /**
     * Create a new job instance.
     */
    public function __construct(
        public OrderStatusChange $statusChange,
    ) {}

    /**
     * Send the status change to the partner's webhook URL, signed with the shared secret.
     *
     * A change that is already marked as delivered is never sent again, so a retry after a success
     * does no harm. The body is encoded once and those exact bytes are signed and sent, so the
     * partner can check the signature against what it receives. An error status or a connection
     * failure throws, so the queue retries it with the backoff above. A missing URL or secret
     * throws too, so the job fails and the alerts channel shows it.
     */
    public function handle(): void
    {
        if ($this->statusChange->webhook_delivered_at !== null) {
            return;
        }

        $body = json_encode([
            'event_id' => $this->statusChange->id,
            'order_id' => $this->statusChange->order_id,
            'partner_reference' => $this->statusChange->order->partner_reference,
            'from_status' => $this->statusChange->from_status?->value,
            'to_status' => $this->statusChange->to_status->value,
            'changed_at' => $this->statusChange->created_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        Http::timeout(5)
            ->withHeaders([
                'X-Event-Id' => (string) $this->statusChange->id,
                'X-Signature' => hash_hmac('sha256', $body, config()->string('services.partner.webhook_secret')),
            ])
            ->withBody($body)
            ->post(config()->string('services.partner.webhook_url'))
            ->throw();

        $this->statusChange->update(['webhook_delivered_at' => now()]);
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

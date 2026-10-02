<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use XerAds\Laravel\Support\Tables;

/**
 * Every delivery this site has seen, so none is applied twice (`xerads_deliveries`).
 *
 * ── Why a ledger, when requests are already signed ──────────────────────────
 * A signature proves who sent a request, not that it is the first time it
 * arrived. XerAds retries anything that timed out, and a timeout can happen
 * after this site already stored the article. Without a record of what was
 * processed, a retry carrying the same delivery id creates the post a second
 * time.
 *
 * ── How a claim works ───────────────────────────────────────────────────────
 * The first request inserts a `processing` row with a lease and a random
 * claim token. A concurrent request with the same id finds the lease and
 * backs off. A finished one stores its response, which every later copy
 * receives unchanged. A failed one, or one whose lease ran out (the worker
 * died mid-request), may be claimed again under a new token, because nothing
 * durable is known to have happened.
 *
 * The outcome is recorded only by the holder of the current token, so a slow
 * request that lost its claim cannot overwrite the result of the one that
 * took over. The lease is never shorter than the signature's timestamp
 * tolerance: a copy can only verify within that window, so within it the
 * original always either holds the lease or has finished.
 *
 * A site that has not run the migration yet keeps working, without this
 * protection, and says so in the log.
 */
final class DeliveryLedger
{
    public const LEASE_SECONDS = 120;

    private bool $warned = false;

    public function __construct(private readonly Tables $tables) {}

    public function available(): bool
    {
        if ($this->tables->exists('deliveries')) {
            return true;
        }

        if (! $this->warned) {
            $this->warned = true;

            Log::warning(
                'The '.$this->tables->name('deliveries').' table does not exist, so XerAds deliveries are not '
                .'checked for duplicates. Run `php artisan migrate`.'
            );
        }

        return false;
    }

    /** Null when the ledger is unavailable and the caller should just proceed. */
    public function claim(string $deliveryId, string $event, ?int $leaseSeconds = null): ?LedgerClaim
    {
        if (! $this->available()) {
            return null;
        }

        $now = Carbon::now();
        $leaseUntil = $now->copy()->addSeconds($this->leaseSeconds($leaseSeconds));
        $token = bin2hex(random_bytes(16));

        $inserted = $this->query()->insertOrIgnore([
            'delivery_id' => $deliveryId,
            'event' => $event,
            'status' => 'processing',
            'claim_token' => $token,
            'processing_until' => $leaseUntil,
            'received_at' => $now,
        ]);

        if ($inserted > 0) {
            return LedgerClaim::claimed($deliveryId, $token);
        }

        $row = $this->query()->where('delivery_id', $deliveryId)->first();

        if ($row === null) {
            // Pruned between the insert and the read. Vanishingly rare; treat
            // it as new rather than refusing a legitimate delivery. Nothing is
            // recorded for it, which only costs the duplicate check.
            return LedgerClaim::claimed($deliveryId, $token);
        }

        if ($row->status === 'ok') {
            $response = is_string($row->response) ? json_decode($row->response, true) : null;

            return LedgerClaim::replay($deliveryId, (int) $row->response_code, is_array($response) ? $response : []);
        }

        $leaseRunning = $row->status === 'processing'
            && $row->processing_until !== null
            && Carbon::parse($row->processing_until)->isFuture();

        if ($leaseRunning) {
            return LedgerClaim::inProgress($deliveryId);
        }

        /*
         * Failed, or the previous worker's lease expired. Take it over only if
         * the row still holds the token just read, so two retries racing for
         * an expired lease cannot both win.
         */
        $taken = $this->query()
            ->where('delivery_id', $deliveryId)
            ->where('status', $row->status)
            ->where('claim_token', $row->claim_token)
            ->update([
                'status' => 'processing',
                'claim_token' => $token,
                'processing_until' => $leaseUntil,
                'error' => null,
            ]);

        return $taken > 0 ? LedgerClaim::claimed($deliveryId, $token) : LedgerClaim::inProgress($deliveryId);
    }

    /**
     * Record a success; later copies of this delivery get `$response` back.
     *
     * @param  array<mixed>  $response
     * @return bool false when this claim no longer holds the delivery
     */
    public function complete(LedgerClaim $claim, int $responseCode, array $response): bool
    {
        return $this->finish($claim, 'ok', $responseCode, $response, null);
    }

    /**
     * Record a failure; the next copy of this delivery is processed again.
     *
     * @param  array<mixed>|null  $response
     * @return bool false when this claim no longer holds the delivery
     */
    public function fail(LedgerClaim $claim, int $responseCode, ?array $response, string $error): bool
    {
        return $this->finish($claim, 'failed', $responseCode, $response, mb_substr($error, 0, 2000));
    }

    /** @param  array<mixed>|null  $response */
    private function finish(LedgerClaim $claim, string $status, int $responseCode, ?array $response, ?string $error): bool
    {
        if (! $claim->isClaimed() || ! $this->available()) {
            return false;
        }

        $updated = $this->query()
            ->where('delivery_id', $claim->deliveryId)
            ->where('claim_token', $claim->token)
            ->where('status', 'processing')
            ->update([
                'status' => $status,
                'processing_until' => null,
                'response_code' => $responseCode,
                'response' => $response !== null
                    ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    : null,
                'error' => $error,
                'processed_at' => Carbon::now(),
            ]);

        return $updated > 0;
    }

    private function leaseSeconds(?int $requested): int
    {
        return max(
            $requested ?? self::LEASE_SECONDS,
            (int) config('xerads.webhook.timestamp_tolerance', 300),
            (int) config('xerads.legacy.timestamp_tolerance', 300),
        );
    }

    private function query(): Builder
    {
        return $this->tables->connection()->table($this->tables->name('deliveries'));
    }
}

<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Sync\Envelope;

/**
 * `php artisan xerads:simulate`: a signed delivery, as XerAds would send it,
 * through this site's own HTTP kernel.
 *
 * For trying a setup without the dashboard: the same middleware, signature
 * check, ledger, pipeline and receiver a real delivery meets, with the
 * reply printed. It writes real rows, so it refuses to run in production
 * unless told to, and it leaves articles XerAds delivered for real alone
 * unless told to.
 *
 * A simulated delivery never holds up a real one: its delivery id marks it,
 * and the next real event for the same article is applied whatever its
 * sequence.
 */
final class SimulateCommand extends Command
{
    protected $signature = 'xerads:simulate
        {--event=article.upsert : The event to send}
        {--fixture= : A JSON file holding an envelope or an article (default: a sample article)}
        {--status=publish : draft or publish, for article.upsert}
        {--force : Run in production, or over an article XerAds delivered for real}';

    protected $description = 'Send a signed XerAds delivery to this site';

    public function handle(CredentialsResolver $credentials, V2Signer $signer, HttpKernel $kernel): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('This writes real articles. Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $event = (string) $this->option('event');

        if (! in_array($event, Envelope::EVENTS, true)) {
            $this->error('Unknown event. One of: '.implode(', ', Envelope::EVENTS));

            return self::INVALID;
        }

        try {
            $current = $credentials->current();
        } catch (InvalidSiteKey $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($current === null) {
            $this->error('This site has no site key yet, so there is nothing to sign with. Pair it, or set XERADS_SITE_KEY.');

            return self::FAILURE;
        }

        $article = $this->article();

        if ($article === null && str_starts_with($event, 'article.')) {
            $this->error('The fixture holds no article.');

            return self::FAILURE;
        }

        $entry = str_starts_with($event, 'article.') ? ContentMapEntry::forArticle((string) ($article['xerads_id'] ?? '')) : null;

        if ($entry !== null && ! $entry->wasSimulated() && ! $this->option('force')) {
            $this->error('XerAds manages this article on this site. A simulated delivery would replace what XerAds sent; use --force to do it anyway (the next real delivery for it still applies).');

            return self::FAILURE;
        }

        // Marked as simulated in the signed delivery id, so the content map
        // knows a real delivery always supersedes what this writes, whatever
        // its sequence.
        $deliveryId = ContentMapEntry::SIMULATED_DELIVERY_PREFIX.Str::ulid();
        $body = json_encode([
            'contract' => Envelope::CONTRACT,
            'event' => $event,
            'delivery_id' => $deliveryId,
            'occurred_at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'site_id' => $current->siteId,
            'sequence' => $this->sequence($article),
            'data' => $this->data($event, $article) ?: new \stdClass,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = Carbon::now()->getTimestamp();

        $request = Request::create(route('xerads.webhook'), 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XERADS_CONTRACT' => (string) Envelope::CONTRACT,
            'HTTP_X_XERADS_EVENT' => $event,
            'HTTP_X_XERADS_SITE' => $current->siteId,
            'HTTP_X_XERADS_KEY_ID' => $current->keyId,
            'HTTP_X_XERADS_DELIVERY' => $deliveryId,
            'HTTP_X_XERADS_ATTEMPT' => '1',
            'HTTP_X_XERADS_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_XERADS_SIGNATURE' => $signer->push($current->secret(), $timestamp, $deliveryId, $body),
        ], content: $body);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $reply = json_decode((string) $response->getContent(), true);

        $this->line("{$event} → HTTP {$response->getStatusCode()}");
        $this->line(is_array($reply)
            ? (string) json_encode($reply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $response->getContent());

        return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The article from the fixture: an envelope's `data.article`, or a bare
     * article.
     *
     * @return array<string, mixed>|null
     */
    private function article(): ?array
    {
        $path = $this->option('fixture');
        $path = is_string($path) && $path !== '' ? $path : __DIR__.'/../../resources/fixtures/article-upsert.json';

        if (! is_file($path)) {
            return null;
        }

        $fixture = json_decode((string) file_get_contents($path), true);

        if (! is_array($fixture)) {
            return null;
        }

        $article = is_array($fixture['data']['article'] ?? null) ? $fixture['data']['article'] : $fixture;

        return isset($article['xerads_id']) ? $article : null;
    }

    /**
     * One past what this site holds for the article, so the delivery is
     * never dropped as stale however often it is simulated.
     *
     * @param  array<string, mixed>|null  $article
     */
    private function sequence(?array $article): int
    {
        $id = is_string($article['xerads_id'] ?? null) ? $article['xerads_id'] : null;

        return ($id !== null ? (ContentMapEntry::forArticle($id)->sequence ?? 0) : 0) + 1;
    }

    /**
     * @param  array<string, mixed>|null  $article
     * @return array<string, mixed>
     */
    private function data(string $event, ?array $article): array
    {
        return match ($event) {
            'article.upsert' => ['article' => ['status' => $this->option('status') === 'draft' ? 'draft' : 'publish'] + $article],
            'article.unpublish', 'article.delete' => [
                'article' => [
                    'xerads_id' => $article['xerads_id'] ?? null,
                    'remote_id' => ContentMapEntry::forArticle((string) ($article['xerads_id'] ?? ''))?->remote_id,
                    'slug' => $article['slug'] ?? null,
                    'public_url' => null,
                ],
                'redirect_to' => null,
            ],
            'settings.updated', 'redirects.updated' => ['version' => Carbon::now()->getTimestamp()],
            default => [],
        };
    }
}

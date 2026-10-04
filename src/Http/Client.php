<?php
declare(strict_types=1);

namespace Elephant\Http;

use Elephant\Future\Deferred;
use Elephant\Loop\EventLoop;
use Elephant\Loop\Source;
use SplObjectStorage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Non-blocking HTTP client that plugs Symfony HttpClient into the event loop.
 *
 * Requests start immediately; the loop polls this source to stream their progress.
 * A request fails with a ConnectionException once its server sends nothing for the request's idle timeout.
 */
final class Client implements Source
{
    /**
     * Requests in flight, mapped to the deferred that receives their response.
     *
     * @var SplObjectStorage<ResponseInterface, Deferred<Response>>
     */
    private readonly SplObjectStorage $pending;

    /**
     * Requests in flight, mapped to how long their server has sent nothing.
     *
     * @var SplObjectStorage<ResponseInterface, Silence>
     */
    private readonly SplObjectStorage $silences;

    /**
     * @param HttpClientInterface $client Symfony client that performs the requests.
     * @param EventLoop $loop Loop that settles the response futures.
     * @param ClockInterface $clock Clock that measures the idle timeout of every request.
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly EventLoop $loop,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
        $this->pending = new SplObjectStorage();
        $this->silences = new SplObjectStorage();
    }

    /**
     * Starts a request and returns its pending response.
     */
    public function request(Method $method, string $url, HttpOptions $options): PendingResponse
    {
        $deferred = new Deferred($this->loop);

        try {
            $response = $this->client->request($method->value, $url, $options->toArray());
        } catch (TransportExceptionInterface $error) {
            $deferred->fail(ConnectionException::from($error));

            return new PendingResponse($deferred->future(), static function (): void {
            });
        }

        $this->pending->offsetSet($response, $deferred);
        $this->silences->offsetSet($response, Silence::of($options, $this->clock));

        return new PendingResponse($deferred->future(), function () use ($response): void {
            $this->cancel($response);
        });
    }

    /**
     * Starts a request that is tried again after connection errors and server errors.
     *
     * @param int $attempts Total number of attempts, including the first one.
     * @param float $pause Seconds to wait between two attempts.
     */
    public function retry(Method $method, string $url, HttpOptions $options, int $attempts, float $pause): PendingResponse
    {
        return (new Retry($this, $this->loop, $method, $url, $options, $attempts, $pause))->start();
    }

    public function isActive(): bool
    {
        return $this->pending->count() > 0;
    }

    /**
     * Handles network activity until a response settles or the wait passes, so the loop regains control quickly.
     *
     * The wait is the loop's timeout, cut short when the idle timeout of a request runs out first.
     */
    public function poll(?float $timeout): void
    {
        foreach ($this->client->stream(iterator_to_array($this->pending, false), $this->wait($timeout)) as $response => $chunk) {
            if ($this->settle($response, $chunk)) {
                return;
            }
        }
    }

    /**
     * Returns how long the stream may wait for network activity, or null to wait until something happens.
     */
    private function wait(?float $timeout): ?float
    {
        $remaining = array_map(
            fn (ResponseInterface $response): float => $this->silences->offsetGet($response)->remaining(),
            iterator_to_array($this->silences, false),
        );

        $wait = min([$timeout ?? INF, ...$remaining]);

        if ($wait === INF) {
            return null;
        }

        return $wait;
    }

    /**
     * Completes or fails the response's future once its last chunk or an error arrives.
     *
     * @return bool Whether polling should stop because a response settled.
     */
    private function settle(ResponseInterface $response, ChunkInterface $chunk): bool
    {
        try {
            if ($this->waiting($response, $chunk)) {
                return false;
            }

            if ($chunk->isFirst()) {
                self::acknowledge($response);
            }

            if ($chunk->isLast()) {
                $this->take($response)->complete(new Response($response));

                return true;
            }
        } catch (TransportExceptionInterface $error) {
            $response->cancel();
            $this->take($response)->fail(ConnectionException::from($error));

            return true;
        }

        $this->silences->offsetGet($response)->heard();

        return false;
    }

    /**
     * Whether the chunk only reports that the stream waited in vain while the request may still stay silent.
     *
     * Once the request's own idle timeout has run out, the timeout chunk is left unhandled,
     * so reading it throws Symfony's idle timeout error instead.
     */
    private function waiting(ResponseInterface $response, ChunkInterface $chunk): bool
    {
        if ($this->silences->offsetGet($response)->expired()) {
            return false;
        }

        return $chunk->isTimeout();
    }

    /**
     * Aborts a request that is still in flight and fails its future with a CancelledException.
     */
    private function cancel(ResponseInterface $response): void
    {
        if ($this->pending->offsetExists($response) === false) {
            return;
        }

        $response->cancel();
        $this->take($response)->cancel();
    }

    /**
     * Reads the status code, so Symfony leaves 4xx and 5xx responses to Response::failed() instead of throwing.
     */
    private static function acknowledge(ResponseInterface $response): void
    {
        $response->getStatusCode();
    }

    /**
     * Stops tracking the response and returns its deferred.
     *
     * @return Deferred<Response>
     */
    private function take(ResponseInterface $response): Deferred
    {
        $deferred = $this->pending->offsetGet($response);
        $this->pending->offsetUnset($response);
        $this->silences->offsetUnset($response);

        return $deferred;
    }
}

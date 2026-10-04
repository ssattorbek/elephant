<?php
declare(strict_types=1);

namespace Elephant\Http;

use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Loop\EventLoop;
use Elephant\Loop\Timer;
use Symfony\Component\HttpClient\HttpOptions;

/**
 * Sends a request again after connection errors and server errors (5xx), until it succeeds or runs out of attempts.
 *
 * Client errors (4xx) are returned at once. Cancelling stops the current attempt and every attempt after it.
 *
 * @internal
 */
final class Retry
{
    /**
     * @var Deferred<Response>
     */
    private readonly Deferred $result;

    /**
     * The attempt that is currently in flight.
     */
    private ?PendingResponse $current = null;

    /**
     * The timer that sends the next attempt once the pause between two attempts is over.
     */
    private ?Timer $next = null;

    private bool $cancelled = false;

    /**
     * @param Client $client Client that sends every attempt.
     * @param EventLoop $loop Loop that waits between attempts.
     * @param int $attempts Total number of attempts, including the first one.
     * @param float $pause Seconds to wait between two attempts.
     */
    public function __construct(
        private readonly Client $client,
        private readonly EventLoop $loop,
        private readonly Method $method,
        private readonly string $url,
        private readonly HttpOptions $options,
        private readonly int $attempts,
        private readonly float $pause,
    ) {
        $this->result = new Deferred($loop);
    }

    /**
     * Sends the first attempt and returns the response of the whole retry.
     */
    public function start(): PendingResponse
    {
        $this->attempt(1);

        return new PendingResponse($this->result->future(), function (): void {
            $this->cancel();
        });
    }

    /**
     * Sends one attempt and decides what to do once it settles.
     */
    private function attempt(int $number): void
    {
        $this->current = $this->client->request($this->method, $this->url, $this->options);
        $future = $this->current->future();

        $future->onSettle(function () use ($future, $number): void {
            $this->settled($future, $number);
        });
    }

    /**
     * Completes or fails the retry, or schedules the next attempt.
     *
     * @param Future<Response> $future
     */
    private function settled(Future $future, int $number): void
    {
        if ($this->cancelled) {
            return;
        }

        if ($future->isFailed()) {
            $this->failed($future, $number);

            return;
        }

        $response = $future->result();

        if ($response->serverError() && $number < $this->attempts) {
            $this->later($number);

            return;
        }

        $this->result->complete($response);
    }

    /**
     * Tries again after a connection error while attempts are left, otherwise fails with the error.
     *
     * @param Future<Response> $future
     */
    private function failed(Future $future, int $number): void
    {
        $error = $future->error();

        if ($error instanceof ConnectionException && $number < $this->attempts) {
            $this->later($number);

            return;
        }

        $this->result->fail($error);
    }

    /**
     * Sends the next attempt after the pause, unless the retry was cancelled meanwhile.
     */
    private function later(int $number): void
    {
        $this->next = $this->loop->delay($this->pause, function () use ($number): void {
            $this->next = null;

            if ($this->cancelled === false) {
                $this->attempt($number + 1);
            }
        });
    }

    /**
     * Stops the current attempt and every attempt after it, removing a pending pause so it no longer keeps the loop alive.
     */
    private function cancel(): void
    {
        if ($this->result->future()->isSettled()) {
            return;
        }

        $this->cancelled = true;
        $this->current?->cancel();

        if ($this->next !== null) {
            $this->loop->cancel($this->next);
            $this->next = null;
        }

        $this->result->cancel();
    }
}

<?php
declare(strict_types=1);

namespace Elephant\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Measures how long the server of a request in flight has sent nothing, against the request's idle timeout.
 *
 * The limit is the request's own timeout option, resolved the way Symfony resolves it:
 * default_socket_timeout when the option is not set, and no limit when it is negative.
 *
 * @internal
 */
final class Silence
{
    /**
     * Request option that holds the idle timeout in seconds.
     */
    private const OPTION = 'timeout';

    /**
     * PHP setting that Symfony falls back to when a request sets no idle timeout.
     */
    private const FALLBACK = 'default_socket_timeout';

    /**
     * Clock time in seconds when the server last sent something.
     */
    private float $since;

    /**
     * @param ClockInterface $clock Clock that measures the silence.
     * @param float $limit Seconds of silence after which the request fails.
     */
    private function __construct(
        private readonly ClockInterface $clock,
        private readonly float $limit,
    ) {
        $this->since = $this->now();
    }

    /**
     * Starts measuring the silence of a request sent with the given options.
     */
    public static function of(HttpOptions $options, ClockInterface $clock): self
    {
        $limit = (float) (new ParameterBag($options->toArray()))->get(self::OPTION, ini_get(self::FALLBACK));

        if ($limit < 0) {
            return new self($clock, INF);
        }

        return new self($clock, $limit);
    }

    /**
     * Starts the measurement over, because the server has just sent something.
     */
    public function heard(): void
    {
        $this->since = $this->now();
    }

    /**
     * Returns the seconds the server may still stay silent before the request times out.
     */
    public function remaining(): float
    {
        return max(0.0, $this->limit - ($this->now() - $this->since));
    }

    /**
     * Whether the server has stayed silent for the whole idle timeout.
     */
    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /**
     * Returns the current clock time in seconds.
     */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}

<?php
declare(strict_types=1);

namespace Elephant\Http;

use Closure;
use Elephant\ElephantException;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Immutable builder for an HTTP request.
 *
 * Every configuration method returns a new instance, so a configured builder can be reused safely.
 */
final class PendingRequest
{
    /**
     * @param Client $client Client that sends the request.
     * @param HttpOptions $options Request options except headers.
     * @param HeaderBag $headers Request headers.
     * @param int $attempts How many times the request is tried in total.
     * @param float $pause Seconds to wait between two attempts.
     */
    public function __construct(
        private readonly Client $client,
        private readonly HttpOptions $options = new HttpOptions(),
        private readonly HeaderBag $headers = new HeaderBag(),
        private readonly int $attempts = 1,
        private readonly float $pause = 0.0,
    ) {
    }

    /**
     * Tries the request again after connection errors and server errors (5xx), up to the given number of attempts.
     *
     * Client errors (4xx) are returned at once. The pause between attempts does not block other tasks.
     * When every attempt fails, the last server error response is returned or the last ConnectionException is thrown.
     *
     * @param int $times Total number of attempts, including the first one.
     * @param float $seconds Seconds to wait between two attempts.
     *
     * @throws ElephantException When fewer than one attempt or a negative pause is given.
     */
    public function retry(int $times, float $seconds = 0.0): self
    {
        if ($times < 1) {
            throw new ElephantException('A request needs at least one attempt.');
        }

        if ($seconds < 0) {
            throw new ElephantException('The pause between attempts cannot be negative.');
        }

        return new self($this->client, clone $this->options, clone $this->headers, $times, $seconds);
    }

    /**
     * Resolves relative request URLs against the given base URL.
     */
    public function baseUrl(string $url): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setBaseUri($url));
    }

    /**
     * Authenticates with a bearer token.
     */
    public function withToken(string $token): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setAuthBearer($token));
    }

    /**
     * Authenticates with HTTP basic authentication.
     */
    public function withBasicAuth(string $username, string $password): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setAuthBasic($username, $password));
    }

    /**
     * Adds several headers.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->configureHeaders(static function (HeaderBag $bag) use ($headers): void {
            $bag->add($headers);
        });
    }

    /**
     * Sets a header, replacing an existing value.
     */
    public function withHeader(string $name, string $value): self
    {
        return $this->configureHeaders(static function (HeaderBag $bag) use ($name, $value): void {
            $bag->set($name, $value);
        });
    }

    /**
     * Sets the User-Agent header.
     */
    public function withUserAgent(string $userAgent): self
    {
        return $this->withHeader('User-Agent', $userAgent);
    }

    /**
     * Sets the Accept header.
     */
    public function accept(string $contentType): self
    {
        return $this->withHeader('Accept', $contentType);
    }

    /**
     * Asks the server for a JSON response.
     */
    public function acceptJson(): self
    {
        return $this->accept('application/json');
    }

    /**
     * Sets the query string parameters.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setQuery($query));
    }

    /**
     * Sends the data as a JSON body.
     *
     * @param array<array-key, mixed> $data
     */
    public function withJson(array $data): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setJson($data));
    }

    /**
     * Sends a raw body with the given content type.
     */
    public function withBody(string $body, string $contentType): self
    {
        return $this
            ->configure(static fn (HttpOptions $options): HttpOptions => $options->setBody($body))
            ->withHeader('Content-Type', $contentType);
    }

    /**
     * Fails the request with a ConnectionException when the server sends nothing for the given number of seconds.
     *
     * This limits silence, not the total time; to limit the total time use ->await(timeout: ...).
     * Like any connection error, the timeout is tried again when retry() allows more attempts.
     */
    public function timeout(float $seconds): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setTimeout($seconds));
    }

    /**
     * Sets how many redirects are followed; zero disables redirects.
     */
    public function maxRedirects(int $max): self
    {
        return $this->configure(static fn (HttpOptions $options): HttpOptions => $options->setMaxRedirects($max));
    }

    /**
     * Sends a GET request.
     */
    public function get(string $url): PendingResponse
    {
        return $this->send(Method::Get, $url);
    }

    /**
     * Sends a POST request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public function post(string $url, array $data = []): PendingResponse
    {
        return $this->payload($data)->send(Method::Post, $url);
    }

    /**
     * Sends a PUT request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public function put(string $url, array $data = []): PendingResponse
    {
        return $this->payload($data)->send(Method::Put, $url);
    }

    /**
     * Sends a PATCH request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public function patch(string $url, array $data = []): PendingResponse
    {
        return $this->payload($data)->send(Method::Patch, $url);
    }

    /**
     * Sends a DELETE request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public function delete(string $url, array $data = []): PendingResponse
    {
        return $this->payload($data)->send(Method::Delete, $url);
    }

    /**
     * Sends a HEAD request.
     */
    public function head(string $url): PendingResponse
    {
        return $this->send(Method::Head, $url);
    }

    /**
     * Sends the request with any method.
     */
    public function send(Method $method, string $url): PendingResponse
    {
        $options = (clone $this->options)->setHeaders($this->headers->all());

        if ($this->attempts === 1) {
            return $this->client->request($method, $url, $options);
        }

        return $this->client->retry($method, $url, $options, $this->attempts, $this->pause);
    }

    /**
     * Adds the data as a JSON body unless it is empty.
     *
     * @param array<array-key, mixed> $data
     */
    private function payload(array $data): self
    {
        if ($data === []) {
            return $this;
        }

        return $this->withJson($data);
    }

    /**
     * Returns a copy with changed options.
     *
     * @param Closure(HttpOptions): HttpOptions $change
     */
    private function configure(Closure $change): self
    {
        return new self($this->client, $change(clone $this->options), clone $this->headers, $this->attempts, $this->pause);
    }

    /**
     * Returns a copy with changed headers.
     *
     * @param Closure(HeaderBag): void $change
     */
    private function configureHeaders(Closure $change): self
    {
        $headers = clone $this->headers;
        $change($headers);

        return new self($this->client, clone $this->options, $headers, $this->attempts, $this->pause);
    }
}

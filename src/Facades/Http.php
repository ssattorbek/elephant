<?php
declare(strict_types=1);

namespace Elephant\Facades;

use Closure;
use Elephant\Application;
use Elephant\Http\Method;
use Elephant\Http\PendingRequest;
use Elephant\Http\PendingResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Static entry point for sending non-blocking HTTP requests.
 *
 * Configuration methods return an immutable PendingRequest; request methods start the request immediately.
 */
final class Http
{
    /**
     * Resolves relative request URLs against the given base URL.
     */
    public static function baseUrl(string $url): PendingRequest
    {
        return self::pending()->baseUrl($url);
    }

    /**
     * Authenticates with a bearer token.
     */
    public static function withToken(string $token): PendingRequest
    {
        return self::pending()->withToken($token);
    }

    /**
     * Authenticates with HTTP basic authentication.
     */
    public static function withBasicAuth(string $username, string $password): PendingRequest
    {
        return self::pending()->withBasicAuth($username, $password);
    }

    /**
     * Adds several headers.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function withHeaders(array $headers): PendingRequest
    {
        return self::pending()->withHeaders($headers);
    }

    /**
     * Sets a header.
     */
    public static function withHeader(string $name, string $value): PendingRequest
    {
        return self::pending()->withHeader($name, $value);
    }

    /**
     * Sets the User-Agent header.
     */
    public static function withUserAgent(string $userAgent): PendingRequest
    {
        return self::pending()->withUserAgent($userAgent);
    }

    /**
     * Sets the Accept header.
     */
    public static function accept(string $contentType): PendingRequest
    {
        return self::pending()->accept($contentType);
    }

    /**
     * Asks the server for a JSON response.
     */
    public static function acceptJson(): PendingRequest
    {
        return self::pending()->acceptJson();
    }

    /**
     * Sets the query string parameters.
     *
     * @param array<string, mixed> $query
     */
    public static function withQuery(array $query): PendingRequest
    {
        return self::pending()->withQuery($query);
    }

    /**
     * Sends the data as a JSON body.
     *
     * @param array<array-key, mixed> $data
     */
    public static function withJson(array $data): PendingRequest
    {
        return self::pending()->withJson($data);
    }

    /**
     * Sends a raw body with the given content type.
     */
    public static function withBody(string $body, string $contentType): PendingRequest
    {
        return self::pending()->withBody($body, $contentType);
    }

    /**
     * Fails the request with a ConnectionException when the server sends nothing for the given number of seconds.
     *
     * This limits silence, not the total time; to limit the total time use ->await(timeout: ...).
     * Like any connection error, the timeout is tried again when retry() allows more attempts.
     */
    public static function timeout(float $seconds): PendingRequest
    {
        return self::pending()->timeout($seconds);
    }

    /**
     * Sets how many redirects are followed; zero disables redirects.
     */
    public static function maxRedirects(int $max): PendingRequest
    {
        return self::pending()->maxRedirects($max);
    }

    /**
     * Tries the request again after connection errors and server errors (5xx), up to the given number of attempts.
     *
     * @param int $times Total number of attempts, including the first one.
     * @param float $seconds Seconds to wait between two attempts.
     */
    public static function retry(int $times, float $seconds = 0.0): PendingRequest
    {
        return self::pending()->retry($times, $seconds);
    }

    /**
     * Sends a GET request.
     */
    public static function get(string $url): PendingResponse
    {
        return self::pending()->get($url);
    }

    /**
     * Sends a POST request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public static function post(string $url, array $data = []): PendingResponse
    {
        return self::pending()->post($url, $data);
    }

    /**
     * Sends a PUT request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public static function put(string $url, array $data = []): PendingResponse
    {
        return self::pending()->put($url, $data);
    }

    /**
     * Sends a PATCH request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public static function patch(string $url, array $data = []): PendingResponse
    {
        return self::pending()->patch($url, $data);
    }

    /**
     * Sends a DELETE request, encoding non-empty data as JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public static function delete(string $url, array $data = []): PendingResponse
    {
        return self::pending()->delete($url, $data);
    }

    /**
     * Sends a HEAD request.
     */
    public static function head(string $url): PendingResponse
    {
        return self::pending()->head($url);
    }

    /**
     * Sends a request with any method.
     */
    public static function send(Method $method, string $url): PendingResponse
    {
        return self::pending()->send($method, $url);
    }

    /**
     * Replaces the real HTTP client with canned responses, for tests.
     *
     * Responses are served in order; a closure receives the method, URL and options of each request.
     *
     * @param ResponseInterface|(Closure(string, string, array<string, mixed>): ResponseInterface)|list<ResponseInterface> $responses
     */
    public static function fake(ResponseInterface|Closure|array $responses): void
    {
        Application::boot(new MockHttpClient($responses));
    }

    /**
     * Starts a fresh request builder.
     */
    private static function pending(): PendingRequest
    {
        return new PendingRequest(Application::http());
    }
}

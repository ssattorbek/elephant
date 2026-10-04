<?php
declare(strict_types=1);

namespace Elephant\Http;

use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response as Status;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyPathBuilder;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Completed HTTP response.
 *
 * Status codes of 400 and above never throw; check them with failed(), clientError() or serverError().
 */
final class Response
{
    private readonly int $status;

    private readonly HeaderBag $headers;

    /**
     * @param ResponseInterface $response Fully received Symfony response.
     */
    public function __construct(
        private readonly ResponseInterface $response,
    ) {
        $this->status = $response->getStatusCode();
        $this->headers = new HeaderBag($response->getHeaders(false));
    }

    /**
     * Returns the HTTP status code.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Returns the standard reason phrase for the status code, such as "Not Found".
     */
    public function reason(): string
    {
        $path = new PropertyPathBuilder();
        $path->appendIndex((string) $this->status);

        return PropertyAccess::createPropertyAccessor()->getValue(Status::$statusTexts, $path->__toString()) ?? 'Non-Standard Status';
    }

    /**
     * Whether the status code is exactly 200.
     */
    public function ok(): bool
    {
        return $this->status === Status::HTTP_OK;
    }

    /**
     * Whether the status code is in the 2xx range.
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Whether the status code is in the 3xx range.
     */
    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * Whether the status code is in the 4xx range.
     */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Whether the status code is 500 or above.
     */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Whether the response is a client or server error.
     */
    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    /**
     * Returns all response headers with case-insensitive access.
     */
    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    /**
     * Returns the first value of a header, or null when it is missing.
     */
    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }

    /**
     * Returns the raw response body.
     */
    public function body(): string
    {
        return $this->response->getContent(false);
    }

    /**
     * Decodes the body as JSON.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        return $this->response->toArray(false);
    }
}

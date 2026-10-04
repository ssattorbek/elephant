<?php
declare(strict_types=1);

use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Facades\Http;
use Elephant\Future\CancelledException;
use Elephant\Http\ConnectionException;
use Elephant\Http\PendingRequest;
use Elephant\Http\Response;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Stopwatch\Stopwatch;

use function Elephant\Flow\{await, delay};
use function Symfony\Component\String\u as String;

describe('responses', function (): void {
    it('returns a typed response', function (): void {
        Http::fake(new JsonMockResponse(['name' => 'Elephant']));

        $response = Http::get('https://api.test/user')->await();

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->ok())->toBeTrue()
            ->and($response->reason())->toBe('OK')
            ->and($response->json())->toBe(['name' => 'Elephant'])
            ->and($response->header('Content-Type'))->toBe('application/json');
    });

    it('classifies status codes', function (int $status, bool $successful, bool $failed, string $reason): void {
        Http::fake(new MockResponse('', ['http_code' => $status]));

        $response = Http::get('https://api.test')->await();

        expect($response->status())->toBe($status)
            ->and($response->successful())->toBe($successful)
            ->and($response->failed())->toBe($failed)
            ->and($response->reason())->toBe($reason);
    })->with([
        'ok' => [200, true, false, 'OK'],
        'created' => [201, true, false, 'Created'],
        'not found' => [404, false, true, 'Not Found'],
        'server error' => [500, false, true, 'Internal Server Error'],
    ]);
});

describe('requests', function (): void {
    beforeEach(function (): void {
        $this->request = new MockResponse();

        Http::fake($this->request);
    });

    it('sends configured headers', function (PendingRequest $http, string $header, string $value): void {
        $http->get('https://api.test')->await();

        expect($this->request)->toHaveSentHeader($header, $value);
    })->with([
        'bearer token' => [fn (): PendingRequest => Http::withToken('secret'), 'Authorization', 'Bearer secret'],
        'basic auth' => [fn (): PendingRequest => Http::withBasicAuth('user', 'pass'), 'Authorization', 'Basic dXNlcjpwYXNz'],
        'single header' => [fn (): PendingRequest => Http::withHeader('X-Trace', 'abc'), 'X-Trace', 'abc'],
        'many headers' => [fn (): PendingRequest => Http::withHeaders(['X-One' => '1']), 'X-One', '1'],
        'user agent' => [fn (): PendingRequest => Http::withUserAgent('Elephant/1.0'), 'User-Agent', 'Elephant/1.0'],
        'json accept' => [fn (): PendingRequest => Http::acceptJson(), 'Accept', 'application/json'],
    ]);

    it('builds the url from a base url and query', function (): void {
        Http::baseUrl('https://api.test')->withQuery(['page' => 2])->get('/users')->await();

        expect($this->request->getRequestUrl())->toBe('https://api.test/users?page=2');
    });

    it('sends arrays as json', function (): void {
        Http::post('https://api.test/users', ['name' => 'Elephant'])->await();

        expect($this->request->getRequestMethod())->toBe('POST')
            ->and($this->request->getRequestOptions()['body'])->toBe('{"name":"Elephant"}')
            ->and($this->request)->toHaveSentHeader('Content-Type', 'application/json');
    });

    it('sends raw bodies', function (): void {
        Http::withBody('hello', 'text/plain')->put('https://api.test/notes')->await();

        expect($this->request->getRequestMethod())->toBe('PUT')
            ->and($this->request->getRequestOptions()['body'])->toBe('hello')
            ->and($this->request)->toHaveSentHeader('Content-Type', 'text/plain');
    });

    it('keeps the base request untouched', function (): void {
        $api = Http::acceptJson();
        $api->withToken('secret');

        $api->get('https://api.test')->await();

        expect($this->request)->not->toHaveSentHeader('Authorization', 'Bearer secret');
    });
});

describe('connection errors', function (): void {
    beforeEach(function (): void {
        Http::fake(new MockResponse('', ['error' => 'Could not resolve host']));
    });

    it('throws a connection exception on await', function (): void {
        expect(fn (): Response => Http::get('https://api.test')->await())
            ->toThrow(ConnectionException::class, 'Could not resolve host');
    });

    it('passes the error to exception handlers', function (): void {
        $message = Http::get('https://api.test')
            ->exception(static fn (ConnectionException $error): string => $error->getMessage());

        expect(await($message))->toContain('Could not resolve host');
    });
});

describe('timeout()', function (): void {
    beforeEach(function (): void {
        $this->server = stream_socket_server('tcp://127.0.0.1:0');
        $this->url = String('http://')->append(stream_socket_get_name($this->server, false))->toString();
    });

    afterEach(function (): void {
        fclose($this->server);
    });

    it('fails when the server stays silent for longer than the timeout', function (): void {
        expect(fn (): Response => Http::timeout(0.2)->get($this->url)->await(2))
            ->toThrow(ConnectionException::class, 'Idle timeout reached');
    });

    it('limits the silence, not the total time', function (): void {
        $response = Http::timeout(0.5)->get($this->url);

        await(delay(0.25));
        $connection = stream_socket_accept($this->server, 1);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n");
        await(delay(0.25));
        fwrite($connection, 'o');
        await(delay(0.25));
        fwrite($connection, 'k');

        expect($response->await(2)->body())->toBe('ok');

        fclose($connection);
    });

    it('tries again after an idle timeout', function (): void {
        $stopwatch = new Stopwatch(true);
        $stopwatch->start('retry');

        expect(fn (): Response => Http::timeout(0.1)->retry(2)->get($this->url)->await(2))
            ->toThrow(ConnectionException::class, 'Idle timeout reached')
            ->and($stopwatch->stop('retry')->getDuration())->toBeGreaterThanOrEqual(200);
    });
});

describe('retry()', function (): void {
    it('tries again after a server error', function (): void {
        Http::fake([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $response = Http::retry(3)->get('https://api.test')->await();

        expect($response->ok())->toBeTrue()
            ->and($response->json())->toBe(['ok' => true]);
    });

    it('tries again after a connection error', function (): void {
        Http::fake([
            new MockResponse('', ['error' => 'Could not resolve host']),
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(Http::retry(2)->get('https://api.test')->await()->ok())->toBeTrue();
    });

    it('returns client errors at once', function (): void {
        Http::fake([
            new MockResponse('', ['http_code' => 404]),
            new JsonMockResponse(['ok' => true]),
        ]);

        expect(Http::retry(3)->get('https://api.test')->await()->status())->toBe(404);
    });

    it('returns the last server error when every attempt fails', function (): void {
        Http::fake([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 502]),
        ]);

        expect(Http::retry(2)->get('https://api.test')->await()->status())->toBe(502);
    });

    it('throws the last connection error when every attempt fails', function (): void {
        Http::fake([
            new MockResponse('', ['error' => 'first']),
            new MockResponse('', ['error' => 'second']),
        ]);

        expect(fn (): Response => Http::retry(2)->get('https://api.test')->await())
            ->toThrow(ConnectionException::class, 'second');
    });

    it('waits between attempts', function (): void {
        Http::fake([
            new MockResponse('', ['http_code' => 500]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $stopwatch = new Stopwatch(true);
        $stopwatch->start('retry');

        Http::retry(2, 0.05)->get('https://api.test')->await();

        expect($stopwatch->stop('retry')->getDuration())->toBeGreaterThanOrEqual(50);
    });

    it('keeps the request options on every attempt', function (): void {
        $second = new MockResponse();

        Http::fake([new MockResponse('', ['http_code' => 500]), $second]);

        Http::withToken('secret')->retry(2)->get('https://api.test')->await();

        expect($second)->toHaveSentHeader('Authorization', 'Bearer secret');
    });

    it('needs at least one attempt', function (): void {
        expect(fn (): PendingRequest => Http::retry(0))->toThrow(ElephantException::class, 'A request needs at least one attempt.');
    });
});

describe('cancel()', function (): void {
    it('stops a request before it finishes', function (): void {
        Http::fake(new JsonMockResponse(['ok' => true]));

        $response = Http::get('https://api.test');
        $response->cancel();

        expect(fn (): Response => $response->await())->toThrow(CancelledException::class);
    });

    it('changes nothing once the response has arrived', function (): void {
        Http::fake(new JsonMockResponse(['ok' => true]));

        $response = Http::get('https://api.test');
        $response->await();
        $response->cancel();

        expect($response->await()->ok())->toBeTrue();
    });

    it('can be handled like any other error', function (): void {
        Http::fake(new JsonMockResponse(['ok' => true]));

        $response = Http::get('https://api.test');
        $handled = $response->exception(static fn (CancelledException $error): string => 'cancelled');
        $response->cancel();

        expect(await($handled))->toBe('cancelled');
    });

    it('stays quiet when nobody awaits the cancelled request', function (): void {
        Http::fake(new JsonMockResponse(['ok' => true]));

        Http::get('https://api.test')->cancel();

        expect(fn (): mixed => await(delay(0.01)))->not->toThrow(CancelledException::class);
    });

    it('stops a retry while it waits between attempts', function (): void {
        $second = new JsonMockResponse(['ok' => true]);

        Http::fake([new MockResponse('', ['http_code' => 500]), $second]);

        $response = Http::retry(2, 0.05)->get('https://api.test');

        await(delay(0.01));
        $response->cancel();
        await(delay(0.08));

        expect(fn (): Response => $response->await())->toThrow(CancelledException::class)
            ->and($second->getRequestOptions())->toBe([]);
    });

    it('lets the loop finish at once when a retry is cancelled between attempts', function (): void {
        Http::fake([new MockResponse('', ['http_code' => 500]), new JsonMockResponse(['ok' => true])]);

        $response = Http::retry(2, 1)->get('https://api.test');

        await(delay(0.01));
        $response->cancel();

        $stopwatch = new Stopwatch(true);
        $stopwatch->start('loop');

        Application::loop()->run();

        expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100);
    });
});

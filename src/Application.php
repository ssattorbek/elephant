<?php
declare(strict_types=1);

namespace Elephant;

use Elephant\Console\ErrorRenderer;
use Elephant\Console\Writer;
use Elephant\Http\Client;
use Elephant\Loop\EventLoop;
use Elephant\Tasks\Combinator;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Service container of the library, built on Symfony DependencyInjection.
 *
 * It is created on first use, and the event loop runs automatically when the script ends.
 */
final class Application
{
    private static ?ContainerInterface $container = null;

    /**
     * Whether the shutdown and error handlers are registered.
     */
    private static bool $registered = false;

    /**
     * Returns the container, booting it with a real HTTP client on first use.
     */
    public static function container(): ContainerInterface
    {
        return self::$container ?? self::boot(HttpClient::create());
    }

    /**
     * Builds a fresh container that sends requests through the given HTTP client.
     *
     * Any previous container, together with its event loop, is discarded.
     */
    public static function boot(HttpClientInterface $http): ContainerInterface
    {
        $container = self::build($http);
        self::$container = $container;

        self::guard();

        return $container;
    }

    /**
     * Renders uncaught errors as Elephant error blocks and runs the event loop when the script ends.
     *
     * Safe to call many times; the handlers are registered only once.
     */
    public static function guard(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        register_shutdown_function(static function (): void {
            try {
                self::loop()->run();
            } catch (Throwable $error) {
                self::fail($error);
            }
        });

        set_exception_handler(static function (Throwable $error): void {
            self::fail($error);
        });
    }

    /**
     * Discards the container so the next access builds a fresh one.
     */
    public static function reset(): void
    {
        self::$container = null;
    }

    /**
     * Returns the event loop.
     *
     * @throws ServiceNotFoundException When the container holds an unexpected service.
     */
    public static function loop(): EventLoop
    {
        $loop = self::container()->get(EventLoop::class);

        if ($loop instanceof EventLoop) {
            return $loop;
        }

        throw new ServiceNotFoundException(EventLoop::class);
    }

    /**
     * Returns the console writer.
     *
     * @throws ServiceNotFoundException When the container holds an unexpected service.
     */
    public static function writer(): Writer
    {
        $writer = self::container()->get(Writer::class);

        if ($writer instanceof Writer) {
            return $writer;
        }

        throw new ServiceNotFoundException(Writer::class);
    }

    /**
     * Returns the non-blocking HTTP client.
     *
     * @throws ServiceNotFoundException When the container holds an unexpected service.
     */
    public static function http(): Client
    {
        $http = self::container()->get(Client::class);

        if ($http instanceof Client) {
            return $http;
        }

        throw new ServiceNotFoundException(Client::class);
    }

    /**
     * Returns the task combinator.
     *
     * @throws ServiceNotFoundException When the container holds an unexpected service.
     */
    public static function tasks(): Combinator
    {
        $tasks = self::container()->get(Combinator::class);

        if ($tasks instanceof Combinator) {
            return $tasks;
        }

        throw new ServiceNotFoundException(Combinator::class);
    }

    /**
     * Returns the renderer for uncaught errors.
     *
     * @throws ServiceNotFoundException When the container holds an unexpected service.
     */
    public static function renderer(): ErrorRenderer
    {
        $renderer = self::container()->get(ErrorRenderer::class);

        if ($renderer instanceof ErrorRenderer) {
            return $renderer;
        }

        throw new ServiceNotFoundException(ErrorRenderer::class);
    }

    /**
     * Shows an uncaught error as a console error block and ends the script with a failure exit code.
     */
    private static function fail(Throwable $error): never
    {
        self::renderer()->render($error);

        exit(1);
    }

    /**
     * Registers every service and compiles the container.
     */
    private static function build(HttpClientInterface $http): ContainerInterface
    {
        $container = new ContainerBuilder();

        $container->register(ClockInterface::class, MonotonicClock::class);
        $container->register(OutputFormatter::class, OutputFormatter::class);

        $container->register(HttpClientInterface::class)
            ->setSynthetic(true);

        $container->register(Client::class, Client::class)
            ->setArguments([new Reference(HttpClientInterface::class), new Reference(EventLoop::class), new Reference(ClockInterface::class)])
            ->setPublic(true);

        $container->register(OutputInterface::class, ConsoleOutput::class)
            ->setArguments([OutputInterface::VERBOSITY_NORMAL, null, new Reference(OutputFormatter::class)]);

        $container->register(EventLoop::class, EventLoop::class)
            ->addArgument(new Reference(ClockInterface::class))
            ->addMethodCall('attach', [new Reference(Client::class)])
            ->setPublic(true);

        $container->register('console.error_output', OutputInterface::class)
            ->setFactory([new Reference(OutputInterface::class), 'getErrorOutput']);

        $container->register(ErrorRenderer::class, ErrorRenderer::class)
            ->addArgument(new Reference('console.error_output'))
            ->setPublic(true);

        $container->register(Writer::class, Writer::class)
            ->setArguments([new Reference(OutputInterface::class), new Reference(OutputFormatter::class)])
            ->setPublic(true);

        $container->register(Combinator::class, Combinator::class)
            ->addArgument(new Reference(EventLoop::class))
            ->setPublic(true);

        $container->compile();
        $container->set(HttpClientInterface::class, $http);

        return $container;
    }
}

<?php
declare(strict_types=1);

namespace Elephant\Channel;

use Countable;
use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Future\CancelledException;
use Elephant\Loop\EventLoop;
use Generator;
use IteratorAggregate;
use SplQueue;

use function Elephant\Flow\await;

/**
 * Passes values from tasks that send them to tasks that receive them, in the order they were sent.
 *
 * A channel holds up to its capacity of values. Sending on a full channel waits until a receiver makes room,
 * and receiving from an empty channel waits until a value arrives; only the waiting task stops, others keep running.
 * An unbuffered channel (capacity 0) hands every value straight from a sender to a receiver.
 * Once closed, it accepts no more values, but the values it holds can still be received.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Channel implements IteratorAggregate, Countable
{
    private const CLOSED = 'Channel is closed.';

    private readonly EventLoop $loop;

    /**
     * Values sent but not received yet.
     *
     * @var SplQueue<T>
     */
    private readonly SplQueue $buffer;

    /**
     * Receivers waiting for a value, oldest first; they only wait while the buffer is empty.
     *
     * @var list<Waiter<T, null>>
     */
    private array $receivers = [];

    /**
     * Senders waiting for room, oldest first; they only wait while the buffer is full.
     *
     * @var list<Waiter<null, T>>
     */
    private array $senders = [];

    private bool $closed = false;

    /**
     * @param int $capacity How many values the channel holds before senders wait; 0 makes every send wait for a receiver.
     *
     * @throws ElephantException When the capacity is negative.
     */
    public function __construct(
        private readonly int $capacity = 0,
    ) {
        if ($capacity < 0) {
            throw new ElephantException('The capacity of a channel cannot be negative.');
        }

        $this->loop = Application::loop();
        $this->buffer = new SplQueue();
    }

    /**
     * Sends a value: hands it to a waiting receiver, buffers it when there is room, or waits until a receiver takes it.
     *
     * @param T $value
     *
     * @throws ChannelClosedException When the channel is closed, or closes while the send waits.
     * @throws CancelledException When the waiting task is cancelled; the value is then not sent.
     */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new ChannelClosedException(self::CLOSED);
        }

        $receiver = array_shift($this->receivers);

        if ($receiver !== null) {
            $receiver->complete($value);

            return;
        }

        if (count($this->buffer) < $this->capacity) {
            $this->buffer->enqueue($value);

            return;
        }

        $sender = new Waiter($this->loop, function (Waiter $sender): void {
            $this->withdrawSender($sender);
        }, $value);

        $this->senders[] = $sender;

        await($sender);
    }

    /**
     * Receives the oldest value, waiting until one is sent when the channel is empty.
     *
     * @return T
     *
     * @throws ChannelClosedException When the channel is closed and empty, or closes while the receive waits.
     * @throws CancelledException When the waiting task is cancelled; the value it would have received goes to the next receiver.
     */
    public function receive(): mixed
    {
        if (count($this->buffer) > 0) {
            $value = $this->buffer->dequeue();
            $this->admit();

            return $value;
        }

        $sender = array_shift($this->senders);

        if ($sender !== null) {
            $sender->complete(null);

            return $sender->payload();
        }

        if ($this->closed) {
            throw new ChannelClosedException(self::CLOSED);
        }

        $receiver = new Waiter($this->loop, function (Waiter $receiver): void {
            $this->withdrawReceiver($receiver);
        });

        $this->receivers[] = $receiver;

        return await($receiver);
    }

    /**
     * Closes the channel: waiting senders and receivers fail, buffered values can still be received.
     *
     * Closing a closed channel does nothing.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $waiters = [...$this->receivers, ...$this->senders];
        $this->receivers = [];
        $this->senders = [];

        foreach ($waiters as $waiter) {
            $waiter->fail(new ChannelClosedException(self::CLOSED));
        }
    }

    /**
     * Whether the channel has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Returns how many values are buffered and waiting for a receiver.
     */
    public function count(): int
    {
        return count($this->buffer);
    }

    /**
     * Receives values until the channel is closed and drained.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        while (true) {
            try {
                $value = $this->receive();
            } catch (ChannelClosedException) {
                return;
            }

            yield $value;
        }
    }

    /**
     * Moves the value of the oldest waiting sender into the buffer when there is room, and lets that sender go on.
     */
    private function admit(): void
    {
        if (count($this->buffer) >= $this->capacity) {
            return;
        }

        $sender = array_shift($this->senders);

        if ($sender === null) {
            return;
        }

        $this->buffer->enqueue($sender->payload());
        $sender->complete(null);
    }

    /**
     * Forgets a sender whose task was cancelled while it waited.
     *
     * @param Waiter<null, T> $sender
     */
    private function withdrawSender(Waiter $sender): void
    {
        $this->senders = array_values(array_filter(
            $this->senders,
            static fn (Waiter $waiting): bool => $waiting !== $sender,
        ));
    }

    /**
     * Forgets a receiver whose task was cancelled while it waited, or passes on the value it was given but never read.
     *
     * @param Waiter<T, null> $receiver
     */
    private function withdrawReceiver(Waiter $receiver): void
    {
        $future = $receiver->future();

        if ($future->isPending()) {
            $this->receivers = array_values(array_filter(
                $this->receivers,
                static fn (Waiter $waiting): bool => $waiting !== $receiver,
            ));

            return;
        }

        if ($future->isCompleted()) {
            $this->restore($future->result());
        }
    }

    /**
     * Gives a value back to the front of the line: to the oldest waiting receiver, or else to the head of the buffer.
     *
     * @param T $value
     */
    private function restore(mixed $value): void
    {
        $receiver = array_shift($this->receivers);

        if ($receiver !== null) {
            $receiver->complete($value);

            return;
        }

        $this->buffer->unshift($value);
    }
}

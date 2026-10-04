<?php
declare(strict_types=1);

use Elephant\Channel\Channel;
use Elephant\Channel\ChannelClosedException;
use Elephant\ElephantException;
use Elephant\Future\CancelledException;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};

it('receives buffered values in the order they were sent', function (): void {
    $channel = new Channel(capacity: 3);

    $channel->send('a');
    $channel->send('b');
    $channel->send('c');

    expect($channel)->toHaveCount(3)
        ->and($channel->receive())->toBe('a')
        ->and($channel->receive())->toBe('b')
        ->and($channel->receive())->toBe('c')
        ->and($channel)->toHaveCount(0);
});

it('makes an unbuffered sender wait until the value is received', function (): void {
    $channel = new Channel();
    $journal = new Journal();

    $sender = async(static function () use ($channel, $journal): void {
        $journal->write('sending');
        $channel->send('value');
        $journal->write('sent');
    });

    await(delay(0.01));
    $journal->write('receiving');
    $value = $channel->receive();
    $sender->await();

    expect($value)->toBe('value')
        ->and($journal->entries())->toBe(['sending', 'receiving', 'sent']);
});

it('waits in receive until a value is sent later', function (): void {
    $channel = new Channel();

    async(static function () use ($channel): void {
        await(delay(0.01));
        $channel->send('late');
    });

    expect($channel->receive())->toBe('late');
});

it('keeps other tasks running while a receiver waits', function (): void {
    $channel = new Channel();
    $journal = new Journal();

    $receiver = async(static fn (): mixed => $channel->receive());

    async(static function () use ($channel, $journal): void {
        $journal->write('working');
        await(delay(0.01));
        $channel->send('done');
    });

    expect($receiver->await())->toBe('done')
        ->and($journal->entries())->toBe(['working']);
});

it('iterates until the channel is closed and drained', function (): void {
    $channel = new Channel(capacity: 2);

    async(static function () use ($channel): void {
        foreach (['a', 'b', 'c'] as $item) {
            $channel->send($item);
        }

        $channel->close();
    });

    $items = [];

    foreach ($channel as $item) {
        $items[] = $item;
    }

    expect($items)->toBe(['a', 'b', 'c'])
        ->and($channel->isClosed())->toBeTrue();
});

it('throws when receiving from a closed and empty channel', function (): void {
    $channel = new Channel();
    $channel->close();

    expect(static function () use ($channel): void {
        $channel->receive();
    })->toThrow(ChannelClosedException::class, 'Channel is closed.');
});

it('still delivers buffered values after closing', function (): void {
    $channel = new Channel(capacity: 2);
    $channel->send('a');
    $channel->send('b');
    $channel->close();

    expect($channel->receive())->toBe('a')
        ->and($channel->receive())->toBe('b')
        ->and(static function () use ($channel): void {
            $channel->receive();
        })->toThrow(ChannelClosedException::class);
});

it('throws when sending on a closed channel', function (): void {
    $channel = new Channel(capacity: 1);
    $channel->close();

    expect(static function () use ($channel): void {
        $channel->send('value');
    })->toThrow(ChannelClosedException::class, 'Channel is closed.')
        ->and($channel)->toHaveCount(0);
});

it('can be closed more than once', function (): void {
    $channel = new Channel();

    $channel->close();
    $channel->close();

    expect($channel->isClosed())->toBeTrue();
});

it('fails waiting receivers when it closes', function (): void {
    $channel = new Channel();

    $first = async(static fn (): mixed => $channel->receive());
    $second = async(static fn (): mixed => $channel->receive());

    await(delay(0.01));
    $channel->close();

    expect(static function () use ($first): void {
        $first->await();
    })->toThrow(ChannelClosedException::class)
        ->and(static function () use ($second): void {
            $second->await();
        })->toThrow(ChannelClosedException::class);
});

it('fails waiting senders when it closes', function (): void {
    $channel = new Channel();

    $sender = async(static function () use ($channel): void {
        $channel->send('value');
    });

    await(delay(0.01));
    $channel->close();

    expect(static function () use ($sender): void {
        $sender->await();
    })->toThrow(ChannelClosedException::class)
        ->and(static function () use ($channel): void {
            $channel->receive();
        })->toThrow(ChannelClosedException::class);
});

it('makes the sender wait while the buffer is full', function (): void {
    $channel = new Channel(capacity: 2);
    $journal = new Journal();

    $sender = async(static function () use ($channel, $journal): void {
        foreach ([1, 2, 3] as $number) {
            $channel->send($number);
            $journal->write('sent');
        }
    });

    await(delay(0.01));

    expect($journal->entries())->toBe(['sent', 'sent'])
        ->and($channel)->toHaveCount(2);

    $first = $channel->receive();
    $journal->write('received');
    $sender->await();

    expect($first)->toBe(1)
        ->and($journal->entries())->toBe(['sent', 'sent', 'received', 'sent'])
        ->and($channel)->toHaveCount(2)
        ->and($channel->receive())->toBe(2)
        ->and($channel->receive())->toBe(3);
});

it('gives the next value to the next receiver when a waiting receiver is cancelled', function (): void {
    $channel = new Channel();

    $first = async(static fn (): mixed => $channel->receive());
    $second = async(static fn (): mixed => $channel->receive());

    await(delay(0.01));
    $first->cancel();
    $channel->send('value');

    expect($second->await())->toBe('value')
        ->and(static function () use ($first): void {
            $first->await();
        })->toThrow(CancelledException::class);
});

it('passes on a value handed to a receiver cancelled before it could read it', function (): void {
    $channel = new Channel();

    $first = async(static fn (): mixed => $channel->receive());
    $second = async(static fn (): mixed => $channel->receive());

    await(delay(0.01));
    $channel->send('value');
    $first->cancel();

    expect($second->await())->toBe('value')
        ->and(static function () use ($first): void {
            $first->await();
        })->toThrow(CancelledException::class);
});

it('keeps a value handed to a cancelled receiver in the channel when nobody else waits', function (): void {
    $channel = new Channel();

    $receiver = async(static fn (): mixed => $channel->receive());

    await(delay(0.01));
    $channel->send('value');
    $receiver->cancel();
    await(delay(0.01));

    expect($channel)->toHaveCount(1)
        ->and($channel->receive())->toBe('value');
});

it('drops the value of a waiting sender that is cancelled', function (): void {
    $channel = new Channel();

    $cancelled = async(static function () use ($channel): void {
        $channel->send('dropped');
    });

    await(delay(0.01));
    $cancelled->cancel();

    async(static function () use ($channel): void {
        $channel->send('kept');
    });

    expect($channel->receive())->toBe('kept')
        ->and(static function () use ($cancelled): void {
            $cancelled->await();
        })->toThrow(CancelledException::class);
});

it('rejects a negative capacity', function (): void {
    expect(static function (): void {
        new Channel(capacity: -1);
    })->toThrow(ElephantException::class);
});

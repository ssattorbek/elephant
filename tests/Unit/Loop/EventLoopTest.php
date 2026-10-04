<?php
declare(strict_types=1);

use Elephant\ElephantException;
use Elephant\Loop\EventLoop;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Journal;

beforeEach(function (): void {
    $this->clock = new MockClock();
    $this->loop = new EventLoop($this->clock);
    $this->journal = new Journal();
});

it('runs deferred callbacks in the order they were scheduled', function (): void {
    $this->loop->defer($this->journal->record('first'));
    $this->loop->defer($this->journal->record('second'));

    $this->loop->run();

    expect($this->journal->entries())->toBe(['first', 'second']);
});

it('fires timers in chronological order', function (): void {
    $this->loop->delay(2, $this->journal->record('late'));
    $this->loop->delay(1, $this->journal->record('early'));

    $this->loop->run();

    expect($this->journal->entries())->toBe(['early', 'late']);
});

it('sleeps on the clock until the next timer is due', function (): void {
    $start = (float) $this->clock->now()->format('U.u');

    $this->loop->delay(5, $this->journal->record('done'));
    $this->loop->run();

    expect((float) $this->clock->now()->format('U.u') - $start)->toEqualWithDelta(5.0, 0.001);
});

it('never fires a cancelled timer', function (): void {
    $timer = $this->loop->delay(1, $this->journal->record('cancelled'));
    $this->loop->delay(2, $this->journal->record('kept'));

    $this->loop->cancel($timer);
    $this->loop->run();

    expect($this->journal->entries())->toBe(['kept']);
});

it('refuses to wait for work that can never happen', function (): void {
    expect(function (): void {
        $this->loop->runUntil(static fn (): bool => false);
    })
        ->toThrow(ElephantException::class);
});

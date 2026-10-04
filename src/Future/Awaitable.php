<?php
declare(strict_types=1);

namespace Elephant\Future;

/**
 * Something that eventually produces a value and can be awaited.
 *
 * @template-covariant T
 */
interface Awaitable
{
    /**
     * Returns the future that settles with the awaited value.
     *
     * @return Future<T>
     */
    public function future(): Future;
}

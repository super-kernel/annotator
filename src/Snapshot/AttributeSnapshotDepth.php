<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Snapshot;

use UnexpectedValueException;

/**
 * Limits serialized Attribute object graph traversal to 64 nested levels.
 *
 * @internal
 */
final class AttributeSnapshotDepth
{
    private const int MAX_OBJECT_GRAPH_DEPTH = 64;

    /**
     * @throws UnexpectedValueException When the depth exceeds the supported maximum
     */
    public static function assertWithinLimit(int $depth): void
    {
        if (self::MAX_OBJECT_GRAPH_DEPTH < $depth) {
            throw new UnexpectedValueException('Serialized Attribute object graph exceeds the supported depth.');
        }
    }
}

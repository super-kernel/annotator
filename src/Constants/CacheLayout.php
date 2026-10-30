<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Constants;

/**
 * Names the annotation cache entries below the vendor `.cache` directory.
 */
enum CacheLayout: string
{
    /** Package-name-to-reference index, published after every package record. */
    case IndexPath = 'packages.ser';

    /** Directory holding one serialized record per package at `<vendor>/<package>.ser`. */
    case PackageDirectory = 'annotations';
}

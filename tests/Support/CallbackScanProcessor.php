<?php

declare(strict_types=1);

namespace SuperKernel\Annotator\Tests\Support;

use Closure;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;

final class CallbackScanProcessor implements ScanProcessorInterface
{
    /** @var list<Closure(): void> */
    private array $callbacks = [];

    private int $supportCalls = 0;

    public function __construct(private readonly bool $supported = true)
    {
    }

    public function support(): bool
    {
        ++$this->supportCalls;

        return $this->supported;
    }

    public function execute(callable $callback): void
    {
        $this->callbacks[] = $callback(...);
        $callback();
    }

    /**
     * @return list<Closure(): void>
     */
    public function callbacks(): array
    {
        return $this->callbacks;
    }

    public function supportCalls(): int
    {
        return $this->supportCalls;
    }
}

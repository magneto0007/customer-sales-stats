<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Cron;

use Magneto\CustomerSalesStats\Service\QueueProcessor;

class ProcessQueue
{
    public function __construct(
        private readonly QueueProcessor $processor
    ) {}

    public function execute(): void
    {
        $this->processor->processAll();
    }
}

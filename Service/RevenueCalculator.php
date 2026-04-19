<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

class RevenueCalculator
{
    public function toGlobalCurrency(float $baseGrandTotal, float $baseToGlobalRate): float
    {
        $rate = $baseToGlobalRate > 0.0 ? $baseToGlobalRate : 1.0;

        return round($baseGrandTotal * $rate, 4);
    }
}

<?php

namespace App\Traits;

use App\Models\Configuration;

trait CalculatesTax
{
    public function calculateTaxedPrice(float $subtotal = null, string $paymentMethod = 'razerpay'): array
    {
        $subtotal = $subtotal ?? $this->total_amount ?? 0;
        $taxPercent = Configuration::getValue($paymentMethod, 'tax', 0);
        $taxAmount = $subtotal * ($taxPercent / 100);
        $total = $subtotal + $taxAmount;

        return [
            'subtotal' => $subtotal,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'total_amount' => $total,
        ];
    }
}

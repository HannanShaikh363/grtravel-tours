<?php

namespace App\Rules;

use App\Models\Rate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxSeatingCapacity implements ValidationRule
{
    protected $rateId;

    /**
     * Constructor to pass the rate ID.
     */
    public function __construct($rateId)
    {
        $this->rateId = $rateId;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if rate ID exists and fetch the rate's vehicle seating capacity
        $rate = Rate::find($this->rateId);

        if (!$rate) {
            $fail('The selected rate is invalid.');
            return;
        }
        $attribute = 'Passengers';

        if ($value > $rate->vehicle_seating_capacity) {
            $fail("The {$attribute} exceeds the maximum seating capacity of {$rate->vehicle_seating_capacity}.");
        }
    }
}

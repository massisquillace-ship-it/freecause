<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxBytes implements ValidationRule
{
    public function __construct(private int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen((string) $value) > $this->max) {
            $fail("The {$attribute} field must not be longer than {$this->max} bytes.");
        }
    }
}

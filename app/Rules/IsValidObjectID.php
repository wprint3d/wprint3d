<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

use MongoDB\BSON\ObjectId;

use Closure;
use Throwable;

class IsValidObjectID implements ValidationRule
{

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try { new ObjectId($value); }
        catch (Throwable $throwable) {
            $fail("Unsupported value provided for \"{$attribute}\": a valid MongoDB object ID was expected.");
        }
    }

}

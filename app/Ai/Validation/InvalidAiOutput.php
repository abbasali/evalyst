<?php

namespace App\Ai\Validation;

use RuntimeException;

/**
 * The model returned output that breaks the contract (e.g. a score above the maximum).
 * Never retried: the item fails closed.
 */
class InvalidAiOutput extends RuntimeException {}

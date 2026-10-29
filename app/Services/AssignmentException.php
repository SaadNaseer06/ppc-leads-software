<?php

namespace App\Services;

/** A business-rule refusal (wrong brand, inactive user...) shown to the user as a 422 */
class AssignmentException extends \RuntimeException {}

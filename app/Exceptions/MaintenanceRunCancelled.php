<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown from the output of a maintenance run cancelled from the dashboard,
 * to stop its command at the next thing it prints.
 */
class MaintenanceRunCancelled extends Exception {}

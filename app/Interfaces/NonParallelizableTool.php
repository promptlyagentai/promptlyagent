<?php

namespace App\Interfaces;

/**
 * Marks a tool as incompatible with the parallel execution wrapper
 * due to complex schema serialization issues.
 *
 * Tools implementing this interface will bypass the parallel execution
 * enhancement to prevent serialization problems with OpenAI API requests.
 */
interface NonParallelizableTool
{
    // This is a marker interface and requires no methods.
}

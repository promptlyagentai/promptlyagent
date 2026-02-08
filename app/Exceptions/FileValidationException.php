<?php

namespace App\Exceptions;

/**
 * Exception thrown when file validation fails during upload.
 *
 * Wraps validation errors from SecureFileValidator to provide
 * consistent error handling across all file upload endpoints.
 */
class FileValidationException extends \Exception {}

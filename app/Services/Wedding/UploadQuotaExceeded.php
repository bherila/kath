<?php

namespace App\Services\Wedding;

use RuntimeException;

/**
 * Today's wedding upload quota (global or per IP) can't fit this file.
 */
class UploadQuotaExceeded extends RuntimeException {}

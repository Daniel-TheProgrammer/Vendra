<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use RuntimeException;

/**
 * The file as a whole cannot be imported (no header, unknown columns, too
 * many rows). Problems with individual rows are reported per product
 * instead, and never stop the rest of the import.
 */
final class InvalidProductCsv extends RuntimeException {}

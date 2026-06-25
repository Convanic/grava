<?php
declare(strict_types=1);

namespace App\Game;

use RuntimeException;

/** Route existiert, wurde aber noch nicht ins Spiel aufgenommen (keine Pässe). */
final class RideSummaryNotIngestedException extends RuntimeException {}

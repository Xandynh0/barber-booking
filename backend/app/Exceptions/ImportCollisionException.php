<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ImportTestData when a CSV record would either reuse a manifest
 * entry that no longer matches the database, or collide by name with a
 * preexisting record the importer did not create.
 */
class ImportCollisionException extends RuntimeException {}

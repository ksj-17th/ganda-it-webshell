<?php

/**
 * IDE-only declaration for the function supplied by /var/www/src/db.php.
 * PDO is inferred from the call sites; keep this signature in sync with db.php.
 * Do not require, autoload, or deploy this file.
 */
function db(): \PDO
{
    throw new \LogicException('IDE stub only; load the real db.php at runtime.');
}

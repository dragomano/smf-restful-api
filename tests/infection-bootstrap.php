<?php

declare(strict_types=1);

// Bootstrap for Infection's own process (loaded via -d auto_prepend_file).
//
// Infection's mutators reflect over source classes with `new ReflectionClass(...)`
// while generating mutants, which autoloads the SMF\API\* files. Each of those
// files starts with `if (! defined('SMF')) die('No direct access...')`, so
// without SMF defined the whole run dies. Defining it here keeps the guard happy.
// Child test runs (initial suite and per-mutant) get SMF from tests/Environment.php
// instead, so this only covers Infection's generation process.
if (! defined('SMF')) {
    define('SMF', true);
}

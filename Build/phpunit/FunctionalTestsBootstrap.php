<?php

declare(strict_types=1);

/*
 * Functional test bootstrap. Loads Tests/.env (if present) so that
 * S3/MinIO credentials can be supplied locally without committing them.
 */

use Symfony\Component\Dotenv\Dotenv;

$baseDir = dirname(__DIR__, 2);
$autoload = $baseDir . '/.Build/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Run `composer install` before executing functional tests.\n");
    exit(1);
}
require $autoload;

$envFile = $baseDir . '/Tests/.env';
if (file_exists($envFile) && class_exists(Dotenv::class)) {
    (new Dotenv())->usePutenv()->loadEnv($envFile);
}

require $baseDir . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';
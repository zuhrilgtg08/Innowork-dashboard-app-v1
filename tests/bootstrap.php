<?php

/**
 * PHPUnit bootstrap.
 *
 * Why this exists instead of pointing straight at vendor/autoload.php:
 *
 * phpunit.xml declares the test environment (`APP_ENV=testing`,
 * `DB_CONNECTION=sqlite`, `CACHE_STORE=array`, ...). PHPUnit applies those via
 * putenv() and $_ENV — even with force="true" it never writes $_SERVER.
 * Laravel's Env repository, however, reads $_SERVER *first*
 * (ServerConstAdapter precedes EnvConstAdapter).
 *
 * So on any machine that exports these as real environment variables — a
 * docker-compose service, a CI image, this container — the outer values land in
 * $_SERVER and silently beat phpunit.xml. The visible symptom is the whole
 * suite trying to reach the production Postgres host and failing to resolve it.
 *
 * Mirroring $_ENV over $_SERVER makes phpunit.xml authoritative again, so the
 * suite is hermetic wherever it runs.
 */

require __DIR__.'/../vendor/autoload.php';

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

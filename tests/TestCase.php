<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must not depend on built frontend assets: without this, any page
        // that renders @vite(...) throws ViteManifestNotFoundException (500)
        // unless the Vite dev server or a production build happens to be present.
        $this->withoutVite();
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Transaction rollback does not remove uploaded objects. Keep every test's
        // private files separate from the localhost/demo document store.
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['filesystems.disks.local.root'=>\Illuminate\Support\Facades\Storage::disk('local')->path('')]);
    }
}

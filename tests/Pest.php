<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->group('browser')->in('Browser');

pest()->browser()->timeout(15000);

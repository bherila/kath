<?php

namespace Tests;

/**
 * Base TestCase for all feature tests.
 *
 * Extends SafeTestCase which enforces SQLite in-memory database usage,
 * ensuring tests never accidentally connect to MySQL (even if .env has credentials).
 *
 * Usage:
 *   - Feature tests should extend this class
 *   - Use RefreshDatabase trait for tests that need a clean database
 *   - Use DatabaseTransactions trait for tests that should rollback changes
 *
 * Example:
 *   class MyFeatureTest extends TestCase
 *   {
 *       use RefreshDatabase;
 *
 *       public function test_something(): void
 *       {
 *           // Database is guaranteed to be SQLite in-memory
 *           $user = User::factory()->create();
 *           // ...
 *       }
 *   }
 */
abstract class TestCase extends SafeTestCase
{
    //
}

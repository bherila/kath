<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basic route + database-safety smoke tests for the site.
 *
 * This test uses RefreshDatabase, which will run migrations on each test.
 * Because we enforce SQLite in-memory via SafeTestCase, this is safe
 * and will never accidentally affect a MySQL database. There are currently
 * no migrations (no domain tables yet), so this is a no-op safety net for
 * when the future blog/CMS phase adds tables.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the home page returns a successful response.
     */
    public function test_home_page_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test that the blog placeholder page returns a successful response.
     */
    public function test_blog_page_returns_a_successful_response(): void
    {
        $response = $this->get('/blog');

        $response->assertStatus(200);
    }

    /**
     * Test that the contact placeholder page returns a successful response.
     */
    public function test_contact_page_returns_a_successful_response(): void
    {
        $response = $this->get('/contact');

        $response->assertStatus(200);
    }

    /**
     * Test that we are using SQLite in-memory database.
     *
     * This test verifies our safety mechanism is working.
     */
    public function test_database_is_sqlite_in_memory(): void
    {
        $this->assertEquals('sqlite', $this->getDatabaseDriver());
        $this->assertEquals(':memory:', $this->getDatabaseName());
    }
}

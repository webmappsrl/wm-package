<?php

namespace Wm\WmPackage\Tests\Feature;

use Tests\TestCase;
use Wm\WmPackage\Commands\Concerns\InteractsWithWmPackageMigrationStubs;

class InteractsWithWmPackageMigrationStubsTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use InteractsWithWmPackageMigrationStubs;
        };
    }

    public function test_stub_base_names_returns_at_least_one_real_stub(): void
    {
        $names = $this->subject->stubBaseNames();

        $this->assertNotEmpty($names);
        $this->assertContains('zz_2026_06_26_000001_add_editor_role', $names);
    }

    public function test_find_published_filename_for_stub_matches_by_suffix(): void
    {
        $found = $this->subject->findPublishedFilenameForStub('zz_2026_06_26_000001_add_editor_role');

        $this->assertNotNull($found);
        $this->assertStringEndsWith('zz_2026_06_26_000001_add_editor_role', $found);
    }

    public function test_find_published_filename_for_stub_returns_null_when_not_published(): void
    {
        $found = $this->subject->findPublishedFilenameForStub('zz_9999_99_99_999999_never_published');

        $this->assertNull($found);
    }

    public function test_create_users_table_is_not_applied_to_database_on_maphub(): void
    {
        $this->assertFalse($this->subject->isAppliedToDatabase('create_users_table'));
        $this->assertTrue($this->subject->needsPublishing('create_users_table'));
        $this->assertContains('manca colonna users.balance', $this->subject->schemaGapsForStub('create_users_table'));
    }
}

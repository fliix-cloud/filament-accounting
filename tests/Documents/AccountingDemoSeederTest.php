<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Database\Seeders\AccountingDemoSeeder;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;

class AccountingDemoSeederTest extends TestCase
{
    public function test_demo_seed_is_repeatable(): void
    {
        $this->actingAs($this->makeUser());
        $this->seed(AccountingDemoSeeder::class);
        $this->assertSame(1, LegalEntity::query()->count());
        $this->assertSame(2, Party::query()->count());
        $this->assertSame(2, Document::query()->count());
        foreach (Document::all() as $document) {
            $this->assertSame(DocumentStatus::Draft, $document->document_status);
        }
        $ids = Document::query()->pluck('id')->all();
        $this->seed(AccountingDemoSeeder::class);
        $this->assertSame($ids, Document::query()->pluck('id')->all());
        $this->assertSame(2, Party::query()->count());
    }

    public function test_denied_permission_rolls_back_demo_company(): void
    {
        $this->actingAs($this->makeUser());
        $authorizer = \Mockery::mock(AccountingAuthorizer::class);
        $authorizer->shouldReceive('authorize')->once()->andThrow(new AuthorizationException);
        $this->app->instance(AccountingAuthorizer::class, $authorizer);
        try {
            $this->seed(AccountingDemoSeeder::class);
            $this->fail('Expected authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertSame(0, LegalEntity::query()->count());
            $this->assertSame(0, Party::query()->count());
        }
    }

    public function test_missing_actor_does_not_leave_partial_demo_data(): void
    {
        try {
            $this->seed(AccountingDemoSeeder::class);
            $this->fail('Expected a missing actor error.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('demo user', $exception->getMessage());
        }
        $this->assertSame(0, LegalEntity::query()->count());
    }
}

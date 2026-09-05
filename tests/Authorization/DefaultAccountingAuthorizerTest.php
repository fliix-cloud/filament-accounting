<?php

namespace FilamentAccounting\Tests\Authorization;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Services\DeletePurchaseInvoiceDraft;
use FilamentAccounting\Tests\Fixtures\User;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class DefaultAccountingAuthorizerTest extends TestCase
{
    protected function defineAccountingGates(): void {}

    #[Test]
    public function undefined_abilities_deny_authenticated_and_anonymous_actors(): void
    {
        $authorizer = app(AccountingAuthorizer::class);
        $this->assertFalse($authorizer->can('post_documents'));
        $this->actingAs($this->makeUser());
        $this->assertFalse($authorizer->can('post_documents'));
        $this->assertFalse($authorizer->can('unknown_ability'));

        $this->expectException(AuthorizationException::class);
        $authorizer->authorize('post_documents');
    }

    #[Test]
    public function defined_gates_receive_the_actor_and_subject_and_preserve_denial(): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        Gate::define('accounting.documents.post', fn (User $actor, $subject): bool => $actor->is($user) && $subject->is($entity));
        Gate::define('accounting.periods.reopen', fn (User $actor): bool => false);

        $authorizer = app(AccountingAuthorizer::class);
        $this->assertTrue($authorizer->can('post_documents', $entity));
        $this->assertFalse($authorizer->can('reopen_periods', $entity));
        auth()->logout();
        $this->assertFalse($authorizer->can('post_documents', $entity));
    }

    #[Test]
    public function purchase_view_permission_does_not_allow_discard_through_ui_or_service(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        Gate::define('accounting.invoices.register-purchase', fn (User $user): bool => true);
        $document = Document::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => DocumentType::PurchaseInvoice,
            'direction' => DocumentDirection::Incoming,
            'currency' => 'EUR',
        ])->fresh();

        $this->assertFalse(PurchaseInvoiceResource::canDelete($document));
        $this->assertFalse(PurchaseInvoiceResource::canDiscard($document));
        try {
            app(DeletePurchaseInvoiceDraft::class)->handle($document, 'Duplicate');
            $this->fail('Discard must require its own permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('accounting_documents', ['id' => $document->getKey(), 'document_status' => 'draft']);
            $this->assertDatabaseCount('accounting_audit_events', 0);
        }
    }
}

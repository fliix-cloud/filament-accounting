<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Services\StrongAuthenticationCoordinator;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeAction;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClient;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClientFactory;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class StrongAuthenticationSecurityTest extends TestCase
{
    #[Test]
    public function it_persists_and_resumes_a_tan_challenge_across_coordinator_instances(): void
    {
        $connection = $this->connection($this->makeEntity());
        $client = $this->bindFakeClient(new FakeFintsClient(['needsTan', 'done']));

        $outcome = app(StrongAuthenticationCoordinator::class)->execute(
            $connection,
            new FakeAction,
            ScaOperationType::Login,
            $client,
            returnUrl: '/return',
        );

        $this->assertSame(ScaSessionState::NeedsTan, $outcome->state);
        $this->assertNotNull($outcome->session?->encrypted_fints_state);
        $this->assertNotNull($outcome->session?->encrypted_action);

        $resumed = app(StrongAuthenticationCoordinator::class)->submitTan(
            $outcome->session->uuid,
            '654321',
            $connection->fresh(),
        );

        $this->assertTrue($resumed->isDone());
        $this->assertSame(1, $client->submitTanCalls);
        $this->assertSame(ScaSessionState::Done, $resumed->session?->fresh()->state);
        $this->assertNull($resumed->session?->fresh()->encrypted_fints_state);
        $this->assertNull($resumed->session?->fresh()->encrypted_action);
        $this->assertSame('fake-fints-state:0', $client->lastPersistedInstance);
        $this->assertSame(BankConnectionStatus::Active, $connection->fresh()?->status);
        $this->assertNotNull($connection->fresh()?->last_successful_connection_at);
    }

    #[Test]
    public function a_submitted_tan_is_never_persisted(): void
    {
        $connection = $this->connection($this->makeEntity());
        $client = $this->bindFakeClient(new FakeFintsClient(['needsTan', 'done']));
        $coordinator = app(StrongAuthenticationCoordinator::class);
        $outcome = $coordinator->execute($connection, new FakeAction, ScaOperationType::Login, $client);

        $tan = '998877';
        $coordinator->submitTan($outcome->session->uuid, $tan, $connection->fresh());

        foreach (get_object_vars($client) as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($tan, $value);
            }
        }

        $row = DB::table('fints_sca_sessions')->where('uuid', $outcome->session->uuid)->first();
        foreach ((array) $row as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($tan, $value);
            }
        }
    }

    #[Test]
    public function a_successful_fints_operation_marks_the_connection_active(): void
    {
        $connection = $this->connection($this->makeEntity());
        $client = $this->bindFakeClient(new FakeFintsClient(['done']));

        $outcome = app(StrongAuthenticationCoordinator::class)->execute(
            $connection,
            new FakeAction,
            ScaOperationType::Login,
            $client,
        );

        $connection->refresh();

        $this->assertTrue($outcome->isDone());
        $this->assertSame(BankConnectionStatus::Active, $connection->status);
        $this->assertNotNull($connection->last_successful_connection_at);
    }

    #[Test]
    public function decoupled_polling_respects_the_configured_minimum_delay(): void
    {
        config()->set('filament-accounting.banking.fints.security.min_poll_seconds', 4);
        $connection = $this->connection($this->makeEntity());
        $client = $this->bindFakeClient(new FakeFintsClient(['needsDecoupled', 'needsDecoupled']));
        $coordinator = app(StrongAuthenticationCoordinator::class);
        $outcome = $coordinator->execute($connection, new FakeAction, ScaOperationType::Login, $client);

        $this->assertSame(ScaSessionState::NeedsDecoupled, $outcome->state);
        $this->assertSame(4, $outcome->session?->poll_interval_seconds);
        $this->assertTrue($outcome->session?->next_poll_at?->isFuture() === true);

        try {
            $coordinator->checkDecoupled($outcome->session->uuid, $connection->fresh());
            $this->fail('Polling before the bank delay should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Polling is not yet permitted', $exception->getMessage());
        }

        $this->assertSame(0, $client->checkDecoupledCalls);
        $this->travel(5)->seconds();

        $resumed = $coordinator->checkDecoupled($outcome->session->uuid, $connection->fresh());
        $this->assertSame(ScaSessionState::NeedsDecoupled, $resumed->state);
        $this->assertSame(1, $client->checkDecoupledCalls);
    }

    #[Test]
    public function an_expired_session_is_terminal_and_loses_its_sensitive_state(): void
    {
        $connection = $this->connection($this->makeEntity());
        $client = $this->bindFakeClient(new FakeFintsClient(['needsTan', 'done']));
        $coordinator = app(StrongAuthenticationCoordinator::class);
        $outcome = $coordinator->execute($connection, new FakeAction, ScaOperationType::Login, $client);
        $session = StrongAuthenticationSession::query()->where('uuid', $outcome->session->uuid)->sole();
        $session->expires_at = now()->subMinute();
        $session->save();

        try {
            $coordinator->submitTan($session->uuid, '123456', $connection->fresh());
            $this->fail('An expired SCA session must not resume.');
        } catch (ScaExpiredException) {
            $fresh = $session->fresh();
            $this->assertSame(ScaSessionState::Expired, $fresh?->state);
            $this->assertNull($fresh?->encrypted_fints_state);
            $this->assertNull($fresh?->encrypted_action);
        }
    }

    private function bindFakeClient(FakeFintsClient $client): FakeFintsClient
    {
        $this->app->instance(FintsClientFactory::class, new FakeFintsClientFactory($client));

        return $client;
    }

    private function connection(LegalEntity $entity): BankConnection
    {
        return BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Testbank',
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
        ]);
    }
}

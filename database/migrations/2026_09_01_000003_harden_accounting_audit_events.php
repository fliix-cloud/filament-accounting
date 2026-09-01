<?php

use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounting_audit_events', 'sequence')) {
            return;
        }

        Schema::table('accounting_audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence')->nullable()->after('legal_entity_id');
            $table->unsignedSmallInteger('event_schema_version')->nullable()->after('sequence');
            $table->unsignedSmallInteger('canonicalization_version')->nullable()->after('event_schema_version');
            $table->string('hash_algorithm', 32)->nullable()->after('canonicalization_version');
            $table->string('impersonator_type', 191)->nullable()->after('actor_id');
            $table->string('impersonator_id', 64)->nullable()->after('impersonator_type');
            $table->longText('canonical_payload')->nullable()->after('payload');
            $table->char('previous_hash', 64)->nullable()->after('canonical_payload');
            $table->char('event_hash', 64)->nullable()->after('previous_hash');
            $table->string('causation_id', 64)->nullable()->after('correlation_id');
            $table->string('application_version', 64)->nullable()->after('request_id');
            $table->string('application_commit', 64)->nullable()->after('application_version');
            $table->string('configuration_snapshot_id', 64)->nullable()->after('application_commit');
            $table->timestamp('technical_at')->nullable()->after('occurred_at');
        });

        $this->createChainHeadsTable();
        $this->backfillChains();

        Schema::table('accounting_audit_events', function (Blueprint $table) {
            $table->unique(['legal_entity_id', 'sequence'], 'acct_audit_entity_seq_uidx');
            $table->unique('event_hash', 'acct_audit_event_hash_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_audit_chain_heads');

        if (! Schema::hasColumn('accounting_audit_events', 'sequence')) {
            return;
        }

        Schema::table('accounting_audit_events', function (Blueprint $table) {
            $table->dropUnique('acct_audit_entity_seq_uidx');
            $table->dropUnique('acct_audit_event_hash_uidx');
            $table->dropColumn([
                'sequence',
                'event_schema_version',
                'canonicalization_version',
                'hash_algorithm',
                'impersonator_type',
                'impersonator_id',
                'canonical_payload',
                'previous_hash',
                'event_hash',
                'causation_id',
                'application_version',
                'application_commit',
                'configuration_snapshot_id',
                'technical_at',
            ]);
        });
    }

    private function createChainHeadsTable(): void
    {
        if (Schema::hasTable('accounting_audit_chain_heads')) {
            return;
        }

        Schema::create('accounting_audit_chain_heads', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->primary()->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->unsignedBigInteger('last_sequence');
            $table->char('last_event_hash', 64);
            $table->unsignedBigInteger('event_count');
            $table->timestamp('updated_at');
        });
    }

    private function backfillChains(): void
    {
        $canonicalJson = new CanonicalJson;
        $hasher = new AuditEventHasher($canonicalJson);
        $entityIds = DB::table('accounting_audit_events')
            ->select('legal_entity_id')
            ->distinct()
            ->orderBy('legal_entity_id')
            ->pluck('legal_entity_id');

        foreach ($entityIds as $entityId) {
            $sequence = 0;
            $previousHash = null;
            $technicalAt = null;

            DB::table('accounting_audit_events')
                ->where('legal_entity_id', $entityId)
                ->orderBy('id')
                ->chunkById(500, function ($events) use ($canonicalJson, $hasher, &$previousHash, &$sequence, &$technicalAt): void {
                    foreach ($events as $event) {
                        $sequence++;
                        $payload = $this->decodePayload($event->payload);
                        $technicalAt = $event->created_at ?: $event->occurred_at;
                        $attributes = array_merge((array) $event, [
                            'sequence' => $sequence,
                            'event_schema_version' => AuditEventHasher::EVENT_SCHEMA_VERSION,
                            'canonicalization_version' => CanonicalJson::VERSION,
                            'hash_algorithm' => AuditEventHasher::HASH_ALGORITHM,
                            'canonical_payload' => $canonicalJson->encode($payload),
                            'previous_hash' => $previousHash,
                            'technical_at' => $technicalAt,
                        ]);
                        $eventHash = $hasher->hash($attributes);

                        DB::table('accounting_audit_events')
                            ->where('id', $event->id)
                            ->update([
                                'sequence' => $sequence,
                                'event_schema_version' => AuditEventHasher::EVENT_SCHEMA_VERSION,
                                'canonicalization_version' => CanonicalJson::VERSION,
                                'hash_algorithm' => AuditEventHasher::HASH_ALGORITHM,
                                'canonical_payload' => $attributes['canonical_payload'],
                                'previous_hash' => $previousHash,
                                'event_hash' => $eventHash,
                                'technical_at' => $technicalAt,
                            ]);

                        $previousHash = $eventHash;
                    }
                });

            DB::table('accounting_audit_chain_heads')->updateOrInsert(
                ['legal_entity_id' => $entityId],
                [
                    'last_sequence' => $sequence,
                    'last_event_hash' => $previousHash,
                    'event_count' => $sequence,
                    'updated_at' => $technicalAt,
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = is_string($payload)
            ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR)
            : $payload;

        if (! is_array($decoded)) {
            throw new RuntimeException('Existing audit payload must decode to an object or array.');
        }

        return $decoded;
    }
};

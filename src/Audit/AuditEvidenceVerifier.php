<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Exceptions\AuditEvidenceException;
use JsonException;
use Throwable;

final class AuditEvidenceVerifier
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly AuditEventChainValidator $chainValidator,
        private readonly AuditAnchorChainValidator $anchorValidator,
    ) {}

    public function verify(string $json): AuditEvidenceVerificationResult
    {
        try {
            $evidence = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AuditEvidenceException('Audit evidence is not valid JSON.', previous: $exception);
        }

        if (! is_array($evidence) || array_is_list($evidence)) {
            throw new AuditEvidenceException('Audit evidence must be a JSON object.');
        }

        $evidenceHash = $this->string($evidence, 'evidence_hash');
        $hashInput = $evidence;
        unset($hashInput['evidence_hash']);

        $issues = [];

        if (($evidence['schema_version'] ?? null) !== AuditEvidenceExporter::SCHEMA_VERSION) {
            $issues[] = new AuditChainIssue('evidence_schema_version_unsupported', 'Unsupported audit-evidence schema version.');
        }

        if (($evidence['canonicalization_version'] ?? null) !== CanonicalJson::VERSION) {
            $issues[] = new AuditChainIssue('evidence_canonicalization_unsupported', 'Unsupported audit-evidence canonicalization version.');
        }

        if (($evidence['hash_algorithm'] ?? null) !== AuditEventHasher::HASH_ALGORITHM) {
            $issues[] = new AuditChainIssue('evidence_hash_algorithm_unsupported', 'Unsupported audit-evidence hash algorithm.');
        }

        $expectedEvidenceHash = hash(
            AuditEventHasher::HASH_ALGORITHM,
            $this->canonicalJson->encode($hashInput),
        );

        if (! hash_equals($expectedEvidenceHash, $evidenceHash)) {
            $issues[] = new AuditChainIssue('evidence_hash_mismatch', 'Audit-evidence document hash is invalid.');
        }

        $entity = $this->object($evidence, 'legal_entity');
        $legalEntityId = $this->integer($entity, 'id');
        $legalEntityUuid = $this->string($entity, 'uuid');
        $chainData = $this->object($evidence, 'audit_chain');
        $events = $this->objects($chainData, 'events');
        $head = $chainData['head'] ?? null;

        if ($head !== null && (! is_array($head) || array_is_list($head))) {
            throw new AuditEvidenceException('Audit-evidence field [audit_chain.head] must be null or an object.');
        }

        $chain = $this->chainValidator->verify($legalEntityId, $events, $head);
        $anchors = [];

        foreach ($this->objects($evidence, 'external_anchors') as $anchorData) {
            try {
                $anchors[] = AuditAnchor::fromArray($anchorData);
            } catch (Throwable $exception) {
                throw new AuditEvidenceException('Audit evidence contains a malformed external anchor.', previous: $exception);
            }
        }

        $eventHashes = [];

        foreach ($events as $event) {
            $eventHashes[(int) ($event['sequence'] ?? 0)] = (string) ($event['event_hash'] ?? '');
        }

        $policy = $this->object($evidence, 'anchor_policy');
        $anchorResult = $this->anchorValidator->verify(
            $legalEntityId,
            $legalEntityUuid,
            $chain,
            $anchors,
            $eventHashes,
            $this->boolean($policy, 'required'),
            $this->boolean($policy, 'immutable_storage_attested'),
        );

        return new AuditEvidenceVerificationResult(
            $evidenceHash,
            $chain,
            $anchorResult,
            $issues,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || array_is_list($value)) {
            throw new AuditEvidenceException("Audit-evidence field [{$key}] must be an object.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function objects(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new AuditEvidenceException("Audit-evidence field [{$key}] must be a list.");
        }

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new AuditEvidenceException("Audit-evidence field [{$key}] must contain only objects.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new AuditEvidenceException("Audit-evidence field [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new AuditEvidenceException("Audit-evidence field [{$key}] must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new AuditEvidenceException("Audit-evidence field [{$key}] must be a boolean.");
        }

        return $value;
    }
}

# php-fints upstream dependency and contribution policy

## Dependency boundary

- Upstream repository: `https://github.com/nemiah/phpFinTS`
- Composer package: `nemiah/php-fints`
- Product integration: `FilamentAccounting\Banking\FinTs`
- Protocol namespace: `Fhp\`

`filament-accounting` consumes the public upstream package directly. It does
not maintain a protocol fork or local protocol patches. Laravel, Filament,
tenancy, persistence, encryption, queues, accounting rules, and product UI stay
inside this repository.

The pre-release project currently follows `dev-master` so that its tested
dependency contains upstream changes newer than the latest stable package. A
release must record the resolved commit from the host application's Composer
lock file. The constraint should return to a stable release as soon as that
release contains the verified functionality.

## Upstream contribution evidence gate

An observed difference is not automatically a defect and must not be submitted
upstream until all of the following are available:

1. A minimal, bank-neutral XML or FinTS fixture that reproduces the behavior on
   an untouched current upstream checkout. If it originated from a bank, remove
   personal and secret data without changing the relevant structure and record
   the message type and namespace.
2. A primary specification or implementation guideline that defines the
   relevant element and code. A local expectation or another fork is not proof.
3. A focused test that fails on current upstream for the asserted reason and
   passes after the proposed change. The test must also cover adjacent valid
   values so the fix does not merely special-case one sample.
4. A source trace showing where information is lost and confirming that the
   public API is intended to expose it. Parser behavior, action response
   handling, and the consuming Accounting status mapping must all be checked.
5. The smallest protocol-only fix, with no Laravel, Filament, product, migration,
   or compatibility code unrelated to the demonstrated behavior.
6. A clean upstream test/style/static-analysis run plus the complete Accounting
   quality gate and a fresh demo database run against the patched checkout.
7. Human review of the fixture provenance, specification citation, failing test,
   and diff before an issue or pull request is published.

If any item is missing or ambiguous, document the observation locally and do
not open an upstream pull request.

## Current CAMT observation

An isolated comparison on 3 September 2026 found different booked/pending
results for two inherited fixtures containing `BOOK` and `PDNG`. The Accounting
importer consumes the protocol transaction's booked flag, so the distinction is
potentially relevant. ISO 20022 defines `BOOK` as booked and `PDNG` as pending.
The primary references currently identified are the
[ISO 20022 camt.052 message definition report](https://www.iso20022.org/message/mdr/12666/download/111)
and the
[SWIFT CGI account-reporting best practices](https://www.swift.com/swift-resource/252088/download).

This is not yet an approved change. Before proposing one, replace the inherited
fixtures with minimal, provenance-recorded cases for the exact supported CAMT
namespaces, verify the expected public API semantics with upstream maintainers,
and run the complete evidence gate above. In particular, `INFO`, a missing
status, nested `Sts/Cd`, and message-specific CAMT.052/CAMT.053 rules must be
handled deliberately rather than inferred from two examples.

# Maintained `fliix-cloud/php-fints` protocol deltas

## Provenance and boundary

- Read-only upstream: `https://github.com/nemiah/phpFinTS.git`
- Maintained fork repository: `https://github.com/fliix-cloud/filament-fints.git`
- Target Composer package: `fliix-cloud/php-fints`
- Reviewed common base: `751436372724f0a4f2ff28dcd886b48bb03da83e`
- Transition commit used by this branch: `95ba81e1d88899186e1e4aae02a00d6ae071577b`

The GitHub fork relationship is retained for provenance and read-only comparison.
No pull request, issue, or other write is made against `nemiah/phpFinTS`. Laravel,
Filament, tenancy, persistence, encryption, queues, and product UI belong only in
`fliix-cloud/filament-accounting`; the protocol package contains the `Fhp\`
namespace and protocol tests only.

## Complete local patch inventory

Exactly five `Fhp\` source files differ intentionally from the common base:

| Patch area | File | Reason | Regression evidence |
| --- | --- | --- | --- |
| Booked and pending statements | `src/Action/GetStatementOfAccount.php` | Parse booked and pending MT940 blocks independently, preserve status, and return both sets. | DKB, GLS, ING-DiBa, and Consors statement integration tests in the protocol repository. |
| CAMT.052/.053 statements | `src/Action/GetStatementOfAccountXML.php` | Accept CAMT.052 and CAMT.053 and retain booked/pending XML separately. | `Tests/Unit/CAMT/CamtParserTest.php` plus statement integration tests. |
| Robust CAMT status/dates | `src/CAMT/CAMT.php` | Support required CAMT.052/.053 variants and tolerate bank-specific status/date representations without losing state. | `Tests/Unit/CAMT/CamtParserTest.php`. |
| Missing booked segment | `src/Segment/CAZ/HICAZv1.php` | Represent an absent booked-statement segment while preserving available pending data. | Statement integration tests. |
| SEPA direct debit and resumable SCA | `src/Action/SendSEPADirectDebit.php` | Validate debit-capable source accounts and bank lead times, preserve sequence/date data, and keep persisted actions serialization-compatible. | `Tests/Unit/SendSEPADirectDebitTest.php`. |

Each row is a permanently maintained local patch until an upstream change makes
it redundant and the corresponding regression suite proves equivalent behavior.

## Controlled upstream synchronization

1. Fetch upstream read-only and create `maintenance/sync-upstream-YYYYMMDD` in
   the maintained fork.
2. Compare and merge the new upstream range without writing to upstream.
3. Reapply only the five patch areas above and resolve conflicts by behavior,
   not by blindly preferring either side.
4. Run the complete protocol `composer check` gate, including all core regression
   tests, PHPStan, formatting, and Composer validation.
5. Update the common-base SHA, patch inventory, conflict notes, and tests in the
   same reviewable fork PR.
6. Publish a new `fliix-cloud/php-fints` version only after that PR is reviewed.

There is no planned switch to `nemiah/php-fints`. Such a switch, vendoring the
core, or changing the upstream relationship requires a new explicit ADR.

## Composer transition

The target branch temporarily declares a Composer `package` repository pinned to
the transition commit because the GitHub default branch still advertises the old
Composer package name. After the fork PR is merged, the repository is renamed or
its default branch exposes `fliix-cloud/php-fints`, and a `4.2` release exists,
replace that transition declaration with the normal stable requirement:

```json
"fliix-cloud/php-fints": "^4.2"
```

No host application should declare the protocol dependency directly; it remains
transitive through `fliix-cloud/filament-accounting`.

# Deployment SQL allowlists

SQL allowlist identity is byte-strict after canonicalizing line endings.

`tools/deployment/SqlAllowlist.php` defines the shared `sql-line-endings-v1`
contract for generation and verification: replace CRLF with LF, then lone CR
with LF. Every other byte stays intact, including spaces, tabs, comments,
case, quoting, Unicode, BOMs and semicolons. Terminal newline **representation**
is normalized; terminal newline presence and count stay strict. Nothing is
trimmed. The helper does not parse SQL or split statements, including triggers
with internal semicolons. Comparison never changes the SQL sent to an executor.

An allowlist contains a contract identifier, statement count and an ordered
statement array. Every entry stores its original SQL, 1-based number, raw
SHA-256 and canonical SHA-256. Verification recomputes both hashes, checks
numbering/count and compares canonical bytes and hashes at each position.
Reordered, extra, missing or malformed statements fail closed. Review the
manifest against the exact approved release: hashes verify integrity and
identity, not the provenance or authorization of an arbitrary supplied manifest.

## Offline generation and verification

Capture an entire migration's SQL offline using a connection stub that records
each query without connecting or executing. Pass its ordered `list<string>` to
`SqlAllowlist::generate()`, or serialize that list as UTF-8 JSON and use:

```sh
php tools/deployment/sql-allowlist.php generate statements.json > manifest.json
php tools/deployment/sql-allowlist.php verify manifest.json generated-statements.json
```

The CLI loads no application code, opens no database connection, and accepts
local regular input files. Output is UTF-8 JSON with one document-ending LF;
this newline is outside all SQL strings. Preserve stdout bytes when saving
files. Windows PowerShell 5's default redirection encodes text as UTF-16;
use an explicit UTF-8 writer without a BOM instead. Verification exits 0 for
an exact ordered canonical match, 2 for mismatch or invalid input. Mismatch
reports contain statement numbers, expected/actual raw and canonical hashes,
canonical equality and whether only line endings differ. They omit SQL.
Generated manifests contain SQL and must be handled as private deployment
artifacts when their source is sensitive.

## Protected execution integration

Future separately authorized deployment scripts should require
`tools/deployment/SqlExecutionGuard.php`, construct a `SqlExecutionGuard` with
the reviewed manifest, then call `execute($capturedSql, $executor)`. Capture
and compare the **whole** target-platform plan before executing any statement.
The callback must execute only the supplied original SQL, once, and return
`true` only after success acknowledgement; return `false` or throw on failure.
It must not run additional SQL or a second migration `up()` call. The guard
cannot intercept arbitrary database access inside an untrusted callback.

The guard executes the preverified array in order and is single-use even
after rejection or completion. A failure stops immediately and reports only
acknowledged statement numbers, the last attempted number, and the error class.
The attempted statement may have applied without acknowledgement: inspect the
actual DDL and migration ledger, keep writes suspended, and obtain explicit
recovery authorization. The guard performs no retry, rollback, history insert,
connection management or access restoration. Its report always requires writes
to remain suspended until the surrounding deployment's acceptance gates pass.
Persist this report through the protected deployment evidence mechanism.

Existing protections for read-only inspection, the sole authorized framework
ledger insert, backups, exact pending migration identity and final schema/data
acceptance remain the responsibility of that surrounding deployment script.
This helper authorizes only its exact reviewed statement plan.

## Historical evidence and regression

The ignored v0.32.0 incident scripts at
`build/deploy-v0.32.0/capture-ddl.php` and
`build/deploy-v0.32.0/migration-command.php` generated raw-hash manifests and
checked membership before each statement, checking order after execution.
Windows CRLF SQL therefore rejected against Linux LF at statement 5 after a
matching four-statement prefix. Their manifests append a JSON document newline,
but the captured SQL has no terminal newline. Recovery evidence at
`build/recover-v0.32.0-000035/proof.php` and `recovery-command.php` used a temporary
CRLF replacement plus terminal CR/LF removal. That terminal-removal exception
is not part of the new contract. Historical scripts and evidence remain
unchanged; do not reuse their manifests or execute those scripts. Future
deployment adapters must use the tracked helper and newly reviewed manifests.

No permanent deployment SQL guard tests existed before this helper. Run all
new tooling/guard tests without application bootstrap or a database:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/tooling
```

The synthetic eleven-statement incident fixture, nine LF/CRLF/CR combinations,
strict SQL/BOM/terminal-newline differences, manifest integrity, CLI round trips,
pre-execution refusal, interruption reporting and single-use behavior are
covered by `tests/tooling/SqlAllowlistTest.php` and `SqlAllowlistCliTest.php`.

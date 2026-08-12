# `Unit/` — no framework boot

Extends nothing. No container, no database, no facades, no `RefreshDatabase`. If a test here needs
one of those three, it is a Feature test and belongs in that directory — moving it is the fix, not
adding a trait.

## May assert

- Pure value objects, enums, and the error-class mapping.
- Repository *contract* behaviour against a mocked interface (`App\Repositories\Contracts`).
- The **shape of the test harness itself** — `TenantHarnessShapeTest.php` is the example, and it is
  the one test in this suite that protects the security suite rather than the application.

## May **not** assert

- Anything about a query, a policy decision, a response status, or a rendered resource. Those need
  the container and the database, and a unit test that reaches for either is a feature test that
  will be slow *and* incomplete.
- Anything about tenancy. A mocked repository cannot leak.

## Running

```
./vendor/bin/pest --testsuite=Unit
```

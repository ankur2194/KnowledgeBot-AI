"""The two state objects `lifespan` populates, and the guard that stops them being confused.

`app/main.py`'s `lifespan` does two things with one set of clients: it ASSIGNS three names onto
`app.state` — which is what `app/api/deps.py` reads, because `httpx.ASGITransport` does not run
lifespan at all and a contract test has to be able to install a key ring without one — and it
YIELDS a mapping Starlette merges into `request.state`, which is what `app/api/health.py` reads.

Both halves are deliberate and both are documented. The cost is that **reading the right name
off the wrong object returns `None` instead of failing**: every read on either side is
`getattr(state, name, None)`, so `request.app.state.qdrant` is `None` on a perfectly healthy
container and `request.state.key_ring` is `None` on one that loaded a ring at boot. Neither is
distinguishable from the deployment defect the `None` branch exists to report.

`_assert_state_owner` makes the mistake a `RuntimeError` naming the other state object, while
leaving what a legitimate `None` means untouched. This file is the executable copy of the split:
the two sets are read **out of `lifespan`'s AST**, never restated here, so a future client added
to one half and not the other fails at this seam rather than at the first request that wanted it.
"""

from __future__ import annotations

import ast
from pathlib import Path
from typing import Any, Final

import pytest

from app.api.deps import APP_STATE_NAMES, REQUEST_STATE_NAMES, _from_app_state, from_request_state
from app.core.errors import ErrorClass, KbError, Origin

MAIN_SOURCE: Final[Path] = Path(__file__).resolve().parents[2] / "app" / "main.py"


def _lifespan_ast() -> ast.AsyncFunctionDef:
    """`lifespan`'s definition, parsed rather than regexed.

    Its own docstring names `request.app.state.settings`, `.key_ring`, `.nonce_store`,
    `request.state.qdrant` and `request.state.cache` while explaining the split — so a textual
    search finds the prose and agrees with itself no matter what the code does. The AST sees
    assignments only. (CPython 3.13 also dedents `__doc__` at compile time, so the usual
    `source.replace(func.__doc__, "")` trick silently strips nothing.)
    """
    module = ast.parse(MAIN_SOURCE.read_text(encoding="utf-8"))
    for node in module.body:
        if isinstance(node, ast.AsyncFunctionDef) and node.name == "lifespan":
            return node
    raise AssertionError(f"no `async def lifespan` in {MAIN_SOURCE}")


def test_app_state_names_are_exactly_what_lifespan_assigns() -> None:
    """`APP_STATE_NAMES` is the contract `deps.py` enforces; `lifespan` is what satisfies it."""
    assigned = {
        target.attr
        for node in ast.walk(_lifespan_ast())
        if isinstance(node, ast.Assign)
        for target in node.targets
        if isinstance(target, ast.Attribute)
        and isinstance(target.value, ast.Attribute)
        and target.value.attr == "state"
        and isinstance(target.value.value, ast.Name)
        and target.value.value.id == "app"
    }

    assert assigned == set(APP_STATE_NAMES), (
        "`lifespan` assigns a different set of names onto `app.state` than "
        "`app/api/deps.py` declares. A name it stopped assigning renders 500 on every "
        "/internal/v1 request; a name it started assigning is invisible to the guard."
    )


def test_request_state_names_are_exactly_what_lifespan_yields() -> None:
    """The mirror, read off the `AppState` mapping `lifespan` yields.

    The dict literal, not the `AppState` declaration, and that was once the only option:
    `AppState` was `total=False` and still declared `embedder` and `reranker` months after
    ADR-030 deleted both, and under `total=False` mypy had nothing to say about either — so the
    TypedDict could not be an oracle for anything. It is `total=True` and matches now
    (`test_the_typed_dict_is_an_oracle_again` below), but this assertion stays on the literal:
    the literal is what Starlette actually merges into `request.state`, and an annotation and
    the value it annotates agreeing is a weaker claim than the value being right.
    """
    yielded: set[str] = set()
    for node in ast.walk(_lifespan_ast()):
        if not isinstance(node, ast.AnnAssign) or not isinstance(node.value, ast.Dict):
            continue
        if not (isinstance(node.annotation, ast.Name) and node.annotation.id == "AppState"):
            continue
        yielded = {key.value for key in node.value.keys if isinstance(key, ast.Constant)}

    assert yielded, "no `state: AppState = {...}` literal in `lifespan`"
    assert yielded == set(REQUEST_STATE_NAMES), (
        "`lifespan` yields a different set of names than `app/api/deps.py` declares for "
        "`request.state`. `app/api/health.py` reads through the guard, so a name added here "
        "and not there raises instead of silently reporting the container un-provisioned."
    )


def test_the_typed_dict_is_an_oracle_again() -> None:
    """`AppState` must declare exactly `REQUEST_STATE_NAMES`, and must be total.

    This is finding #107.1, executable. The declaration carried `embedder` and `reranker` long
    after ADR-030 moved embedding and reranking onto the provider adapter layer, and nothing
    caught it: `total=False` makes every key optional, so mypy could not object to a key that no
    longer existed, nor to `db_pool` being yielded — the annotation was incapable of disagreeing
    with the code. A type that cannot be wrong is not checking anything.

    Both halves are asserted because either one alone is satisfiable by a lying type. The name
    set catches a stale or missing key; `__total__` catches the thing that made the stale key
    survive. `__required_keys__` under `total=True` is the whole set, which is what makes a key
    added to `REQUEST_STATE_NAMES` and not to `AppState` a mypy error at `lifespan`'s
    `state: AppState = {...}` rather than a `None` at the first request that wanted it.
    """
    from app.main import AppState

    assert set(AppState.__annotations__) == set(REQUEST_STATE_NAMES)
    assert AppState.__total__, (
        "`AppState` is `total=False` again, so every key is optional and the declaration can no "
        "longer disagree with what `lifespan` yields — which is how `embedder` and `reranker` "
        "outlived ADR-030 in it"
    )
    assert set(AppState.__required_keys__) == set(REQUEST_STATE_NAMES)


def test_settings_is_the_only_name_on_both_and_that_is_deliberate() -> None:
    """The overlap is the reason the split is easy to get wrong somewhere it does not overlap.

    `settings` genuinely lives on both — `deps.py` needs it without a lifespan, `health.py`
    wants the per-request view — so a reader who checks only `settings` concludes the two state
    objects are interchangeable. They are not, and every other name proves it.
    """
    assert {"settings"} == APP_STATE_NAMES & REQUEST_STATE_NAMES


class _FakeState:
    """Starlette's `State` is a `__dict__` proxy; `getattr(..., name, None)` is all that is
    exercised, so a bare namespace is a faithful double and needs no ASGI scope."""

    def __init__(self, **values: Any) -> None:
        self.__dict__.update(values)


class _FakeRequest:
    def __init__(self, *, app_state: _FakeState, request_state: _FakeState) -> None:
        self.app = _FakeState(state=app_state)
        self.state = request_state


def _request(**values: Any) -> Any:
    """One request whose two state objects hold everything a fully-wired lifespan would put
    there — so a raise below can only be the guard, never a missing object."""
    populated = {name: object() for name in APP_STATE_NAMES | REQUEST_STATE_NAMES}
    populated.update(values)
    return _FakeRequest(
        app_state=_FakeState(**{k: populated[k] for k in APP_STATE_NAMES}),
        request_state=_FakeState(**{k: populated[k] for k in REQUEST_STATE_NAMES}),
    )


@pytest.mark.parametrize("name", sorted(REQUEST_STATE_NAMES - APP_STATE_NAMES))
def test_reading_a_request_state_name_off_the_application_raises(name: str) -> None:
    """`qdrant`, `cache` and `db_pool` are yielded, never assigned. Before the guard this
    returned `None` and rendered the generic 500 a missing key ring renders — so "I read the
    wrong state object" and "this deployment is broken" were the same 22 bytes."""
    with pytest.raises(RuntimeError, match=r"does not own it"):
        _from_app_state(_request(), name)


@pytest.mark.parametrize("name", sorted(APP_STATE_NAMES - REQUEST_STATE_NAMES))
def test_reading_an_app_state_name_off_the_request_raises(name: str) -> None:
    """`key_ring` and `nonce_store` are assigned, never yielded — and this direction was the
    silent one: `health.py` treats `None` as "not provisioned" and answers 503, so a typo here
    would have taken a healthy container out of Traefik's rotation with no error anywhere."""
    with pytest.raises(RuntimeError, match=r"does not own it"):
        from_request_state(_request(), name)


def test_the_message_names_the_state_object_that_does_own_it() -> None:
    """A guard that says only "wrong" costs the next reader the same hour the guard saved."""
    with pytest.raises(RuntimeError) as excinfo:
        from_request_state(_request(), "key_ring")

    message = str(excinfo.value)
    assert "request.app.state" in message
    assert "key_ring" in message


def test_a_name_lifespan_owns_on_neither_side_says_so() -> None:
    """`request_id` and `surface` are per-request stamps, not lifespan-owned objects, and the
    message must not send someone looking for them on the other state object."""
    with pytest.raises(RuntimeError, match=r"lifespan` puts it on neither"):
        from_request_state(_request(), "request_id")


def test_a_genuinely_missing_object_still_renders_as_our_defect() -> None:
    """THE GUARD MUST NOT HAVE EATEN THE CONDITION IT SITS IN FRONT OF.

    An owned name that is absent is still a `KbError(INTERNAL_DEPENDENCY, origin=SELF)` — a
    deployment that never loaded a key ring, which no retry fixes (ADR-029). If this stopped
    holding, the two failures would have been merged in the other direction.
    """
    request = _FakeRequest(app_state=_FakeState(), request_state=_FakeState())

    with pytest.raises(KbError) as excinfo:
        _from_app_state(request, "key_ring")

    assert excinfo.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert excinfo.value.origin is Origin.SELF


def test_from_request_state_returns_none_when_lifespan_never_ran() -> None:
    """The legitimate `None`, unchanged. `httpx.ASGITransport` does not run lifespan, and
    `/health/ready` answering 503 in that case is correct rather than a bug to guard against —
    only an unowned NAME raises."""
    request = _FakeRequest(app_state=_FakeState(), request_state=_FakeState())

    assert from_request_state(request, "qdrant") is None
    assert from_request_state(request, "cache") is None

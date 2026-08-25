"""Provider name to adapter instance. The one place a vendor string becomes an object.

``provider_connections.provider`` is a string chosen in the control plane, and something has to
turn it into the adapter that speaks to that vendor. That mapping lives here rather than in a
``getattr`` on a module or an ``if`` ladder at the call site, for the reason
``capabilities.PROVIDER_TASKS`` is data: a lookup that can be *queried* is one a test can assert
against, and a lookup that is spelled out at three call sites is three places to add the sixth
vendor and two places to forget.

**THIS REGISTRY DOES NOT DECIDE WHAT AN ADAPTER CAN DO.** Presence here means "an object exists
for this name", nothing more. ``capabilities.PROVIDER_TASKS`` is the claim about which surfaces
a vendor supports, and ``tests/unit/test_provider_capability_matrix.py`` is what keeps the two
in agreement in both directions. A caller asks the matrix whether the vendor embeds and asks
this registry for the object — never one question standing in for the other, because a vendor
whose adapter exists but whose embedding arm is a stub answers "yes" to the wrong question and
raises ``NotImplementedError`` on the ingestion path, in a class the taxonomy has no row for.

Instances rather than classes, and one instance per process. Every adapter holds nothing
per-organization — the credential is a per-call argument on ``embed`` and ``stream`` — so one
object serves every tenant, and constructing one per call would build an HTTP client per
document.
"""

from __future__ import annotations

from typing import Any, Final

from app.providers.anthropic import AnthropicAdapter
from app.providers.deepseek import DeepSeekAdapter
from app.providers.nim import NimAdapter
from app.providers.openai_adapter import OpenAIAdapter

__all__ = ["ADAPTERS"]

#: Keyed by the string the control plane stores on ``provider_connections.provider``. The keys
#: are the wire values and are not derived from the class names: a rename here would be a
#: silent lookup miss on every existing connection row, and the miss reads as "this vendor has
#: no adapter in this build" rather than as a typo.
#:
#: **Only the OpenAI embedding arm is implemented.** Every other cell of every adapter still
#: raises, which is not a defect of this table: the 2026-08-10 line puts the five wire adapters
#: out of scope, and the embedding arm crossed it only because ``run_version`` cannot index a
#: chunk without exactly one. A connection naming any other vendor resolves to an object here
#: and fails at the call, which is why `bind_embedder` asks the capability matrix first.
ADAPTERS: Final[dict[str, Any]] = {
    "openai": OpenAIAdapter(),
    "anthropic": AnthropicAdapter(),
    "deepseek": DeepSeekAdapter(),
    "nvidia_nim": NimAdapter(),
    # NO `openrouter` ENTRY, AND THE ABSENCE IS THE HONEST ANSWER RATHER THAN A GAP.
    # `OpenRouterAdapter` requires a shared `httpx` client and this deployment's public app URL
    # at construction — it is the only adapter that is not credential-free to build, because
    # its attribution headers carry our identity rather than the tenant's. Constructing one
    # here would mean inventing both, and an adapter built against an invented app URL sends a
    # wrong `HTTP-Referer` on every call, which is an attribution error nothing raises.
    #
    # A connection naming `openrouter` therefore resolves to nothing and `bind_embedder` fails
    # with a sentence, rather than resolving to an object that misreports who is calling.
}

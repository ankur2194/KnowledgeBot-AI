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

from app.core.config import get_settings
from app.providers.anthropic import AnthropicAdapter
from app.providers.deepseek import DeepSeekAdapter
from app.providers.nim import NimAdapter
from app.providers.openai_adapter import OpenAIAdapter
from app.providers.openrouter import OpenRouterAdapter

__all__ = ["ADAPTERS"]

#: Keyed by the string the control plane stores on ``provider_connections.provider``. The keys
#: are the wire values and are not derived from the class names: a rename here would be a
#: silent lookup miss on every existing connection row, and the miss reads as "this vendor has
#: no adapter in this build" rather than as a typo.
#:
#: **All five wire adapters are implemented**, chat included. This entry used to read "only the
#: OpenAI embedding arm is implemented … the 2026-08-10 line puts the five wire adapters out of
#: scope"; that line was redrawn on **2026-08-26** and the adapters were written. What has not
#: changed is that presence here is still only "an object exists for this name": the surfaces a
#: vendor actually serves are ``capabilities.PROVIDER_TASKS``, and a row's flags are the other
#: half — three vendors embed, two publish a rerank route, and of those two only one returns a
#: scale this pipeline can threshold. Ask the matrix; this table cannot answer that question and
#: is not arranged to try.
ADAPTERS: Final[dict[str, Any]] = {
    "openai": OpenAIAdapter(),
    "anthropic": AnthropicAdapter(),
    "deepseek": DeepSeekAdapter(),
    "nvidia_nim": NimAdapter(),
    # THE ONE ENTRY THAT IS NOT CREDENTIAL-FREE TO BUILD, and it is worth knowing why before
    # copying its shape. Every other adapter carries no identity of ours — the tenant's key is
    # a per-call argument and nothing else crosses. OpenRouter's attribution headers name the
    # CALLING APPLICATION, which is this deployment, so it needs `settings.public_app_url` at
    # construction. That value comes from configuration and never from an inbound request: the
    # widget runs on customer sites, and an `HTTP-Referer` filled from a request publishes
    # every customer domain onto a third party's public app-rankings page. An adapter built
    # against an invented URL sends wrong attribution on every call and nothing raises, which
    # is why the placeholder that used to sit here was an absent entry rather than a guess.
    #
    # `http=None` means the adapter builds a short-lived `httpx.AsyncClient` per call, the same
    # arrangement the four siblings have one SDK layer down. A single shared client would be
    # better and it belongs in `app/core/runtime.py`'s `RuntimeClients`, not here: a client
    # constructed at import binds its connection pool to whichever event loop first uses it,
    # and this module is imported by Celery workers that run a fresh loop per task. Reported
    # rather than done — restructuring the lifespan is `fastapi-service`'s call.
    "openrouter": OpenRouterAdapter(http=None, public_app_url=get_settings().public_app_url),
}

# Functional Scope — Auth, Tenancy, Bots, Providers

> Part of the **KnowledgeBot AI** specification — §8.1–8.7, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 8. Functional Scope

## 8.1 Authentication and Accounts

The platform will support:

- Email and password authentication.
- Email verification.
- Password reset.
- Secure session authentication for the web application.
- Personal access tokens for approved API use.
- Organization invitations.
- User activation and suspension.
- Role-based access control.
- Optional two-factor authentication as a post-MVP security enhancement.

Laravel will own authentication, sessions, users, organizations, roles, and permissions.

## 8.2 Multi-Tenancy

The platform will use shared application services with strict tenant isolation.

Every tenant-owned relational record must contain an organization identifier either directly or through an enforced parent relationship.

Every Qdrant point must include tenant and bot identifiers in its payload.

Every storage object must be stored under a tenant-aware path or bucket prefix.

Every cache and queue key must use tenant-safe namespacing where tenant data is involved.

Cross-tenant access must be prevented at:

- HTTP authorization.
- Query scopes.
- Service-layer policies.
- Vector search filters.
- Object-storage paths.
- Analytics queries.
- Logs and exports.

## 8.3 Bot Management

Each bot will have:

- Name.
- Slug.
- Description.
- Status: draft, testing, published, paused, or archived.
- Public or private access mode.
- Welcome message.
- Placeholder text.
- Suggested starter questions.
- System instruction.
- Answer-style instruction.
- Provider and model selection.
- Optional fallback model chain.
- Embedding model configuration.
- Reranker configuration.
- Retrieval configuration.
- Citation configuration.
- Refusal and insufficient-evidence behavior.
- Knowledge-source assignments.
- Appearance configuration.
- Allowed website origins.
- Rate limits.
- Conversation retention settings.
- Data collection and consent text.

A bot may use multiple knowledge sources. A source may be assigned to one or more bots in the same organization.

## 8.4 Provider and Model Management

The admin panel will support direct configuration for:

- OpenAI.
- Anthropic Claude.
- DeepSeek.
- NVIDIA NIM API.
- OpenRouter.
- Future providers through the same adapter contract.

Each provider connection will contain:

- Provider type.
- Display name.
- API base URL when the provider officially supports configuration.
- Encrypted API key or token.
- Optional organization, project, or account identifier.
- Timeout settings.
- Retry policy.
- Enabled or disabled state.
- Last successful connection test.
- Last failure summary.
- Usage limits defined by the organization.

Each model record will contain:

- Provider connection.
- Official model identifier.
- Display name.
- Model family.
- Supported input types.
- Text generation capability.
- Image input capability.
- Tool-use capability.
- Structured-output capability.
- Reasoning capability.
- Context-window information entered from provider documentation.
- Maximum configured output.
- Pricing metadata used only for estimated reporting.
- Enabled or disabled state.
- Recommended use category.

The system must not assume that all providers support identical parameters. Provider-specific capabilities must be explicit.

## 8.5 Direct Official API Integration

KnowledgeBot AI will not use LiteLLM Gateway.

Instead, the AI service will implement an internal provider abstraction with separate provider adapters.

The common internal request will describe the desired behavior, while each provider adapter will translate that request into the provider's official format.

The shared internal request may contain:

- Model identifier.
- System instruction.
- Conversation messages.
- Retrieved context.
- Maximum output.
- Temperature when supported.
- Reasoning setting when supported.
- Structured response schema when supported.
- Tool definitions when enabled in future.
- Image inputs when supported.
- Streaming requirement.
- Metadata and trace identifiers.

The common internal response will normalize only information required by KnowledgeBot:

- Text deltas.
- Completed text.
- Stop reason.
- Input tokens.
- Output tokens.
- Cached tokens when reported.
- Reasoning tokens when reported.
- Provider request identifier.
- Provider latency.
- Error classification.
- Safety or refusal status.

Provider-specific details should also be preserved in a restricted diagnostic payload so that useful provider features are not lost through over-normalization.

## 8.6 Provider Adapter Responsibilities

Every provider adapter must handle:

- Authentication.
- Request translation.
- Official endpoint selection.
- Streaming event translation.
- Timeout handling.
- Retryable versus non-retryable error classification.
- Rate-limit information.
- Token-usage extraction.
- Stop-reason mapping.
- Provider request IDs.
- Model-specific parameter validation.
- Safety or refusal responses.
- Provider-specific caching options when supported.
- Provider-specific reasoning options when supported.
- Provider-specific structured output when supported.

The adapter layer must not silently discard a provider feature. Unsupported options must be rejected or clearly ignored with a diagnostic warning.

## 8.7 Provider Routing and Fallback

The first release should keep routing understandable.

Supported modes:

1. **Fixed model:** Every request uses the selected model.
2. **Manual model selection:** An authorized tester chooses a model in the playground.
3. **Ordered fallback:** A secondary model is used only when the primary provider returns an eligible operational failure.

Fallback should be allowed for:

- Provider outage.
- Connection timeout.
- Temporary server error.
- Rate-limit response when configured.
- Model temporarily unavailable.

Fallback should not automatically occur for:

- Authentication failure.
- Invalid request.
- Content-policy refusal.
- Tenant quota exceeded.
- Context too large due to an application bug.
- User cancellation.

Every fallback event must be visible in telemetry and conversation diagnostics.


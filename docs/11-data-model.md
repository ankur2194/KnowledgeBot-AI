# Data Model

> Part of the **KnowledgeBot AI** specification — §16, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 16. Data Model

The exact schema will be finalized during implementation, but the following entities are required.

## 16.1 Identity and Tenancy

### users

- ID.
- Name.
- Email.
- Password hash.
- Verification status.
- Account status.
- Last login.
- Timestamps.

### organizations

- ID.
- Name.
- Slug.
- Status.
- Default timezone.
- Retention settings.
- Storage quota.
- Request quota.
- Timestamps.

### organization_users

- Organization ID.
- User ID.
- Role.
- Status.
- Invitation metadata.

### roles and permissions

- Role definitions.
- Permission definitions.
- Organization-scoped assignments.

## 16.2 Provider Configuration

### provider_connections

- ID.
- Organization ID.
- Provider type.
- Display name.
- Encrypted credential reference.
- Base URL when allowed.
- Additional encrypted settings.
- Enabled state.
- Last tested time.
- Last test status.
- Timestamps.

### provider_models

- ID.
- Provider connection ID.
- Official model identifier.
- Display name.
- Capability flags.
- Context configuration.
- Output limits.
- Pricing metadata.
- Enabled state.
- Timestamps.

## 16.3 Bots

### bots

- ID.
- Organization ID.
- Name.
- Slug.
- Description.
- Status.
- Access mode.
- Provider model ID.
- Fallback settings.
- Prompt configuration version.
- Retrieval configuration ID.
- Theme configuration.
- Retention configuration.
- Timestamps.

### bot_domains

- Bot ID.
- Allowed origin.
- Status.

### bot_starter_questions

- Bot ID.
- Question.
- Sort order.

### bot_source_assignments

- Bot ID.
- Source ID.
- Priority.
- Optional access metadata.
- Enabled state.

## 16.4 Knowledge Sources

### knowledge_sources

- ID.
- Organization ID.
- Type.
- Name.
- Status.
- Original location.
- Storage object reference.
- Crawl configuration ID.
- Current version ID.
- Tags.
- Effective and expiry times.
- Created by.
- Timestamps.
- Deletion timestamps.

### source_items

A source may contain many independently versioned items, such as pages within a sitemap.

- ID.
- Source ID.
- Canonical identity.
- URL or internal path.
- Title.
- Status.
- Current version ID.
- Last discovered time.
- Missing count.
- Timestamps.

### source_versions

- ID.
- Source item ID.
- Version number.
- Content hash.
- Parser configuration version.
- Chunker configuration version.
- Embedding model version.
- Processing status.
- Warning summary.
- Activated time.
- Retired time.
- Timestamps.

### document_elements

- ID.
- Source version ID.
- Parent element ID.
- Element type.
- Sequence.
- Page, slide, sheet, or section metadata.
- Normalized content reference.
- Structured metadata.

### chunks

- ID.
- Source version ID.
- Document element ID.
- Sequence.
- Text.
- Token count.
- Content hash.
- Metadata.
- Vector point ID.
- Index status.
- Timestamps.

## 16.5 Crawling

### crawl_configurations

- Source ID.
- Sitemap or seed URLs.
- Schedule.
- Include rules.
- Exclude rules.
- Maximum pages.
- Maximum depth.
- JavaScript rendering policy.
- Missing-page policy.
- Robots policy.
- User agent.
- Concurrency and delay.

### crawl_runs

- ID.
- Source ID.
- Trigger type.
- Status.
- URLs discovered.
- URLs fetched.
- URLs changed.
- URLs unchanged.
- URLs added.
- URLs missing.
- Errors.
- Start and finish times.

### crawl_page_results

- Crawl run ID.
- Source item ID.
- URL.
- HTTP status.
- ETag.
- Last-Modified.
- Content hash.
- Result status.
- Error summary.

## 16.6 Conversations

### conversations

- ID.
- Organization ID.
- Bot ID.
- User ID or anonymous session ID.
- Channel.
- Status.
- Locale.
- Consent metadata.
- Started time.
- Last activity time.
- Retention expiry.

### messages

- ID.
- Conversation ID.
- Role.
- Content.
- Status.
- Parent message ID when retrying.
- Provider call ID when applicable.
- Created time.

### provider_calls

- ID.
- Organization ID.
- Bot ID.
- Conversation ID.
- Message ID.
- Provider connection ID.
- Model ID.
- Provider request ID.
- Status.
- Input tokens.
- Output tokens.
- Other token categories.
- Estimated cost.
- First-token latency.
- Total latency.
- Fallback metadata.
- Error class.
- Timestamps.

### retrieval_traces

- ID.
- Message ID.
- Original query.
- Rewritten query.
- Filters.
- Retrieval configuration version.
- Candidate summaries.
- Selected evidence.
- Insufficient-evidence result.
- Timing breakdown.

### citations

- ID.
- Message ID.
- Chunk ID.
- Citation label.
- Display title.
- Location metadata.
- Excerpt.

### feedback

- ID.
- Message ID.
- Rating.
- Comment.
- Submitted by user or anonymous session.
- Timestamps.

## 16.7 Evaluation

### evaluation_datasets

- ID.
- Organization ID.
- Bot ID.
- Name.
- Description.
- Status.

### evaluation_cases

- Dataset ID.
- Question.
- Expected answer or grading notes.
- Expected sources.
- Tags.
- Difficulty.

### evaluation_runs

- ID.
- Dataset ID.
- Bot configuration snapshot.
- Model configuration.
- Retrieval configuration.
- Status.
- Aggregate metrics.
- Start and finish times.

### evaluation_results

- Evaluation run ID.
- Case ID.
- Generated answer.
- Retrieved evidence.
- Metric results.
- Human-review status.
- Error details.

## 16.8 Operations

### background_jobs

- ID.
- Organization ID.
- Type.
- Entity reference.
- External worker job ID.
- Status.
- Progress.
- Retry count.
- Error summary.
- Timestamps.

### audit_logs

- ID.
- Organization ID.
- Actor.
- Action.
- Entity type and ID.
- Before and after summary.
- IP and user agent where appropriate.
- Trace ID.
- Timestamp.

### usage_events

- Organization ID.
- Bot ID.
- Event type.
- Quantity.
- Provider and model.
- Timestamp.
- Aggregation metadata.


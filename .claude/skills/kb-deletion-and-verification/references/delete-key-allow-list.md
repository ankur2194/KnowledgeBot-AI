# The delete-key allow-list — greppable, CI-gated

Detail split out of `kb-deletion-and-verification/SKILL.md`, which owns the rule this enumerates: deletion targets stable identifiers, never text matching (§8.17).

**Permitted, exhaustively:** the Qdrant point id itself (`chunks.vector_point_id`), and the payload keys `org_id`, `bot_id`, `source_id`, `source_item_id`, `source_version_id`. Every one is an identifier PostgreSQL issued; none is derived from content, so all survive a re-parse, a re-embed and an `embedding_model_version` bump. `MatchValue` is permitted only against a key on this list, `MatchAny` only against a list of that key's values.

**Forbidden in any deletion path.** These were the literal strings a CI gate grepped for until 2026-08-17; the strings are unchanged and nothing greps for them now: the matchers `MatchText`, `FullTextMatch`, `full_text`, and the payload keys `text`, `content`, `content_hash`, `chunk_text`, `chunk_content`, `body`, `title`, `heading`, `element_text`, `page_content`, `excerpt`, `payload_text`. A Qdrant delete whose filter names any of them — or any payload key not on the permitted list — is the banned shape, however it is spelled. The gate greps the deletion modules only, which have no legitimate reason to name a content field at all, so any hit fails the build (`the deleted CI gate` owns the job):

```bash
grep -rnE "MatchText|FullTextMatch|full_text|\b(text|content|content_hash|chunk_text|chunk_content|body|title|heading|element_text|page_content|excerpt|payload_text)\b" services/ai-service/app/deletion/ services/core-api/app/Deletion/ && exit 1
```

Note the one deliberate overlap with the erasure sweep: `citations.excerpt` and the other verbatim-text columns are written **by column name in PostgreSQL**, never used as a Qdrant match key. The grep above covers the deletion modules' Qdrant filters; an erasure sweep that names `excerpt` in a PostgreSQL `UPDATE` lives outside those paths and is not a violation.

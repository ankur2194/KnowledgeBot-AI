# Keeping Qdrant genuinely derived — four mechanics, not aspirations

Together these are what ADR-010 actually costs. Referenced from `kb-architecture-map`'s
"How we use it"; the gotchas that show what each one prevents are Gotchas 5–7 in the skill.

- **Application code never names a concrete collection** — always an alias. Alias updates in Qdrant are atomic (*"no concurrent requests will be affected during the switch"*), so build the new collection in the background and swap in one `update_aliases` call. Retrofitting the alias later is itself an outage.
- **The payload is a projection, not a record.** Build it through one serializer derived from primary rows; ban ad-hoc `set_payload` outside it. If a field can't be produced from PostgreSQL, it can't enter the payload.
- **Between rebuilds, sync through a transactional outbox, not dual writes.** No transaction spans PostgreSQL and Qdrant, so a dual write that half-fails is silent until a user searches. Writing the chunk row and its index event in one PG transaction turns a correctness problem into a lag problem you can alert on.
- **Rebuild is a CI check, not a recovery script.** A seeded fixture rebuilt from PostgreSQL must reproduce the same point count, payload keys, and payload values. Snapshot restore is the RTO tool (§25.3 option 1); rebuild is the correctness proof (option 2). Only the second one is a test.

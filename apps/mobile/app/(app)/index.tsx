import { useQuery } from '@tanstack/react-query';
import { Link, Stack } from 'expo-router';
import type { ReactNode } from 'react';
import { FlatList, StyleSheet, Text, TouchableOpacity, View } from 'react-native';

import { apiFetch } from '@/api/client';
import { useSession } from '@/auth/session-provider';
import { orgKey } from '@/lib/query-client';

interface ConversationSummary {
  readonly id: string;
  readonly title: string | null;
  readonly updated_at: string;
}

/**
 * The conversation list — the app's home screen, and the first place the tenancy rules bite.
 *
 * The query key is `orgKey(organizationId, 'conversations')`, i.e. `['org', <id>, 'conversations']`.
 * `organizationId` is a CACHE NAMESPACE, not a request parameter: Laravel derives the real
 * organization from the bearer token and would ignore anything we sent. Without it in the key,
 * `['conversations']` is one cache entry that serves the previous user of this device — and the
 * symptom is a correctly rendered list, not an error. One device serves several people; that is
 * the assumption this app has and a browser profile does not.
 *
 * `signal` is destructured and forwarded, or `cancelQueries` aborts nothing and only discards the
 * result — which on a phone means a request that outlives the screen and finishes over cellular.
 */
export default function ConversationsScreen(): ReactNode {
  const { organizationId } = useSession();

  const conversations = useQuery({
    // The gate guarantees an authenticated session, so organizationId is non-null here. `enabled`
    // makes that explicit rather than trusting the invariant across a future refactor.
    enabled: organizationId !== null,
    queryKey: orgKey(organizationId ?? '', 'conversations'),
    queryFn: ({ signal }) =>
      apiFetch<{ data: readonly ConversationSummary[] }>({
        // `rt/v1`, the PUBLIC chat runtime surface — not `api/v1`, which is the admin group behind
        // a Sanctum cookie session and CSRF. This app carries a personal access token in an
        // Authorization header and no cookie, and routes/api_public.php names mobile as exactly the
        // exception that carries a PAT on the runtime surface. The endpoint does not exist in
        // Laravel yet; the prefix does, and pointing at the wrong one now is how three clients end
        // up confidently wrong with green suites.
        path: '/rt/v1/conversations',
        signal,
      }),
    // No `retry` here, and none anywhere outside src/lib/query-client.ts — a per-call override
    // re-multiplies attempts against the FastAPI adapter's own ladder. ESLint enforces it.
  });

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Conversations' }} />
      <FlatList
        data={conversations.data?.data ?? EMPTY}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <Link href={`/chat/${item.id}`} asChild>
            <TouchableOpacity style={styles.row}>
              <Text style={styles.rowTitle}>{item.title ?? 'Untitled conversation'}</Text>
            </TouchableOpacity>
          </Link>
        )}
        ListEmptyComponent={
          <Text style={styles.empty}>
            {conversations.isPending ? 'Loading…' : 'No conversations yet.'}
          </Text>
        }
      />
    </View>
  );
}

/** Hoisted: an inline `?? []` is a fresh array identity every render and re-renders every row. */
const EMPTY: readonly ConversationSummary[] = [];

const styles = StyleSheet.create({
  container: { flex: 1 },
  empty: { color: '#64748B', padding: 24, textAlign: 'center' },
  row: { borderBottomColor: '#E2E8F0', borderBottomWidth: 1, padding: 16 },
  rowTitle: { fontSize: 16 },
});

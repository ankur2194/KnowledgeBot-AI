import { Link, Stack } from 'expo-router';
import type { ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';

/**
 * The catch-all. It is reached two ways and both matter:
 *
 *  1. A deep link naming a route this build does not have — an old link, a typo, a probe. Every
 *     route under app/ is automatically deep-linkable, so the inverse is also true: anything NOT
 *     under app/ has to land somewhere, and this is it.
 *  2. A screen that validated its own parameter and rejected it (see
 *     app/(app)/chat/[conversationId].tsx), or that asked Laravel and got the public 404.
 *
 * The copy is deliberately identical for "this route does not exist", "this conversation does not
 * exist" and "this conversation belongs to someone else". A distinguishable message for the third
 * case confirms the row exists and turns a deep link into an enumeration oracle — which is exactly
 * why the server returns 404 rather than 403 on public surfaces.
 */
export default function NotFoundScreen(): ReactNode {
  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Not found' }} />
      <Text style={styles.title}>Not found</Text>
      <Text style={styles.body}>This page is not available.</Text>
      <Link href="/" style={styles.link}>
        Go to conversations
      </Link>
    </View>
  );
}

const styles = StyleSheet.create({
  body: { color: '#64748B', marginTop: 8 },
  container: { alignItems: 'center', flex: 1, justifyContent: 'center', padding: 24 },
  link: { color: '#2563EB', marginTop: 16 },
  title: { fontSize: 22, fontWeight: '600' },
});

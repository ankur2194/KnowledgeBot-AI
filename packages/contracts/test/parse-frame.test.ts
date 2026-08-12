import { describe, expect, it } from 'vitest';

import { createFrameBuffer, parseFrame } from '../src/sse/parse-frame.js';

describe('parseFrame — WHATWG field rules', () => {
  it('strips exactly one leading space from a value', () => {
    expect(parseFrame('data: {"text":" indented"}')?.data).toBe('{"text":" indented"}');
    expect(parseFrame('data:{"text":"x"}')?.data).toBe('{"text":"x"}');
  });

  it('joins multi-line data with a newline', () => {
    expect(parseFrame('event: token\ndata: a\ndata: b')?.data).toBe('a\nb');
  });

  it('returns null for a comment-only block — `: ping` is not an event', () => {
    expect(parseFrame(': ping')).toBeNull();
    expect(parseFrame(':')).toBeNull();
  });

  it('reads the event name and ignores unknown fields', () => {
    const frame = parseFrame('event: status\nfuture_field: 1\ndata: {"stage":"retrieving"}');
    expect(frame?.event).toBe('status');
    expect(frame?.data).toBe('{"stage":"retrieving"}');
  });

  it('parses id and retry, and defaults them to null', () => {
    const frame = parseFrame('id: 7\nretry: 10000\ndata: x');
    expect(frame?.id).toBe('7');
    expect(frame?.retry).toBe(10000);
    expect(parseFrame('data: x')?.retry).toBeNull();
  });
});

describe('createFrameBuffer — chunk boundaries', () => {
  it('reassembles a frame split mid-line across two chunks', () => {
    const buffer = createFrameBuffer();
    expect(buffer.push('event: token\nda')).toEqual([]);
    expect(buffer.push('ta: {"text":"hi"}\n\n')).toEqual([
      { event: 'token', data: '{"text":"hi"}', id: null, retry: null },
    ]);
  });

  it('reassembles a frame split between the two newlines of the separator', () => {
    const buffer = createFrameBuffer();
    expect(buffer.push('data: a\n')).toEqual([]);
    expect(buffer.push('\ndata: b\n\n')).toHaveLength(2);
  });

  it('does not split a frame when \\r and \\n land in different chunks', () => {
    const buffer = createFrameBuffer();
    expect(buffer.push('data: a\r')).toEqual([]);
    // Normalising the trailing \r in the previous chunk would have made this \n\n — one frame
    // becoming two, with the second one empty.
    const frames = buffer.push('\ndata: b\r\n\r\n');
    expect(frames).toHaveLength(1);
    expect(frames[0]?.data).toBe('a\nb');
  });

  it('drops heartbeats without dispatching an event', () => {
    const buffer = createFrameBuffer();
    expect(buffer.push(': ping\n\n: ping\n\n')).toEqual([]);
  });

  it('yields nothing from flush() when the stream hangs up mid-frame', () => {
    const buffer = createFrameBuffer();
    buffer.push('event: token\ndata: {"text":"par');
    // The caller's missing-terminal-event check is what raises `stream_lost`; the parser's job is
    // to not invent a frame out of half of one.
    expect(buffer.flush()).toEqual([]);
  });
});

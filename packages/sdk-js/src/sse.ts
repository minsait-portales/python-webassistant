/** Lector incremental de Server-Sent Events sobre un `ReadableStream` (fetch). */
export interface SseMessage {
  id: string | null;
  event: string;
  data: string;
}

export class SseParser {
  private buffer = '';
  private dataLines: string[] = [];
  private event = 'message';
  private id: string | null = null;

  feed(chunk: string): SseMessage[] {
    this.buffer += chunk;
    const messages: SseMessage[] = [];
    let index: number;
    while ((index = this.buffer.search(/\r\n|\r|\n/)) >= 0) {
      const line = this.buffer.slice(0, index);
      const newline = this.buffer.startsWith('\r\n', index) ? 2 : 1;
      this.buffer = this.buffer.slice(index + newline);
      if (line === '') {
        if (this.dataLines.length) {
          messages.push({ id: this.id, event: this.event, data: this.dataLines.join('\n') });
        }
        this.dataLines = [];
        this.event = 'message';
        continue;
      }
      if (line.startsWith(':')) continue;
      const colon = line.indexOf(':');
      const field = colon >= 0 ? line.slice(0, colon) : line;
      const value = colon >= 0 ? line.slice(colon + 1).replace(/^ /, '') : '';
      if (field === 'data') this.dataLines.push(value);
      else if (field === 'event') this.event = value;
      else if (field === 'id') this.id = value;
    }
    return messages;
  }
}

export async function* readSse(
  stream: ReadableStream<Uint8Array>,
  signal?: AbortSignal,
): AsyncGenerator<SseMessage> {
  const reader = stream.getReader();
  const decoder = new TextDecoder();
  const parser = new SseParser();
  try {
    while (true) {
      if (signal?.aborted) return;
      const { value, done } = await reader.read();
      if (done) return;
      for (const message of parser.feed(decoder.decode(value, { stream: true }))) {
        yield message;
      }
    }
  } finally {
    reader.releaseLock();
  }
}

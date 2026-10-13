// Reports API request timings to cofa/performance-tracking-times from React
// Native, or any JavaScript runtime without a browser page. In a web page,
// load client.js instead. See the README, "Mobile app".
//
//   const response = await timedFetch('https://api.example.com/api/orders');
//
// Call flush() from an AppState 'background' listener.

// Change to your API's address.
const ENDPOINT = 'https://api.example.com/performance-tracking/api/client';
const OFFSET_TTL_MS = 10 * 60 * 1000;

type Report = {
  id: string;
  t0: number;
  t3: number;
  offset_ms: number | null;
  calc_ms?: number;
  headers_only: boolean;
};

const queue: Report[] = [];
const offsets = new Map<string, { offset: number; rtt: number; at: number }>();
let timer: ReturnType<typeof setTimeout> | null = null;

/** Use instead of fetch() for calls to the API. */
export async function timedFetch(input: RequestInfo, init?: RequestInit, calcMs?: number): Promise<Response> {
  const t0 = Date.now();
  const response = await fetch(input, init);
  const header = response.headers.get('x-request-timing');
  if (header) record(header, t0, Date.now(), calcMs);
  return response;
}

function record(header: string, t0: number, t3: number, calcMs?: number): void {
  const parts: Record<string, string> = {};
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0) parts[part.slice(0, i).trim()] = part.slice(i + 1).trim();
  }
  const t1 = Number(parts.t1);
  const t2 = Number(parts.t2);
  if (!parts.id || !Number.isFinite(t1) || !Number.isFinite(t2)) return;

  // Clock offset (server minus device), per server, from the fastest exchange.
  const node = parts.n ?? '';
  const rtt = (t3 - t0) - (t2 - t1);
  const best = offsets.get(node);
  if (rtt >= 0 && (!best || rtt <= best.rtt || t3 - best.at > OFFSET_TTL_MS)) {
    offsets.set(node, { offset: ((t1 - t0) + (t2 - t3)) / 2, rtt, at: t3 });
  }

  queue.push({
    id: parts.id,
    t0,
    t3,
    offset_ms: offsets.get(node)?.offset ?? null,
    ...(calcMs !== undefined ? { calc_ms: calcMs } : {}),
    headers_only: true, // t3 is when the headers arrived; the caller reads the body later
  });

  if (queue.length >= 20) void flush();
  else timer ??= setTimeout(() => void flush(), 5000);
}

/** Sends the queued timings. Also call this when the app goes to the background. */
export async function flush(): Promise<void> {
  if (timer) clearTimeout(timer);
  timer = null;

  const batch = queue.splice(0, 50);
  if (!batch.length) return;

  try {
    await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ timings: batch }),
    });
  } catch {
    // Monitoring never breaks the app.
  }

  if (queue.length) await flush();
}

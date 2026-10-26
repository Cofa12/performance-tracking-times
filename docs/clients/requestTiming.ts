// Reports API request timings to cofa/performance-tracking-times from React
// Native, or any JavaScript runtime without a browser page. In a web page,
// load client.js instead. See the README, "Mobile app".
//
// A plain call:
//
//   const response = await timedFetch('https://api.example.com/api/orders');
//
// With "calculating" time: the work the app does to prepare the request
// (building and encoding the body, signing, encrypting, compressing an
// upload) is timed and reported with it:
//
//   const response = await measure(async () => {
//     const body = JSON.stringify(buildOrder(cart));   // counted as calculating
//     return ['https://api.example.com/api/orders', { method: 'POST', body }];
//   });
//
// Call flush() from an AppState 'background' listener.

// Change to your API's address.
const ENDPOINT = 'https://api.example.com/performance-tracking/api/client';
const OFFSET_TTL_MS = 10 * 60 * 1000;
// Server limit for calculating time; a larger value would get the whole
// report rejected, so it is dropped instead.
const MAX_CALC_MS = 10 * 60 * 1000;

// A monotonic clock where there is one (React Native and browsers have
// performance.now()): the phone changing its clock does not affect it.
const monotonicNow = (): number =>
  typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();

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

/**
 * Runs `prepare`, which builds the request and returns fetch()'s arguments,
 * and reports the time it took as "calculating" along with that request.
 * Keep other network calls (a token refresh, say) outside `prepare`: their
 * time is not calculating.
 */
export async function measure(
  prepare: () => [RequestInfo, RequestInit?] | Promise<[RequestInfo, RequestInit?]>,
): Promise<Response> {
  const start = monotonicNow();
  const [input, init] = await prepare();
  const calcMs = monotonicNow() - start;

  return timedFetch(input, init, calcMs);
}

/**
 * Use instead of fetch() for calls to the API. `calcMs` is the time spent
 * preparing the request, if you measured it yourself (see `measure`).
 */
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
    ...(calcMs !== undefined && calcMs >= 0 && calcMs <= MAX_CALC_MS ? { calc_ms: calcMs } : {}),
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

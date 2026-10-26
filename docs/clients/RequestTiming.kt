// Reports API request timings to cofa/performance-tracking-times from an
// Android app (OkHttp 4+, Retrofit included). See the README, "Mobile app".
//
// Add the interceptor to the OkHttpClient your API calls use; every call is
// then timed, with no other change:
//
//   val client = OkHttpClient.Builder().addInterceptor(RequestTiming.interceptor).build()
//
// With "calculating" time: the work the app does to prepare the request
// (building and encoding the body, signing, encrypting, compressing an
// upload) is timed and reported with it. With OkHttp (blocking, so call it
// from Dispatchers.IO):
//
//   val response = RequestTiming.measure(client) {
//       val body = gson.toJson(buildOrder(cart))                    // counted as calculating
//       Request.Builder().url("https://api.example.com/api/orders")
//           .post(body.toRequestBody("application/json".toMediaType()))
//           .build()
//   }
//
// With Retrofit, time the preparation and pass the result as a @Tag:
//
//   @POST("orders") suspend fun createOrder(@Body order: Order, @Tag calc: RequestTiming.CalcTime?): OrderResponse
//
//   val (order, calc) = RequestTiming.time { buildOrder(cart) }   // counted as calculating
//   api.createOrder(order, calc)
//
// Send the queued timings when the app goes to the background, e.g. from a
// ProcessLifecycleOwner ON_STOP observer: RequestTiming.flush()

import java.io.IOException
import java.util.Locale
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import okhttp3.Call
import okhttp3.Callback
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response

object RequestTiming {
    // Change to your API's address.
    @Volatile
    var endpoint: String = "https://api.example.com/performance-tracking/api/client"

    private const val OFFSET_TTL_MS = 10 * 60 * 1000.0

    // Server limit for calculating time; a larger value would get the whole
    // report rejected, so it is dropped instead.
    private const val MAX_CALC_MS = 10 * 60 * 1000.0

    private val UUID = Regex("^[0-9a-fA-F-]{36}$")

    /** Time spent preparing a request, attached to it as an OkHttp/Retrofit tag. */
    class CalcTime(val ms: Double)

    private data class Offset(val offset: Double, val rtt: Double, val at: Double)

    private data class Report(val id: String, val t0: Double, val t3: Double, val offsetMs: Double?, val calcMs: Double?)

    private val lock = Any()
    private val queue = mutableListOf<Report>()
    private val offsets = mutableMapOf<String, Offset>()
    private var pending: ScheduledFuture<*>? = null
    private val scheduler = Executors.newSingleThreadScheduledExecutor { runnable ->
        Thread(runnable, "request-timing").apply { isDaemon = true }
    }

    // Reports go out on their own client, without the interceptor.
    private val reporter = OkHttpClient()

    /** Times every call made through the client it is added to. */
    val interceptor = Interceptor { chain ->
        val request = chain.request()
        val t0 = wallNow()
        val response = chain.proceed(request)
        // When proceed() returns the headers have arrived; the body is read
        // later by the caller, so the report says headers_only.
        val t3 = wallNow()
        response.header("X-Request-Timing")?.let { record(it, t0, t3, request.tag(CalcTime::class.java)?.ms) }
        response
    }

    /**
     * Runs [prepare], which builds the request, and executes it on [client]
     * with the time [prepare] took reported as "calculating". Blocking: call
     * it off the main thread. Keep other network calls (a token refresh, say)
     * outside [prepare]: their time is not calculating.
     */
    fun measure(client: OkHttpClient, prepare: () -> Request): Response {
        val (request, calc) = time(prepare)
        return client.newCall(request.newBuilder().tag(CalcTime::class.java, calc).build()).execute()
    }

    /** Runs [prepare] and returns its result with the time it took, for a Retrofit @Tag. */
    fun <T> time(prepare: () -> T): Pair<T, CalcTime> {
        // Monotonic: the phone changing its clock does not affect it.
        val start = System.nanoTime()
        val result = prepare()
        return result to CalcTime((System.nanoTime() - start) / 1_000_000.0)
    }

    /** Sends the queued timings. Also call this when the app goes to the background. */
    fun flush() {
        val batch = synchronized(lock) {
            pending?.cancel(false)
            pending = null
            if (queue.isEmpty()) return
            val batch = queue.take(50)
            repeat(batch.size) { queue.removeAt(0) }
            batch
        }

        val request = Request.Builder()
            .url(endpoint)
            .post(json(batch).toRequestBody("application/json".toMediaType()))
            .build()

        reporter.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {} // monitoring never breaks the app
            override fun onResponse(call: Call, response: Response) = response.close()
        })

        if (synchronized(lock) { queue.isNotEmpty() }) flush()
    }

    private fun record(header: String, t0: Double, t3: Double, calcMs: Double?) {
        val parts = header.split(';').mapNotNull { part ->
            val i = part.indexOf('=')
            if (i > 0) part.substring(0, i).trim() to part.substring(i + 1).trim() else null
        }.toMap()

        val id = parts["id"]?.takeIf { UUID.matches(it) } ?: return
        val t1 = parts["t1"]?.toDoubleOrNull() ?: return
        val t2 = parts["t2"]?.toDoubleOrNull() ?: return
        val node = parts["n"] ?: ""

        val flushNow = synchronized(lock) {
            // Clock offset (server minus device), per server, from the fastest exchange.
            val rtt = (t3 - t0) - (t2 - t1)
            val best = offsets[node]
            if (rtt >= 0 && (best == null || rtt <= best.rtt || t3 - best.at > OFFSET_TTL_MS)) {
                offsets[node] = Offset(((t1 - t0) + (t2 - t3)) / 2, rtt, t3)
            }

            queue += Report(id, t0, t3, offsets[node]?.offset, calcMs?.takeIf { it in 0.0..MAX_CALC_MS })

            if (queue.size >= 20) {
                true
            } else {
                if (pending == null) pending = scheduler.schedule({ flush() }, 5, TimeUnit.SECONDS)
                false
            }
        }

        if (flushNow) flush()
    }

    // Locale.ROOT: in some locales (Arabic, for one) "%f" would write
    // non-ASCII digits or a decimal comma, which is not valid JSON.
    private fun num(value: Double) = String.format(Locale.ROOT, "%.3f", value)

    private fun json(batch: List<Report>) = batch.joinToString(",", "{\"timings\":[", "]}") { r ->
        buildString {
            append("{\"id\":\"").append(r.id).append('"')
            append(",\"t0\":").append(num(r.t0))
            append(",\"t3\":").append(num(r.t3))
            append(",\"headers_only\":true")
            r.offsetMs?.let { append(",\"offset_ms\":").append(num(it)) }
            r.calcMs?.let { append(",\"calc_ms\":").append(num(it)) }
            append('}')
        }
    }

    private fun wallNow() = System.currentTimeMillis().toDouble()
}

package de.railtime.location

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.SystemClock
import org.json.JSONObject
import java.time.Instant
import java.util.UUID
import java.util.concurrent.Executors

/** Explicitly started, non-restarting service. It never stores a coordinate on disk. */
class LocationService : Service(), LocationListener {
    private val handler = Handler(Looper.getMainLooper())
    private val executor = Executors.newSingleThreadExecutor()
    private val policy = PermitPolicy()
    private lateinit var manager: LocationManager
    private var api: ApiClient? = null
    private var permitId: String? = null
    @Volatile private var active = false
    private var generation = 0
    private var requestRunning = false
    private var lastRenewal = 0L
    private var lastFixSeen = 0L
    private var pendingQuality: String? = null

    override fun onCreate() {
        super.onCreate()
        manager = getSystemService(LocationManager::class.java)
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CHANNEL, "Arbeitszeit-Standort", NotificationManager.IMPORTANCE_LOW))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == STOP) { stopTracking("Standortdienst beendet", notifyServer = true); return START_NOT_STICKY }
        if (intent?.action != START || active) return START_NOT_STICKY
        val code = intent.getStringExtra("code")?.takeIf { it.matches(Regex("[A-Za-z0-9_-]{16,256}")) }
        val registration = CredentialStore(this).read()
        if (registration == null || code == null ||
            checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            stopTracking("Einrichtung oder präzise Standortberechtigung fehlt", false)
            return START_NOT_STICKY
        }
        api = ApiClient(registration.base, registration.token)
        active = true
        running = true
        generation++
        val notification = notification("Serverfreigabe wird geprüft")
        if (Build.VERSION.SDK_INT >= 29) startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        else startForeground(NOTIFICATION_ID, notification)
        updateStatus("Serverfreigabe wird geprüft")
        requestPermit("start", JSONObject().put("code", code), initial = true)
        handler.post(watchdog)
        return START_NOT_STICKY
    }

    private val watchdog = object : Runnable {
        override fun run() {
            if (!active) return
            if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED ||
                (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED)) {
                stopTracking("Präzise Standortberechtigung oder Benachrichtigungen entzogen", true)
                return
            }
            val now = SystemClock.elapsedRealtime()
            if (permitId != null && !policy.valid(now)) {
                stopTracking("Erfassung unterbrochen: Serverfreigabe abgelaufen", false)
                return
            }
            if (permitId != null && now - lastRenewal >= 60_000L && !requestRunning) {
                requestPermit("permit", JSONObject(), initial = false)
            }
            if (permitId != null && policy.sampleDue(now)) {
                if (now - lastFixSeen >= 30_000L) pendingQuality = "unavailable"
                pendingQuality?.let { reportQuality(it) }
            }
            handler.postDelayed(this, 1_000L)
        }
    }

    private fun requestPermit(action: String, payload: JSONObject, initial: Boolean) {
        val client = api ?: return
        val requestGeneration = generation
        val sentAt = SystemClock.elapsedRealtime()
        requestRunning = true
        executor.execute {
            val response = runCatching { client.post(action, payload) }
            val receivedAt = SystemClock.elapsedRealtime()
            handler.post {
                if (!active || requestGeneration != generation) return@post
                requestRunning = false
                if (!initial && !policy.valid(receivedAt)) {
                    stopTracking("Erfassung unterbrochen: Serverfreigabe abgelaufen", false)
                    return@post
                }
                response.fold(onSuccess = { json ->
                    runCatching {
                        policy.authorize(
                            Instant.parse(json.getString("server_time")).toEpochMilli(),
                            Instant.parse(json.getString("expires_at")).toEpochMilli(),
                            Instant.parse(json.getString("next_sample_at")).toEpochMilli(),
                            receivedAt, receivedAt - sentAt)
                        require(policy.valid(receivedAt))
                        permitId = json.getString("permit_id")
                        lastRenewal = receivedAt
                        if (initial) {
                            lastFixSeen = receivedAt
                            pendingQuality = null
                            beginLocationUpdates()
                        }
                        if (pendingQuality == null) updateStatus("Aktiv – Meldung alle 15 Minuten während der Arbeitszeit")
                    }.onFailure { stopTracking("Erfassung unterbrochen: ungültige Serverfreigabe", false) }
                }, onFailure = { error ->
                    if (error is ApiFailure && error.status == 401) CredentialStore(this).clear()
                    stopTracking("Erfassung unterbrochen: Verbindung oder Freigabe fehlt", false)
                })
            }
        }
    }

    private fun beginLocationUpdates() {
        if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            stopTracking("Präzise Standortberechtigung fehlt", true)
            return
        }
        var available = false
        for (provider in listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)) {
            if (!manager.isProviderEnabled(provider)) continue
            available = true
            try { manager.requestLocationUpdates(provider, 30_000L, 0f, this, Looper.getMainLooper()) }
            catch (_: SecurityException) { stopTracking("Standortberechtigung entzogen", true); return }
        }
        if (!available) stopTracking("Standortdienste sind ausgeschaltet", true)
    }

    override fun onLocationChanged(location: Location) {
        val now = SystemClock.elapsedRealtime()
        if (!active) return
        if (!policy.valid(now)) { stopTracking("Serverfreigabe abgelaufen", false); return }
        lastFixSeen = now
        if (requestRunning || !policy.sampleDue(now)) return
        val fixMs = location.elapsedRealtimeNanos / 1_000_000L
        if (!location.hasAccuracy() || !policy.acceptsFix(now, fixMs, location.accuracy.toDouble(), location.latitude, location.longitude)) {
            pendingQuality = if (location.hasAccuracy() && location.accuracy.isFinite() && location.accuracy > 50f) "inaccurate" else "unavailable"
            updateStatus("Messung ungenau oder veraltet – noch kein Punkt gespeichert")
            reportQuality(pendingQuality!!)
            return
        }
        val client = api ?: return
        val id = permitId ?: return
        val requestGeneration = generation
        val payload = JSONObject().put("permit_id", id).put("event_id", UUID.randomUUID().toString())
            .put("latitude", location.latitude).put("longitude", location.longitude)
            .put("accuracy_m", location.accuracy.toDouble())
            .put("measured_at", Instant.ofEpochMilli(policy.serverNow(now) - (now - fixMs)).toString())
        requestRunning = true
        executor.execute {
            val response = runCatching {
                check(active && requestGeneration == generation && policy.valid(SystemClock.elapsedRealtime()))
                client.post("points", payload)
            }
            handler.post {
                if (!active || requestGeneration != generation) return@post
                requestRunning = false
                if (!policy.valid(SystemClock.elapsedRealtime())) {
                    stopTracking("Erfassung unterbrochen: Serverfreigabe abgelaufen", false)
                    return@post
                }
                response.fold(onSuccess = { json ->
                    runCatching {
                        require(json.getBoolean("accepted"))
                        policy.acknowledge(Instant.parse(json.getString("next_sample_at")).toEpochMilli())
                        pendingQuality = null
                        updateStatus("Standort gemeldet – nächste Meldung in etwa 15 Minuten")
                    }.onFailure { stopTracking("Erfassung unterbrochen: Antwort konnte nicht bestätigt werden", false) }
                }, onFailure = { error ->
                    if (error is ApiFailure && error.status == 401) CredentialStore(this).clear()
                    stopTracking("Erfassung unterbrochen: Standort nicht bestätigt", false)
                })
            }
        }
    }

    private fun reportQuality(quality: String) {
        val now = SystemClock.elapsedRealtime()
        if (!active || requestRunning || !policy.qualityReportDue(now)) return
        val client = api ?: return
        val id = permitId ?: return
        val requestGeneration = generation
        // Metadata only: inaccurate/stale coordinates are never copied into this request.
        val payload = JSONObject().put("permit_id", id).put("quality_status", quality)
        policy.markQualityReport(now)
        requestRunning = true
        executor.execute {
            val response = runCatching {
                check(active && requestGeneration == generation && policy.valid(SystemClock.elapsedRealtime()))
                client.post("quality", payload)
            }
            handler.post {
                if (!active || requestGeneration != generation) return@post
                requestRunning = false
                val receivedAt = SystemClock.elapsedRealtime()
                if (!policy.valid(receivedAt)) {
                    stopTracking("Erfassung unterbrochen: Serverfreigabe abgelaufen", false)
                    return@post
                }
                policy.markQualityReport(receivedAt)
                response.fold(onSuccess = { json ->
                    runCatching { json.getBoolean("reported") }
                        .onFailure { stopTracking("Erfassung unterbrochen: Qualitätsmeldung nicht bestätigt", false) }
                }, onFailure = { error ->
                    if (error is ApiFailure && error.status == 429) return@fold
                    if (error is ApiFailure && error.status == 401) CredentialStore(this).clear()
                    stopTracking("Erfassung unterbrochen: Qualitätsmeldung nicht bestätigt", false)
                })
            }
        }
    }

    override fun onProviderDisabled(provider: String) {
        if (!manager.isProviderEnabled(LocationManager.GPS_PROVIDER) &&
            !manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) stopTracking("Standortdienste ausgeschaltet", true)
    }

    // LocationListener had abstract callbacks before Android 11; keep Android 8–10 safe.
    override fun onProviderEnabled(provider: String) = Unit
    @Deprecated("Legacy LocationListener callback")
    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

    private fun updateStatus(text: String) {
        getSharedPreferences("location_status", MODE_PRIVATE).edit().putString("text", text).putBoolean("active", active).apply()
        if (active) getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification(text))
    }

    private fun notification(text: String): Notification {
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val stop = PendingIntent.getService(this, 1, Intent(this, LocationService::class.java).setAction(STOP), PendingIntent.FLAG_IMMUTABLE)
        return Notification.Builder(this, CHANNEL).setSmallIcon(R.drawable.ic_location)
            .setContentTitle("RailTime Standortdienst").setContentText(text).setContentIntent(open)
            .setOngoing(true).addAction(Notification.Action.Builder(null, "Beenden", stop).build()).build()
    }

    private fun stopTracking(text: String, notifyServer: Boolean) {
        val client = api
        active = false
        running = false
        generation++
        policy.invalidate()
        permitId = null
        handler.removeCallbacks(watchdog)
        runCatching { manager.removeUpdates(this) }
        updateStatus(text)
        if (notifyServer && client != null) executor.execute { runCatching { client.post("stop") } }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        active = false
        running = false
        policy.invalidate()
        generation++
        handler.removeCallbacksAndMessages(null)
        runCatching { manager.removeUpdates(this) }
        // Complete an already queued stop request; no location queue exists.
        executor.shutdown()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
    companion object {
        @Volatile var running = false
            private set
        const val START = "de.railtime.location.START"
        const val STOP = "de.railtime.location.STOP"
        private const val CHANNEL = "employee_location"
        private const val NOTIFICATION_ID = 15
    }
}

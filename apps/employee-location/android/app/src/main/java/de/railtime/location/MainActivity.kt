package de.railtime.location

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import org.json.JSONObject
import java.util.UUID
import java.util.concurrent.Executors

class MainActivity : Activity() {
    private val handler = Handler(Looper.getMainLooper())
    private val executor = Executors.newSingleThreadExecutor()
    private lateinit var status: TextView
    private lateinit var baseInput: EditText
    private lateinit var registrationInput: EditText
    private lateinit var handoffInput: EditText
    private lateinit var pairButton: Button
    private lateinit var startButton: Button
    private var handoffCode: String? = null
    private var visible = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val container = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(28), dp(24), dp(28))
        }
        fun label(text: String, size: Float = 16f) = TextView(this).apply { this.text = text; textSize = size; setPadding(0, dp(8), 0, dp(8)); container.addView(this) }
        label("RailTime Standortdienst", 24f)
        label("Die Mitarbeiteroberfläche und die Zustimmung für die aktive Anmeldung bleiben in RailTime. Dieser Dienst meldet nur während freigegebener Arbeitszeit alle 15 Minuten einen präzisen Punkt. Kein Offline-Verlauf und kein automatischer Neustart.")
        status = label("Dienst nicht gestartet")
        baseInput = EditText(this).apply {
            hint = getString(R.string.ui_text_1)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
            container.addView(this)
        }
        registrationInput = EditText(this).apply {
            hint = getString(R.string.ui_text_2)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            isSaveEnabled = false
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
            container.addView(this)
        }
        pairButton = Button(this).apply { text = getString(R.string.ui_text_3); setOnClickListener { register() }; container.addView(this) }
        handoffInput = EditText(this).apply {
            hint = getString(R.string.ui_text_4)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            isSaveEnabled = false
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
            container.addView(this)
        }
        startButton = Button(this).apply { text = getString(R.string.ui_text_5); setOnClickListener { begin() }; container.addView(this) }
        Button(this).apply {
            text = getString(R.string.ui_text_6)
            setOnClickListener { startService(Intent(this@MainActivity, LocationService::class.java).setAction(LocationService.STOP)) }
            container.addView(this)
        }
        Button(this).apply {
            text = getString(R.string.ui_text_7)
            setOnClickListener { CredentialStore(this@MainActivity).read()?.let { openWeb(it.base + "/mein-arbeitstag") } }
            container.addView(this)
        }
        Button(this).apply {
            text = getString(R.string.ui_text_8)
            setOnClickListener {
                startService(Intent(this@MainActivity, LocationService::class.java).setAction(LocationService.STOP))
                CredentialStore(this@MainActivity).clear()
                handoffCode = null
                baseInput.isEnabled = true
                pairButton.isEnabled = true
                status.text = getString(R.string.ui_text_9)
            }
            container.addView(this)
        }
        setContentView(ScrollView(this).apply { addView(container) })
        CredentialStore(this).read()?.let { baseInput.setText(it.base); baseInput.isEnabled = false; pairButton.isEnabled = false }
        readHandoff(intent)
    }

    override fun onNewIntent(intent: Intent) { super.onNewIntent(intent); setIntent(intent); readHandoff(intent) }
    override fun onResume() { super.onResume(); visible = true; handler.post(statusPoll) }
    override fun onPause() { visible = false; handler.removeCallbacks(statusPoll); super.onPause() }
    override fun onDestroy() { handler.removeCallbacksAndMessages(null); executor.shutdown(); super.onDestroy() }

    private val statusPoll = object : Runnable {
        override fun run() {
            if (!visible) return
            val preferences = getSharedPreferences("location_status", MODE_PRIVATE)
            val text = if (preferences.getBoolean("active", false) && !LocationService.running)
                getString(R.string.service_interrupted) else preferences.getString("text", null)
            if (text != null && handoffCode == null) status.text = text
            handler.postDelayed(this, 2_000L)
        }
    }

    private fun readHandoff(intent: Intent?) {
        val uri = intent?.data ?: return
        // Clear sensitive fragment from the Activity's retained Intent immediately.
        intent.data = null
        val registration = CredentialStore(this).read() ?: run { status.text = getString(R.string.ui_text_10); return }
        val code = HandoffLinks.readCode(uri.toString(), registration.base)
        if (code == null) { status.text = getString(R.string.invalid_start_link); return }
        handoffCode = code
        status.text = getString(R.string.ui_text_14)
    }

    private fun register() {
        val base = baseInput.text.toString().trim().trimEnd('/')
        val code = registrationInput.text.toString().trim()
        if (runCatching { ApiClient.validateBase(base) }.isFailure || code.isBlank()) { status.text = getString(R.string.ui_text_15); return }
        pairButton.isEnabled = false
        registrationInput.text.clear()
        status.text = getString(R.string.ui_text_16)
        val installation = UUID.randomUUID().toString()
        executor.execute {
            val response = runCatching { ApiClient(base).post("register", JSONObject().put("code", code).put("installation_id", installation).put("platform", "android")) }
            handler.post {
                if (isFinishing || isDestroyed) return@post
                response.fold(onSuccess = { json ->
                    runCatching { CredentialStore(this).save(Registration(base, json.getString("token"), json.getString("installation_id"))) }
                        .onSuccess { baseInput.isEnabled = false; status.text = getString(R.string.ui_text_17) }
                        .onFailure { pairButton.isEnabled = true; status.text = getString(R.string.ui_text_18) }
                }, onFailure = { pairButton.isEnabled = true; status.text = getString(R.string.ui_text_19) })
            }
        }
    }

    private fun begin() {
        if (CredentialStore(this).read() == null) { status.text = getString(R.string.ui_text_20); return }
        if (handoffCode == null) handoffCode = handoffInput.text.toString().trim().takeIf { it.matches(Regex("[A-Za-z0-9_-]{16,256}")) }
        if (handoffCode == null) { status.text = getString(R.string.ui_text_21); return }
        val required = mutableListOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION)
        if (Build.VERSION.SDK_INT >= 33) required += Manifest.permission.POST_NOTIFICATIONS
        if (required.any { checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }) {
            requestPermissions(required.toTypedArray(), 15)
            return
        }
        val code = handoffCode ?: return
        handoffCode = null
        handoffInput.text.clear()
        runCatching { startForegroundService(Intent(this, LocationService::class.java).setAction(LocationService.START).putExtra("code", code)) }
            .onFailure { status.text = getString(R.string.ui_text_22) }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == 15) status.text = getString(R.string.ui_text_23)
    }

    private fun openWeb(url: String) { runCatching { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } }
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}

package de.railtime.location

import android.content.Context
import android.annotation.SuppressLint
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

data class Registration(val base: String, val token: String, val installationId: String)

// Pairing must not succeed before its encrypted credential is durable; removal must be synchronous.
@SuppressLint("ApplySharedPref")
class CredentialStore(context: Context) {
    private val prefs = context.getSharedPreferences("native_registration", Context.MODE_PRIVATE)
    private val alias = "railtime.employee-location.registration"

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(alias, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
            generateKey()
        }
    }

    fun save(registration: Registration) {
        val plain = JSONObject().put("base", registration.base).put("token", registration.token)
            .put("installation_id", registration.installationId).toString().toByteArray(Charsets.UTF_8)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        val encrypted = cipher.doFinal(plain)
        check(prefs.edit().putString("iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString("payload", Base64.encodeToString(encrypted, Base64.NO_WRAP)).commit())
        plain.fill(0)
    }

    fun read(): Registration? = try {
        val iv = prefs.getString("iv", null)
        val payload = prefs.getString("payload", null)
        if (iv == null || payload == null) null else {
            val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply {
                init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
            }
            val plain = cipher.doFinal(Base64.decode(payload, Base64.NO_WRAP))
            val json = JSONObject(String(plain, Charsets.UTF_8))
            plain.fill(0)
            Registration(json.getString("base"), json.getString("token"), json.getString("installation_id"))
        }
    } catch (_: Exception) { clear(); null }

    fun clear() {
        prefs.edit().clear().commit()
        KeyStore.getInstance("AndroidKeyStore").apply { load(null); deleteEntry(alias) }
    }
}

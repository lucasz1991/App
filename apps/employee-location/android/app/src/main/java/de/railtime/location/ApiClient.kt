package de.railtime.location

import org.json.JSONObject
import java.net.URI
import javax.net.ssl.HttpsURLConnection

class ApiFailure(val status: Int) : Exception("RailTime request failed")

class ApiClient(val base: String, private val token: String? = null) {
    init { validateBase(base) }

    fun post(action: String, payload: JSONObject = JSONObject()): JSONObject {
        require(action in setOf("register", "start", "permit", "points", "quality", "stop"))
        val endpoint = URI(base.trimEnd('/') + "/api/employee-location/" + action).toURL()
        val connection = endpoint.openConnection() as HttpsURLConnection
        connection.requestMethod = "POST"
        connection.instanceFollowRedirects = false
        connection.connectTimeout = 8_000
        connection.readTimeout = 8_000
        connection.doOutput = true
        connection.setRequestProperty("Content-Type", "application/json")
        connection.setRequestProperty("Accept", "application/json")
        token?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
        try {
            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            if (status !in 200..299) throw ApiFailure(status)
            if (status == 204) return JSONObject()
            val body = connection.inputStream.bufferedReader(Charsets.UTF_8).use { it.readText() }
            return JSONObject(body)
        } finally { connection.disconnect() }
    }

    companion object {
        fun validateBase(base: String): URI {
            val uri = URI(base.trim())
            require(uri.scheme == "https" && !uri.host.isNullOrBlank() &&
                uri.userInfo == null && uri.query == null && uri.fragment == null &&
                (uri.path.isNullOrEmpty() || uri.path == "/")) { "Eine HTTPS-Adresse ohne Pfad ist erforderlich." }
            return uri
        }
    }
}

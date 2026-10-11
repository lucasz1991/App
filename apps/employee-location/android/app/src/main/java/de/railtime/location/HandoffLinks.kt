package de.railtime.location

import java.net.URI
import java.net.URLDecoder

object HandoffLinks {
    /** A link can select a start code, never a new origin or credential. */
    fun readCode(link: String, pairedBase: String): String? = runCatching {
        val base = ApiClient.validateBase(pairedBase)
        val uri = URI(link)
        fun httpsPort(value: URI) = if (value.port == -1) 443 else value.port
        require(uri.scheme == "https" && uri.host.equals(base.host, ignoreCase = true) &&
            httpsPort(uri) == httpsPort(base) && uri.userInfo == null &&
            uri.path == "/employee-location/open" && uri.query == null)
        val values = linkedMapOf<String, String>()
        for (item in (uri.rawFragment ?: "").split('&')) {
            val parts = item.split('=', limit = 2)
            require(parts.size == 2)
            val key = URLDecoder.decode(parts[0], "UTF-8")
            val value = URLDecoder.decode(parts[1], "UTF-8")
            require(key in setOf("code", "server") && !values.containsKey(key))
            values[key] = value
        }
        require(values["server"] == null || values["server"]!!.trimEnd('/') == pairedBase.trimEnd('/'))
        values["code"]?.takeIf { it.matches(Regex("[A-Za-z0-9_-]{16,256}")) }
    }.getOrNull()
}

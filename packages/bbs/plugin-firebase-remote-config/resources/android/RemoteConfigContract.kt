package com.bbs.plugins.firebase_remote_config

import org.json.JSONArray
import org.json.JSONObject

/** Constants and validation limits matching the PHP request contract. */
internal object RemoteConfigContract {
    const val MIN_API_LEVEL = 33
    const val MAX_REQUEST_BYTES = 8192
    const val MAX_RESPONSE_BYTES = 16384
    const val JSON_DEPTH = 16
    const val MAX_PARAMETERS = 32
    const val MAX_KEY_BYTES = 128
    const val MAX_STRING_BYTES = 1024
    const val MAX_SAFE_INTEGER = 9007199254740991L
    const val DEFAULT_FETCH_INTERVAL_SECONDS = 43200L
    const val MAX_FETCH_INTERVAL_SECONDS = 604800L
    const val DEFAULT_FETCH_TIMEOUT_SECONDS = 60L
    const val MAX_FETCH_TIMEOUT_SECONDS = 120L

    const val INVALID_METHOD = "INVALID_METHOD"
    const val INVALID_OPTIONS = "INVALID_OPTIONS"
    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_KEY = "INVALID_KEY"
    const val INVALID_TYPE = "INVALID_TYPE"
    const val INVALID_VALUE = "INVALID_VALUE"
    const val INVALID_SETTINGS = "INVALID_SETTINGS"
    const val REQUEST_TOO_LARGE = "REQUEST_TOO_LARGE"
    const val LIMIT_EXCEEDED = "LIMIT_EXCEEDED"
    const val NATIVE_UNAVAILABLE = "NATIVE_UNAVAILABLE"
    const val INVALID_NATIVE_RESPONSE = "INVALID_NATIVE_RESPONSE"
    const val NOT_INITIALIZED = "NOT_INITIALIZED"
    const val DEFAULTS_NOT_READY = "DEFAULTS_NOT_READY"
    const val BUSY = "BUSY"
    const val REQUEST_ID_CONFLICT = "REQUEST_ID_CONFLICT"
    const val FETCH_FAILED = "FETCH_FAILED"
    const val ACTIVATION_FAILED = "ACTIVATION_FAILED"
    const val CONFIGURE_FAILED = "CONFIGURE_FAILED"
    const val DEFAULTS_FAILED = "DEFAULTS_FAILED"
    const val OPERATION_TIMEOUT = "OPERATION_TIMEOUT"
    const val THROTTLED = "THROTTLED"
    const val PROCESS_INTERRUPTED = "PROCESS_INTERRUPTED"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val PERSIST_FAILED = "PERSIST_FAILED"

    val TYPES = setOf("string", "boolean", "integer", "decimal")
    val SOURCES = setOf("remote", "default", "static")
    val FETCH_STATUSES = setOf("no_fetch_yet", "success", "failure", "throttled")
    val OPERATIONS = setOf("Configure", "SetDefaults", "Fetch", "Activate", "FetchAndActivate")
    val METHODS = OPERATIONS + setOf("IsAvailable", "GetValues", "GetStatus", "GetInfo")
    val ERRORS = setOf(INVALID_METHOD, INVALID_OPTIONS, INVALID_REQUEST_ID, INVALID_KEY,
        INVALID_TYPE, INVALID_VALUE, INVALID_SETTINGS, REQUEST_TOO_LARGE, LIMIT_EXCEEDED,
        NATIVE_UNAVAILABLE, INVALID_NATIVE_RESPONSE, NOT_INITIALIZED, DEFAULTS_NOT_READY,
        BUSY, REQUEST_ID_CONFLICT, FETCH_FAILED, ACTIVATION_FAILED, CONFIGURE_FAILED,
        DEFAULTS_FAILED, OPERATION_TIMEOUT, THROTTLED, PROCESS_INTERRUPTED, RESULT_NOT_FOUND,
        PERSIST_FAILED)

    private val ID_PATTERN = Regex("[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}")
    private val KEY_PATTERN = Regex("[A-Za-z_][A-Za-z0-9_]*")

    fun requestId(value: Any?): String? = (value as? String)?.takeIf { ID_PATTERN.matches(it) }
    fun key(value: Any?): String? = (value as? String)?.takeIf {
        it.length <= MAX_KEY_BYTES && KEY_PATTERN.matches(it)
    }

    /** JSONTokener preserves whole numbers as Int/Long and decimal tokens as Double. */
    fun integer(value: Any?): Long? = when (value) {
        is Byte -> value.toLong()
        is Short -> value.toLong()
        is Int -> value.toLong()
        is Long -> value
        else -> null
    }?.takeIf { it in -MAX_SAFE_INTEGER..MAX_SAFE_INTEGER }

    fun decimal(value: Any?): Double? = when (value) {
        is Double -> value.takeIf { it.isFinite() }
        is Byte, is Short, is Int, is Long -> integer(value)?.toDouble()
        else -> null
    }

    fun typedValue(type: String, value: Any?): Any? = when (type) {
        "string" -> (value as? String)?.takeIf { it.length <= MAX_STRING_BYTES && validUnicode(it) && utf8Size(it) <= MAX_STRING_BYTES }
        "boolean" -> value as? Boolean
        "integer" -> integer(value)
        "decimal" -> decimal(value)
        else -> null
    }

    fun validUnicode(value: String): Boolean {
        var index = 0
        while (index < value.length) {
            val character = value[index]
            if (Character.isHighSurrogate(character)) {
                if (index + 1 >= value.length || !Character.isLowSurrogate(value[index + 1])) return false
                index += 2
            } else {
                if (Character.isLowSurrogate(character)) return false
                index++
            }
        }
        return true
    }

    fun utf8Size(value: String): Int = value.toByteArray(Charsets.UTF_8).size

    fun objectMap(value: Any?): Map<String, Any?>? = when (value) {
        is JSONObject -> {
            val result = linkedMapOf<String, Any?>()
            val keys = value.keys()
            while (keys.hasNext()) {
                val key = keys.next()
                val entry = value.get(key)
                result[key] = if (entry === JSONObject.NULL) null else entry
            }
            result
        }
        is Map<*, *> -> {
            if (value.keys.any { it !is String }) null
            else value.entries.associate { it.key as String to if (it.value === JSONObject.NULL) null else it.value }
        }
        else -> null
    }

    /** The router has already parsed JSON. Bound its reconstructed compact payload. */
    fun sizeError(parameters: Map<*, *>): String? = try {
        val normalized = normalize(parameters, 1)
        val encoded = JSONObject(requireNotNull(objectMap(normalized))).toString()
        if (utf8Size(encoded) > MAX_REQUEST_BYTES) REQUEST_TOO_LARGE else null
    } catch (_: Exception) { INVALID_OPTIONS }

    fun boundedResponse(response: Map<String, Any>): Boolean = try {
        utf8Size(JSONObject(response).toString()) <= MAX_RESPONSE_BYTES
    } catch (_: Exception) { false }

    private fun normalize(value: Any?, depth: Int): Any {
        if (value == null || value === JSONObject.NULL) return JSONObject.NULL
        return when (value) {
            is String -> { require(validUnicode(value)); value }
            is Boolean -> value
            is Double -> { require(value.isFinite()); value }
            is Byte, is Short, is Int, is Long -> value
            is JSONObject, is Map<*, *> -> {
                require(depth <= JSON_DEPTH)
                val entries = requireNotNull(objectMap(value))
                entries.mapValues { (key, entry) ->
                    require(validUnicode(key))
                    normalize(entry, depth + 1)
                }
            }
            is JSONArray -> {
                require(depth <= JSON_DEPTH)
                List(value.length()) { index -> normalize(value.get(index), depth + 1) }
            }
            is List<*> -> {
                require(depth <= JSON_DEPTH)
                value.map { normalize(it, depth + 1) }
            }
            else -> throw IllegalArgumentException()
        }
    }
}

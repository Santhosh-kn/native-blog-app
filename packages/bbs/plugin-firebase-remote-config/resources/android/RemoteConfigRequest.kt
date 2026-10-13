package com.bbs.plugins.firebase_remote_config

import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal data class RemoteConfigDefault(val type: String, val value: Any)
internal data class RemoteConfigSettings(val minimumFetchIntervalSeconds: Long, val fetchTimeoutSeconds: Long)

internal data class RemoteConfigRequest(
    val operation: String,
    val id: String? = null,
    val settings: RemoteConfigSettings? = null,
    val defaults: Map<String, RemoteConfigDefault> = emptyMap(),
    val values: Map<String, String> = emptyMap()
) {
    companion object {
        fun parse(operation: String, parameters: Map<*, *>): RemoteConfigRequestParseResult {
            if (operation !in Contract.METHODS) return invalid(Contract.INVALID_METHOD)
            Contract.sizeError(parameters)?.let { return invalid(it) }
            val input = Contract.objectMap(parameters) ?: return invalid(Contract.INVALID_OPTIONS)
            val expected = when (operation) {
                "IsAvailable", "GetInfo" -> emptySet()
                "Configure" -> setOf("id", "minimum_fetch_interval_seconds", "fetch_timeout_seconds")
                "SetDefaults" -> setOf("id", "defaults")
                "GetValues" -> setOf("values")
                else -> setOf("id")
            }
            if (input.keys != expected) return invalid(Contract.INVALID_OPTIONS)
            val id = if ("id" in expected) Contract.requestId(input["id"]) ?: return invalid(Contract.INVALID_REQUEST_ID) else null
            return when (operation) {
                "Configure" -> {
                    val interval = Contract.integer(input["minimum_fetch_interval_seconds"])
                    val timeout = Contract.integer(input["fetch_timeout_seconds"])
                    if (interval == null || interval !in 0..Contract.MAX_FETCH_INTERVAL_SECONDS ||
                        timeout == null || timeout !in 1..Contract.MAX_FETCH_TIMEOUT_SECONDS) return invalid(Contract.INVALID_SETTINGS)
                    valid(RemoteConfigRequest(operation, id, RemoteConfigSettings(interval, timeout)))
                }
                "SetDefaults" -> {
                    val entries = Contract.objectMap(input["defaults"]) ?: return invalid(Contract.INVALID_OPTIONS)
                    if (entries.isEmpty()) return invalid(Contract.INVALID_OPTIONS)
                    if (entries.size > Contract.MAX_PARAMETERS) return invalid(Contract.LIMIT_EXCEEDED)
                    val defaults = linkedMapOf<String, RemoteConfigDefault>()
                    for ((key, raw) in entries) {
                        if (Contract.key(key) == null) return invalid(Contract.INVALID_KEY)
                        val entry = Contract.objectMap(raw) ?: return invalid(Contract.INVALID_OPTIONS)
                        if (entry.keys != setOf("type", "value")) return invalid(Contract.INVALID_OPTIONS)
                        val type = (entry["type"] as? String)?.takeIf { it in Contract.TYPES } ?: return invalid(Contract.INVALID_TYPE)
                        val value = Contract.typedValue(type, entry["value"]) ?: return invalid(Contract.INVALID_VALUE)
                        defaults[key] = RemoteConfigDefault(type, value)
                    }
                    valid(RemoteConfigRequest(operation, id, defaults = defaults.toMap()))
                }
                "GetValues" -> {
                    val entries = Contract.objectMap(input["values"]) ?: return invalid(Contract.INVALID_OPTIONS)
                    if (entries.isEmpty()) return invalid(Contract.INVALID_OPTIONS)
                    if (entries.size > Contract.MAX_PARAMETERS) return invalid(Contract.LIMIT_EXCEEDED)
                    val values = linkedMapOf<String, String>()
                    for ((key, raw) in entries) {
                        if (Contract.key(key) == null) return invalid(Contract.INVALID_KEY)
                        val type = (raw as? String)?.takeIf { it in Contract.TYPES } ?: return invalid(Contract.INVALID_TYPE)
                        values[key] = type
                    }
                    valid(RemoteConfigRequest(operation, values = values.toMap()))
                }
                else -> valid(RemoteConfigRequest(operation, id))
            }
        }

        private fun valid(request: RemoteConfigRequest) = RemoteConfigRequestParseResult.Valid(request)
        private fun invalid(code: String) = RemoteConfigRequestParseResult.Invalid(code)
    }
}

internal sealed interface RemoteConfigRequestParseResult {
    data class Valid(val request: RemoteConfigRequest) : RemoteConfigRequestParseResult
    data class Invalid(val code: String) : RemoteConfigRequestParseResult
}

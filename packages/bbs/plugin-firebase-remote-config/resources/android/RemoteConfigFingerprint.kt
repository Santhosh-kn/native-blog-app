package com.bbs.plugins.firebase_remote_config

import java.io.ByteArrayOutputStream
import java.io.DataOutputStream
import java.security.MessageDigest
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** Canonical, typed mutation identity. Only the digest is persisted, never the input values. */
internal object RemoteConfigFingerprint {
    fun validated(request: RemoteConfigRequest): RemoteConfigRequest {
        require(request.operation in Contract.OPERATIONS && request.values.isEmpty())
        val parameters = linkedMapOf<String, Any?>("id" to request.id)
        when (request.operation) {
            "Configure" -> {
                require(request.defaults.isEmpty())
                val settings = requireNotNull(request.settings)
                parameters["minimum_fetch_interval_seconds"] = settings.minimumFetchIntervalSeconds
                parameters["fetch_timeout_seconds"] = settings.fetchTimeoutSeconds
            }
            "SetDefaults" -> {
                require(request.settings == null)
                parameters["defaults"] = request.defaults.mapValues { (_, entry) ->
                    mapOf("type" to entry.type, "value" to entry.value)
                }
            }
            else -> require(request.settings == null && request.defaults.isEmpty())
        }
        val parsed = RemoteConfigRequest.parse(request.operation, parameters)
        return (parsed as? RemoteConfigRequestParseResult.Valid)?.request
            ?: throw IllegalArgumentException("Invalid mutation request")
    }

    fun of(request: RemoteConfigRequest): String {
        val input = validated(request)
        val bytes = ByteArrayOutputStream()
        DataOutputStream(bytes).use { out ->
            out.writeUTF("FirebaseRemoteConfig:request:v1")
            out.writeUTF(input.operation)
            out.writeUTF(requireNotNull(input.id))
            input.settings?.let {
                out.writeLong(it.minimumFetchIntervalSeconds)
                out.writeLong(it.fetchTimeoutSeconds)
            }
            out.writeInt(input.defaults.size)
            for ((key, entry) in input.defaults.toSortedMap()) {
                out.writeUTF(key)
                out.writeUTF(entry.type)
                when (entry.type) {
                    "string" -> out.writeUTF(entry.value as String)
                    "boolean" -> out.writeBoolean(entry.value as Boolean)
                    "integer" -> out.writeLong(entry.value as Long)
                    "decimal" -> out.writeLong(java.lang.Double.doubleToLongBits(entry.value as Double))
                }
            }
        }
        return MessageDigest.getInstance("SHA-256").digest(bytes.toByteArray())
            .joinToString("") { (it.toInt() and 0xff).toString(16).padStart(2, '0') }
    }
}

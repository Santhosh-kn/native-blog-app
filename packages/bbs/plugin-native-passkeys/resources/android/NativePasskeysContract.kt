package com.bbs.plugins.native_passkeys

import org.json.JSONException
import org.json.JSONObject
import java.nio.charset.StandardCharsets
import java.util.Locale

internal object NativePasskeysContract {

    const val EVENT_CLASS =
        "Bbs\\NativePasskeys\\Events\\NativePasskeysCompleted"

    const val OPERATION_CREATE = "create"
    const val OPERATION_AUTHENTICATE = "authenticate"

    const val STATUS_PENDING = "pending"
    const val STATUS_SUCCEEDED = "succeeded"
    const val STATUS_CANCELLED = "cancelled"
    const val STATUS_FAILED = "failed"
    const val STATUS_NOT_FOUND = "not_found"

    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_OPERATION = "INVALID_OPERATION"
    const val INVALID_REQUEST_JSON = "INVALID_REQUEST_JSON"
    const val REQUEST_JSON_TOO_LARGE = "REQUEST_JSON_TOO_LARGE"
    const val ANDROID_VERSION_UNSUPPORTED =
        "ANDROID_VERSION_UNSUPPORTED"
    const val CREDENTIAL_MANAGER_UNAVAILABLE =
        "CREDENTIAL_MANAGER_UNAVAILABLE"
    const val ACTIVITY_UNAVAILABLE = "ACTIVITY_UNAVAILABLE"
    const val REQUEST_BUSY = "REQUEST_BUSY"
    const val CREATE_FAILED = "CREATE_FAILED"
    const val AUTHENTICATION_FAILED = "AUTHENTICATION_FAILED"
    const val NO_CREDENTIAL = "NO_CREDENTIAL"
    const val PROVIDER_CONFIGURATION_ERROR =
        "PROVIDER_CONFIGURATION_ERROR"
    const val OPERATION_INTERRUPTED = "OPERATION_INTERRUPTED"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val RESULT_NOT_TERMINAL = "RESULT_NOT_TERMINAL"
    const val RESULT_ALREADY_CONSUMED =
        "RESULT_ALREADY_CONSUMED"
    const val RESULT_PERSISTENCE_FAILED =
        "RESULT_PERSISTENCE_FAILED"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    const val MINIMUM_ANDROID_API = 28

    const val MAX_REQUEST_JSON_BYTES = 262_144

    const val MAX_RESPONSE_JSON_BYTES = 1_048_576

    private val uuidPattern = Regex(
        "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{12}$"
    )

    fun normalizeRequestId(value: Any?): String? {
        val candidate =
            (value as? String)?.trim()
                ?: return null

        if (!uuidPattern.matches(candidate)) {
            return null
        }

        return candidate.lowercase(Locale.ROOT)
    }

    fun normalizeOperation(value: Any?): String? {
        return when (value) {
            OPERATION_CREATE -> OPERATION_CREATE
            OPERATION_AUTHENTICATE ->
                OPERATION_AUTHENTICATE
            else -> null
        }
    }

    fun requestJsonError(
        value: Any?,
        operation: String
    ): String? {
        val requestJson =
            value as? String
                ?: return INVALID_REQUEST_JSON

        if (
            requestJson.toByteArray(
                StandardCharsets.UTF_8
            ).size > MAX_REQUEST_JSON_BYTES
        ) {
            return REQUEST_JSON_TOO_LARGE
        }

        if (requestJson.isBlank()) {
            return INVALID_REQUEST_JSON
        }

        val options = try {
            JSONObject(requestJson)
        } catch (_: JSONException) {
            return INVALID_REQUEST_JSON
        } catch (_: RuntimeException) {
            return INVALID_REQUEST_JSON
        }

        val challenge =
            options.opt("challenge") as? String

        if (challenge.isNullOrBlank()) {
            return INVALID_REQUEST_JSON
        }

        if (operation == OPERATION_AUTHENTICATE) {
            return null
        }

        if (operation != OPERATION_CREATE) {
            return INVALID_OPERATION
        }

        val relyingParty =
            options.optJSONObject("rp")
        val user =
            options.optJSONObject("user")
        val algorithms =
            options.optJSONArray("pubKeyCredParams")

        if (
            relyingParty == null ||
            user == null ||
            algorithms == null ||
            algorithms.length() == 0
        ) {
            return INVALID_REQUEST_JSON
        }

        return null
    }

    fun normalizeResponseJson(
        value: Any?
    ): String? {
        val responseJson =
            value as? String
                ?: return null

        if (
            responseJson.isBlank() ||
            responseJson.toByteArray(
                StandardCharsets.UTF_8
            ).size > MAX_RESPONSE_JSON_BYTES
        ) {
            return null
        }

        return try {
            JSONObject(responseJson)
            responseJson
        } catch (_: JSONException) {
            null
        } catch (_: RuntimeException) {
            null
        }
    }

    fun isTerminalStatus(status: String): Boolean {
        return status == STATUS_SUCCEEDED ||
            status == STATUS_CANCELLED ||
            status == STATUS_FAILED
    }

    fun isKnownErrorCode(errorCode: String): Boolean {
        return errorCode in knownErrorCodes
    }

    fun errorMessage(errorCode: String): String {
        return when (errorCode) {
            INVALID_REQUEST_ID ->
                "The request ID must be a valid UUID."

            INVALID_OPERATION ->
                "The requested passkey operation is invalid."

            INVALID_REQUEST_JSON ->
                "Valid server-generated WebAuthn request JSON is required."

            REQUEST_JSON_TOO_LARGE ->
                "The WebAuthn request JSON exceeds the allowed size."

            ANDROID_VERSION_UNSUPPORTED ->
                "Passkeys require Android API level 28 or newer."

            CREDENTIAL_MANAGER_UNAVAILABLE ->
                "Android Credential Manager is unavailable."

            ACTIVITY_UNAVAILABLE ->
                "The passkey request cannot access an Android activity."

            REQUEST_BUSY ->
                "Another passkey request is already active."

            CREATE_FAILED ->
                "Passkey registration did not complete."

            AUTHENTICATION_FAILED ->
                "Passkey authentication did not complete."

            NO_CREDENTIAL ->
                "No matching passkey credential was found."

            PROVIDER_CONFIGURATION_ERROR ->
                "No compatible credential provider is configured."

            OPERATION_INTERRUPTED ->
                "The passkey operation was interrupted."

            RESULT_NOT_FOUND ->
                "No saved passkey result was found."

            RESULT_NOT_TERMINAL ->
                "The passkey request has not completed."

            RESULT_ALREADY_CONSUMED ->
                "The passkey result has already been consumed."

            RESULT_PERSISTENCE_FAILED ->
                "The passkey result could not be safely persisted."

            else ->
                "The passkey operation could not be completed."
        }
    }

    private val knownErrorCodes = setOf(
        INVALID_REQUEST_ID,
        INVALID_OPERATION,
        INVALID_REQUEST_JSON,
        REQUEST_JSON_TOO_LARGE,
        ANDROID_VERSION_UNSUPPORTED,
        CREDENTIAL_MANAGER_UNAVAILABLE,
        ACTIVITY_UNAVAILABLE,
        REQUEST_BUSY,
        CREATE_FAILED,
        AUTHENTICATION_FAILED,
        NO_CREDENTIAL,
        PROVIDER_CONFIGURATION_ERROR,
        OPERATION_INTERRUPTED,
        RESULT_NOT_FOUND,
        RESULT_NOT_TERMINAL,
        RESULT_ALREADY_CONSUMED,
        RESULT_PERSISTENCE_FAILED,
        UNKNOWN_ERROR
    )
}

package com.bbs.plugins.native_calendar

import org.json.JSONObject
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

/**
 * Request metadata only. Event details are deliberately absent.
 */
internal class NativeCalendarResult private constructor(
    val id: String,
    val operation: String?,
    val target: String?,
    val status: String,
    val accepted: Boolean,
    val success: Boolean,
    val errorCode: String?,
    val createdAtMs: Long?,
    val completedAtMs: Long?
) {

    init {
        require(hasValidState()) {
            "Native Calendar result metadata is invalid."
        }
    }

    val errorMessage: String?
        get() = errorCode?.let { Contract.errorMessage(it) }

    val isTerminal: Boolean
        get() = accepted && (
            status == Contract.STATUS_LAUNCHED ||
                status == Contract.STATUS_FAILED ||
                status == Contract.STATUS_UNKNOWN
            )

    override fun toString(): String =
        "NativeCalendarResult(redacted)"

    fun toBridgeMap(): Map<String, Any> = linkedMapOf(
        "id" to id,
        "operation" to (operation ?: JSONObject.NULL),
        "target" to (target ?: JSONObject.NULL),
        "status" to status,
        "accepted" to accepted,
        "success" to success,
        "errorCode" to (errorCode ?: JSONObject.NULL),
        "errorMessage" to (errorMessage ?: JSONObject.NULL),
        "createdAtMs" to (createdAtMs ?: JSONObject.NULL),
        "completedAtMs" to (completedAtMs ?: JSONObject.NULL)
    )

    fun toStoredJson(): JSONObject {
        require(accepted && Contract.isBinding(operation, target)) {
            "Native Calendar result cannot be stored."
        }

        return JSONObject(toBridgeMap())
    }

    fun toEventJson(): JSONObject {
        require(isTerminal) {
            "Native Calendar completion metadata is invalid."
        }

        return JSONObject(toBridgeMap())
    }

    fun launched(completedAtMs: Long): NativeCalendarResult =
        finish(
            status = Contract.STATUS_LAUNCHED,
            success = true,
            errorCode = null,
            completedAtMs = completedAtMs
        )

    fun failed(
        errorCode: Any?,
        completedAtMs: Long
    ): NativeCalendarResult {
        val code = Contract.canonicalErrorCode(errorCode)

        require(code != Contract.RESULT_NOT_FOUND) {
            "Native Calendar completion metadata is invalid."
        }

        // An interrupted launch has an unknown outcome.
        if (code == Contract.INTERRUPTED) {
            return interrupted(completedAtMs)
        }

        return finish(
            status = Contract.STATUS_FAILED,
            success = false,
            errorCode = code,
            completedAtMs = completedAtMs
        )
    }

    fun interrupted(completedAtMs: Long): NativeCalendarResult =
        finish(
            status = Contract.STATUS_UNKNOWN,
            success = false,
            errorCode = Contract.INTERRUPTED,
            completedAtMs = completedAtMs
        )

    private fun finish(
        status: String,
        success: Boolean,
        errorCode: String?,
        completedAtMs: Long
    ): NativeCalendarResult {
        require(accepted && this.status == Contract.STATUS_PENDING) {
            "Native Calendar request cannot be completed."
        }

        return NativeCalendarResult(
            id = id,
            operation = operation,
            target = target,
            status = status,
            accepted = true,
            success = success,
            errorCode = errorCode,
            createdAtMs = createdAtMs,
            completedAtMs = completedAtMs
        )
    }

    private fun hasValidState(): Boolean {
        if (Contract.requestId(id) == null) {
            return false
        }

        val bound = Contract.isBinding(operation, target)
        val lookup = operation == null && target == null

        if (!bound && !lookup) {
            return false
        }

        val untimed = createdAtMs == null && completedAtMs == null
        val validCreated = Contract.epochMilliseconds(createdAtMs) != null

        val validTerminalTimes =
            createdAtMs != null &&
                completedAtMs != null &&
                Contract.epochMilliseconds(createdAtMs) != null &&
                Contract.epochMilliseconds(completedAtMs) != null &&
                completedAtMs >= createdAtMs

        return when (status) {
            Contract.STATUS_PENDING ->
                bound &&
                    accepted &&
                    !success &&
                    errorCode == null &&
                    validCreated &&
                    completedAtMs == null

            Contract.STATUS_LAUNCHED ->
                bound &&
                    accepted &&
                    success &&
                    errorCode == null &&
                    validTerminalTimes

            Contract.STATUS_FAILED ->
                !success &&
                    Contract.isKnownErrorCode(errorCode) &&
                    errorCode != Contract.RESULT_NOT_FOUND &&
                    if (accepted) {
                        bound && validTerminalTimes
                    } else {
                        untimed && (bound || lookup)
                    }

            Contract.STATUS_UNKNOWN ->
                bound &&
                    accepted &&
                    !success &&
                    errorCode == Contract.INTERRUPTED &&
                    validTerminalTimes

            Contract.STATUS_NOT_FOUND ->
                lookup &&
                    !accepted &&
                    !success &&
                    errorCode == Contract.RESULT_NOT_FOUND &&
                    untimed

            else -> false
        }
    }

    companion object {

        private val STORED_KEYS = setOf(
            "id", "operation", "target", "status", "accepted", "success",
            "errorCode", "errorMessage", "createdAtMs", "completedAtMs"
        )

        fun pending(
            id: String,
            operation: String,
            target: String,
            createdAtMs: Long
        ): NativeCalendarResult =
            NativeCalendarResult(
                id = id,
                operation = operation,
                target = target,
                status = Contract.STATUS_PENDING,
                accepted = true,
                success = false,
                errorCode = null,
                createdAtMs = createdAtMs,
                completedAtMs = null
            )

        fun failure(
            id: Any?,
            errorCode: Any?,
            operation: String? = null,
            target: String? = null
        ): NativeCalendarResult {
            val safeId = Contract.requestId(id) ?: Contract.FALLBACK_REQUEST_ID
            val code = Contract.canonicalErrorCode(errorCode)

            if (code == Contract.RESULT_NOT_FOUND) {
                return notFound(safeId)
            }

            val safeOperation: String?
            val safeTarget: String?

            when {
                operation == Contract.CREATE_EVENT -> {
                    safeOperation = Contract.CREATE_EVENT
                    safeTarget = Contract.TARGET_EDITOR
                }

                operation == Contract.OPEN &&
                    (target == Contract.TARGET_DATE || target == Contract.TARGET_EVENT) -> {
                    safeOperation = Contract.OPEN
                    safeTarget = target
                }

                else -> {
                    safeOperation = null
                    safeTarget = null
                }
            }

            return NativeCalendarResult(
                id = safeId,
                operation = safeOperation,
                target = safeTarget,
                status = Contract.STATUS_FAILED,
                accepted = false,
                success = false,
                errorCode = code,
                createdAtMs = null,
                completedAtMs = null
            )
        }

        fun notFound(id: Any?): NativeCalendarResult =
            NativeCalendarResult(
                id = Contract.requestId(id) ?: Contract.FALLBACK_REQUEST_ID,
                operation = null,
                target = null,
                status = Contract.STATUS_NOT_FOUND,
                accepted = false,
                success = false,
                errorCode = Contract.RESULT_NOT_FOUND,
                createdAtMs = null,
                completedAtMs = null
            )

        /**
         * Only accepted requests belong in private persistent state.
         * Reject extra fields, invalid types, and noncanonical messages.
         */
        fun fromStoredJson(json: JSONObject): NativeCalendarResult? {
            return try {
                if (json.length() != STORED_KEYS.size) {
                    return null
                }

                val keys = json.keys()

                while (keys.hasNext()) {
                    if (keys.next() !in STORED_KEYS) {
                        return null
                    }
                }

                val id = Contract.requestId(json.get("id")) ?: return null
                val status = json.get("status") as? String ?: return null
                val accepted = json.get("accepted") as? Boolean ?: return null
                val success = json.get("success") as? Boolean ?: return null

                if (!accepted) {
                    return null
                }

                val operationValue = json.get("operation")
                val targetValue = json.get("target")
                val errorValue = json.get("errorCode")

                if (
                    operationValue !== JSONObject.NULL && operationValue !is String ||
                    targetValue !== JSONObject.NULL && targetValue !is String ||
                    errorValue !== JSONObject.NULL && errorValue !is String
                ) {
                    return null
                }

                val operation = operationValue as? String
                val target = targetValue as? String
                val errorCode = errorValue as? String

                val createdValue = json.get("createdAtMs")
                val completedValue = json.get("completedAtMs")

                val created = if (createdValue === JSONObject.NULL) {
                    null
                } else {
                    Contract.epochMilliseconds(createdValue) ?: return null
                }

                val completed = if (completedValue === JSONObject.NULL) {
                    null
                } else {
                    Contract.epochMilliseconds(completedValue) ?: return null
                }

                val result = NativeCalendarResult(
                    id = id,
                    operation = operation,
                    target = target,
                    status = status,
                    accepted = accepted,
                    success = success,
                    errorCode = errorCode,
                    createdAtMs = created,
                    completedAtMs = completed
                )

                val messageValue = json.get("errorMessage")

                if (result.errorMessage == null) {
                    if (messageValue !== JSONObject.NULL) {
                        return null
                    }
                } else if (messageValue !is String || messageValue != result.errorMessage) {
                    return null
                }

                if (
                    json.toString().toByteArray(Charsets.UTF_8).size >
                    Contract.MAX_RESULT_BYTES
                ) {
                    return null
                }

                result
            } catch (_: Exception) {
                null
            }
        }
    }
}

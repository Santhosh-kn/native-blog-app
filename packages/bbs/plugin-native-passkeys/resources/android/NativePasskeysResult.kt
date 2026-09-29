package com.bbs.plugins.native_passkeys

import org.json.JSONObject

internal data class NativePasskeysResult(
    val id: String,
    val operation: String?,
    val status: String,
    val success: Boolean,
    val cancelled: Boolean,
    val consumed: Boolean,
    val responseJson: String? = null,
    val errorCode: String? = null,
    val completedAt: Long? = null
) {
    val errorMessage: String?
        get() = errorCode?.let(
            NativePasskeysContract::errorMessage
        )

    fun toBridgeMap(
        includeResponseJson: Boolean = false
    ): Map<String, Any> {
        return buildMap {
            put("id", id)
            operation?.let {
                put("operation", it)
            }
            put("status", status)
            put("success", success)
            put("cancelled", cancelled)
            put("consumed", consumed)

            if (includeResponseJson) {
                responseJson?.let {
                    put("responseJson", it)
                }
            }

            errorCode?.let {
                put("errorCode", it)
            }

            errorMessage?.let {
                put("errorMessage", it)
            }
        }
    }

    fun toEventJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put(
                "operation",
                operation ?: JSONObject.NULL
            )
            put("status", status)
            put("success", success)
            put("cancelled", cancelled)
            put("consumed", consumed)
            put(
                "errorCode",
                errorCode ?: JSONObject.NULL
            )
            put(
                "errorMessage",
                errorMessage ?: JSONObject.NULL
            )
        }
    }

    fun toStoredJson(): JSONObject {
        return toEventJson().apply {
            put(
                "responseJson",
                responseJson ?: JSONObject.NULL
            )
            put(
                "completedAt",
                completedAt ?: JSONObject.NULL
            )
        }
    }

    companion object {

        fun pending(
            request: NativePasskeysRequest
        ): NativePasskeysResult {
            return NativePasskeysResult(
                id = request.id,
                operation = request.operation,
                status =
                    NativePasskeysContract.STATUS_PENDING,
                success = false,
                cancelled = false,
                consumed = false
            )
        }

        fun succeeded(
            id: String,
            operation: String,
            responseJson: String
        ): NativePasskeysResult {
            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_SUCCEEDED,
                success = true,
                cancelled = false,
                consumed = false,
                responseJson = responseJson,
                completedAt = System.currentTimeMillis()
            )
        }

        fun cancelled(
            id: String,
            operation: String
        ): NativePasskeysResult {
            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_CANCELLED,
                success = false,
                cancelled = true,
                consumed = false,
                completedAt = System.currentTimeMillis()
            )
        }

        fun failed(
            id: String,
            operation: String?,
            errorCode: String,
            consumed: Boolean = false
        ): NativePasskeysResult {
            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_FAILED,
                success = false,
                cancelled = false,
                consumed = consumed,
                errorCode = errorCode,
                completedAt = System.currentTimeMillis()
            )
        }

        fun notFound(
            id: String
        ): NativePasskeysResult {
            return NativePasskeysResult(
                id = id,
                operation = null,
                status =
                    NativePasskeysContract.STATUS_NOT_FOUND,
                success = false,
                cancelled = false,
                consumed = false,
                errorCode =
                    NativePasskeysContract.RESULT_NOT_FOUND,
                completedAt = System.currentTimeMillis()
            )
        }

        fun fromStoredJson(
            json: JSONObject
        ): NativePasskeysResult? {
            val id =
                NativePasskeysContract.normalizeRequestId(
                    json.opt("id")
                ) ?: return null

            val status =
                json.opt("status") as? String
                    ?: return null

            val success =
                booleanValue(json, "success")
                    ?: return null

            val cancelled =
                booleanValue(json, "cancelled")
                    ?: return null

            val consumed =
                booleanValue(json, "consumed")
                    ?: return null

            val operation =
                nullableOperation(json)

            return when (status) {
                NativePasskeysContract.STATUS_PENDING ->
                    restorePending(
                        id = id,
                        operation = operation,
                        success = success,
                        cancelled = cancelled,
                        consumed = consumed,
                        json = json
                    )

                NativePasskeysContract.STATUS_SUCCEEDED ->
                    restoreSucceeded(
                        id = id,
                        operation = operation,
                        success = success,
                        cancelled = cancelled,
                        consumed = consumed,
                        json = json
                    )

                NativePasskeysContract.STATUS_CANCELLED ->
                    restoreCancelled(
                        id = id,
                        operation = operation,
                        success = success,
                        cancelled = cancelled,
                        consumed = consumed,
                        json = json
                    )

                NativePasskeysContract.STATUS_FAILED ->
                    restoreFailed(
                        id = id,
                        operation = operation,
                        success = success,
                        cancelled = cancelled,
                        consumed = consumed,
                        json = json
                    )

                else -> null
            }
        }

        private fun restorePending(
            id: String,
            operation: String?,
            success: Boolean,
            cancelled: Boolean,
            consumed: Boolean,
            json: JSONObject
        ): NativePasskeysResult? {
            if (
                operation == null ||
                success ||
                cancelled ||
                consumed ||
                nonNullValue(json, "responseJson") != null ||
                nonNullValue(json, "completedAt") != null
            ) {
                return null
            }

            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_PENDING,
                success = false,
                cancelled = false,
                consumed = false
            )
        }

        private fun restoreSucceeded(
            id: String,
            operation: String?,
            success: Boolean,
            cancelled: Boolean,
            consumed: Boolean,
            json: JSONObject
        ): NativePasskeysResult? {
            if (
                operation == null ||
                !success ||
                cancelled
            ) {
                return null
            }

            val completedAt =
                positiveLong(json, "completedAt")
                    ?: return null

            val storedResponse =
                nonNullValue(
                    json,
                    "responseJson"
                )

            val responseJson =
                if (consumed) {
                    if (storedResponse != null) {
                        return null
                    }

                    null
                } else {
                    NativePasskeysContract
                        .normalizeResponseJson(
                            storedResponse
                        )
                        ?: return null
                }

            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_SUCCEEDED,
                success = true,
                cancelled = false,
                consumed = consumed,
                responseJson = responseJson,
                completedAt = completedAt
            )
        }

        private fun restoreCancelled(
            id: String,
            operation: String?,
            success: Boolean,
            cancelled: Boolean,
            consumed: Boolean,
            json: JSONObject
        ): NativePasskeysResult? {
            if (
                operation == null ||
                success ||
                !cancelled ||
                nonNullValue(json, "responseJson") != null
            ) {
                return null
            }

            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_CANCELLED,
                success = false,
                cancelled = true,
                consumed = consumed,
                completedAt =
                    positiveLong(json, "completedAt")
                        ?: return null
            )
        }

        private fun restoreFailed(
            id: String,
            operation: String?,
            success: Boolean,
            cancelled: Boolean,
            consumed: Boolean,
            json: JSONObject
        ): NativePasskeysResult? {
            if (
                success ||
                cancelled ||
                nonNullValue(json, "responseJson") != null
            ) {
                return null
            }

            val errorCode =
                nonNullValue(
                    json,
                    "errorCode"
                ) as? String
                    ?: return null

            if (
                !NativePasskeysContract
                    .isKnownErrorCode(errorCode)
            ) {
                return null
            }

            return NativePasskeysResult(
                id = id,
                operation = operation,
                status =
                    NativePasskeysContract.STATUS_FAILED,
                success = false,
                cancelled = false,
                consumed = consumed,
                errorCode = errorCode,
                completedAt =
                    positiveLong(json, "completedAt")
                        ?: return null
            )
        }

        private fun nullableOperation(
            json: JSONObject
        ): String? {
            val value =
                nonNullValue(json, "operation")
                    ?: return null

            return NativePasskeysContract
                .normalizeOperation(value)
        }

        private fun booleanValue(
            json: JSONObject,
            key: String
        ): Boolean? {
            return nonNullValue(json, key) as? Boolean
        }

        private fun positiveLong(
            json: JSONObject,
            key: String
        ): Long? {
            val number =
                nonNullValue(json, key) as? Number
                    ?: return null

            val value = number.toLong()

            if (
                number is Float &&
                number.toDouble() != value.toDouble() ||
                number is Double &&
                number != value.toDouble() ||
                value <= 0L
            ) {
                return null
            }

            return value
        }

        private fun nonNullValue(
            json: JSONObject,
            key: String
        ): Any? {
            if (
                !json.has(key) ||
                json.isNull(key)
            ) {
                return null
            }

            return json.opt(key)
        }
    }
}

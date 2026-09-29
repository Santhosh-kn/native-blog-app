package com.bbs.plugins.native_passkeys

import java.util.UUID

internal sealed interface NativePasskeysRequestValidation {

    data class Valid(
        val request: NativePasskeysRequest
    ) : NativePasskeysRequestValidation

    data class Invalid(
        val id: String,
        val operation: String?,
        val errorCode: String
    ) : NativePasskeysRequestValidation
}

internal data class NativePasskeysRequest(
    val id: String,
    val operation: String,
    val requestJson: String,
    val startedAt: Long
) {
    companion object {

        fun fromParameters(
            parameters: Map<String, Any>,
            expectedOperation: String
        ): NativePasskeysRequestValidation {
            val operation =
                NativePasskeysContract.normalizeOperation(
                    expectedOperation
                )

            if (operation == null) {
                return NativePasskeysRequestValidation.Invalid(
                    id = UUID.randomUUID().toString(),
                    operation = null,
                    errorCode =
                        NativePasskeysContract.INVALID_OPERATION
                )
            }

            val id =
                NativePasskeysContract.normalizeRequestId(
                    parameters["id"]
                )

            if (id == null) {
                return NativePasskeysRequestValidation.Invalid(
                    id = UUID.randomUUID().toString(),
                    operation = operation,
                    errorCode =
                        NativePasskeysContract.INVALID_REQUEST_ID
                )
            }

            val requestJson =
                parameters["request_json"] as? String

            val requestError =
                NativePasskeysContract.requestJsonError(
                    value = requestJson,
                    operation = operation
                )

            if (requestError != null) {
                return NativePasskeysRequestValidation.Invalid(
                    id = id,
                    operation = operation,
                    errorCode = requestError
                )
            }

            return NativePasskeysRequestValidation.Valid(
                NativePasskeysRequest(
                    id = id,
                    operation = operation,
                    requestJson = requestJson!!,
                    startedAt = System.currentTimeMillis()
                )
            )
        }
    }
}

package com.bbs.plugins.native_contacts

import org.json.JSONObject
import java.util.UUID

internal sealed interface NativeContactsRequestValidation {

    data class Valid(
        val request: NativeContactsRequest
    ) : NativeContactsRequestValidation

    data class Invalid(
        val id: String,
        val operation: String?,
        val mode: String?,
        val errorCode: String
    ) : NativeContactsRequestValidation
}

internal class NativeContactsRequest private constructor(
    val id: String,
    val operation: String,
    val mode: String?,
    val name: String?,
    val phone: String?,
    val email: String?,
    val uri: String?
) {

    // Prevent accidental string conversion from exposing contact inputs.
    override fun toString(): String =
        "NativeContactsRequest(operation=$operation)"

    companion object {

        const val GET_STATUS = "get_status"
        const val CONSUME_RESULT = "consume_result"

        private val fields = mapOf(
            NativeContactsContract.PICK to setOf("id", "mode"),
            NativeContactsContract.CREATE to setOf("id", "name", "phone", "email"),
            NativeContactsContract.OPEN to setOf("id", "uri"),
            GET_STATUS to setOf("id"),
            CONSUME_RESULT to setOf("id")
        )

        fun fromParameters(
            operation: String,
            parameters: Map<String, Any>
        ): NativeContactsRequestValidation {
            val suppliedId = NativeContactsContract.requestId(parameters["id"])
            val responseId = suppliedId ?: UUID.randomUUID().toString()
            val responseOperation = operation.takeIf {
                it in NativeContactsContract.operations
            }
            val responseMode = (parameters["mode"] as? String)?.takeIf {
                operation == NativeContactsContract.PICK &&
                    it in NativeContactsContract.modes
            }

            fun invalid(errorCode: String): NativeContactsRequestValidation.Invalid =
                NativeContactsRequestValidation.Invalid(
                    id = responseId,
                    operation = responseOperation,
                    mode = responseMode,
                    errorCode = errorCode
                )

            val allowed = fields[operation]
                ?: return invalid(NativeContactsContract.INVALID_PARAMETERS)

            if (parameters.keys.any { it !in allowed }) {
                return invalid(NativeContactsContract.INVALID_PARAMETERS)
            }

            // Bound string allocation before serializing the native payload.
            if (parameters.values.any {
                it is String && it.length > NativeContactsContract.MAX_REQUEST_BYTES
            }) {
                return invalid(NativeContactsContract.REQUEST_TOO_LARGE)
            }

            val encodedSize = try {
                JSONObject(parameters).toString()
                    .toByteArray(Charsets.UTF_8).size
            } catch (_: Exception) {
                return invalid(NativeContactsContract.INVALID_PARAMETERS)
            }

            if (encodedSize > NativeContactsContract.MAX_REQUEST_BYTES) {
                return invalid(NativeContactsContract.REQUEST_TOO_LARGE)
            }

            if (suppliedId == null) {
                return invalid(NativeContactsContract.INVALID_REQUEST_ID)
            }

            when (operation) {
                NativeContactsContract.PICK -> {
                    if (responseMode == null) {
                        return invalid(NativeContactsContract.INVALID_MODE)
                    }
                }

                NativeContactsContract.CREATE -> {
                    if (
                        parameters.containsKey("name") &&
                        !NativeContactsContract.isName(parameters["name"])
                    ) {
                        return invalid(NativeContactsContract.INVALID_NAME)
                    }

                    if (
                        parameters.containsKey("phone") &&
                        !NativeContactsContract.isPhone(parameters["phone"])
                    ) {
                        return invalid(NativeContactsContract.INVALID_PHONE)
                    }

                    if (
                        parameters.containsKey("email") &&
                        !NativeContactsContract.isEmail(parameters["email"])
                    ) {
                        return invalid(NativeContactsContract.INVALID_EMAIL)
                    }
                }

                NativeContactsContract.OPEN -> {
                    if (NativeContactsContract.contactUri(parameters["uri"]) == null) {
                        return invalid(NativeContactsContract.INVALID_URI)
                    }
                }
            }

            return NativeContactsRequestValidation.Valid(
                NativeContactsRequest(
                    id = suppliedId,
                    operation = operation,
                    mode = responseMode,
                    name = parameters["name"] as? String,
                    phone = parameters["phone"] as? String,
                    email = parameters["email"] as? String,
                    uri = parameters["uri"] as? String
                )
            )
        }

        /**
         * Reconstruct picker identity only.
         * Creation inputs and contact references are not persisted here.
         */
        fun restorePicker(
            id: String,
            mode: String
        ): NativeContactsRequest? {
            val result = fromParameters(
                NativeContactsContract.PICK,
                mapOf("id" to id, "mode" to mode)
            )

            return (result as? NativeContactsRequestValidation.Valid)?.request
        }
    }
}

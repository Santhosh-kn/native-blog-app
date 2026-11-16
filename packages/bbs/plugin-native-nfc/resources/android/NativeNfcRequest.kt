package com.bbs.plugins.native_nfc

import com.nativephp.mobile.bridge.BridgeError
import org.json.JSONObject
import java.nio.charset.StandardCharsets

internal data class NativeNfcIdRequest(
    val id: String
)

internal data class NativeNfcWriteRequest(
    val id: String,
    val text: String
)

internal object NativeNfcRequest {

    fun requireNoParameters(parameters: Map<String, Any>) {
        if (parameters.isNotEmpty()) {
            throw BridgeError.InvalidParameters(
                "This operation does not accept parameters"
            )
        }
    }

    fun parseId(parameters: Map<String, Any>): NativeNfcIdRequest {
        requireExactKeys(parameters, setOf("id"))

        val id = parameters["id"] as? String
            ?: throw BridgeError.InvalidParameters("id must be a string")

        validateId(id)
        validateRequestSize(parameters)

        return NativeNfcIdRequest(id)
    }

    fun parseWrite(parameters: Map<String, Any>): NativeNfcWriteRequest {
        requireExactKeys(parameters, setOf("id", "text"))

        val id = parameters["id"] as? String
            ?: throw BridgeError.InvalidParameters("id must be a string")

        validateId(id)

        val text = parameters["text"] as? String
            ?: throw BridgeError.InvalidParameters("text must be a string")

        if (text.isBlank()) {
            throw BridgeError.InvalidParameters("text must not be blank")
        }

        // Fast upper bound before scanning or allocating UTF-8 bytes.
        if (text.length > NativeNfcContract.MAX_TEXT_BYTES) {
            throw BridgeError.InvalidParameters(
                "text exceeds the maximum byte limit"
            )
        }

        validateTextCharacters(text)

        val textBytes = text.toByteArray(StandardCharsets.UTF_8).size

        if (textBytes > NativeNfcContract.MAX_TEXT_BYTES) {
            throw BridgeError.InvalidParameters(
                "text exceeds the maximum byte limit"
            )
        }

        validateRequestSize(parameters)

        return NativeNfcWriteRequest(id, text)
    }

    private fun validateId(id: String) {
        if (!NativeNfcContract.UUID_V4_REGEX.matches(id)) {
            throw BridgeError.InvalidParameters("id must be a valid UUID v4")
        }
    }

    private fun requireExactKeys(
        parameters: Map<String, Any>,
        expected: Set<String>
    ) {
        if (parameters.keys != expected) {
            throw BridgeError.InvalidParameters(
                "Expected parameters: ${expected.sorted().joinToString(", ")}"
            )
        }
    }

    private fun validateRequestSize(parameters: Map<String, Any>) {
        val requestBytes = try {
            JSONObject(parameters)
                .toString()
                .toByteArray(StandardCharsets.UTF_8)
                .size
        } catch (_: Exception) {
            throw BridgeError.InvalidParameters("Unable to serialize request")
        }

        if (requestBytes > NativeNfcContract.MAX_REQUEST_BYTES) {
            throw BridgeError.InvalidParameters(
                "Request exceeds the maximum byte limit"
            )
        }
    }

    private fun validateTextCharacters(text: String) {
        var index = 0

        while (index < text.length) {
            val current = text[index]

            val codePoint = when {
                Character.isHighSurrogate(current) -> {
                    if (index + 1 >= text.length ||
                        !Character.isLowSurrogate(text[index + 1])
                    ) {
                        throw BridgeError.InvalidParameters(
                            "text contains malformed Unicode"
                        )
                    }

                    Character.toCodePoint(current, text[index + 1]).also {
                        index++
                    }
                }

                Character.isLowSurrogate(current) -> {
                    throw BridgeError.InvalidParameters(
                        "text contains malformed Unicode"
                    )
                }

                else -> current.code
            }

            val allowedWhitespace = codePoint == 0x09 ||
                codePoint == 0x0A ||
                codePoint == 0x0D

            if (!allowedWhitespace && Character.isISOControl(codePoint)) {
                throw BridgeError.InvalidParameters(
                    "text contains prohibited control characters"
                )
            }

            index++
        }
    }
}

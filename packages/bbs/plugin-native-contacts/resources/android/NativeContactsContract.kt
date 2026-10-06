package com.bbs.plugins.native_contacts

import android.net.Uri
import java.io.ByteArrayOutputStream
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction

internal object NativeContactsContract {

    const val MIN_API_LEVEL = 33
    const val MAX_REQUEST_BYTES = 8_192
    const val MAX_NAME_CODE_POINTS = 120
    const val MAX_PHONE_LENGTH = 64
    const val MAX_EMAIL_LENGTH = 254
    const val MAX_URI_BYTES = 2_048
    const val MAX_RESULT_BYTES = 16_384

    const val SELECTION_TTL_MS = 600_000L
    const val METADATA_TTL_MS = 86_400_000L
    const val MAX_STORED_RESULTS = 100

    const val EVENT_CLASS =
        "Bbs\\NativeContacts\\Events\\NativeContactsCompleted"

    const val PICK = "pick"
    const val CREATE = "create"
    const val OPEN = "open"

    const val MODE_CONTACT = "contact"
    const val MODE_PHONE = "phone"
    const val MODE_EMAIL = "email"

    const val STATUS_PENDING = "pending"
    const val STATUS_SELECTED = "selected"
    const val STATUS_LAUNCHED = "launched"
    const val STATUS_CANCELLED = "cancelled"
    const val STATUS_FAILED = "failed"
    const val STATUS_UNKNOWN = "unknown"
    const val STATUS_NOT_FOUND = "not_found"

    const val INVALID_PARAMETERS = "INVALID_PARAMETERS"
    const val REQUEST_TOO_LARGE = "REQUEST_TOO_LARGE"
    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_MODE = "INVALID_MODE"
    const val INVALID_NAME = "INVALID_NAME"
    const val INVALID_PHONE = "INVALID_PHONE"
    const val INVALID_EMAIL = "INVALID_EMAIL"
    const val INVALID_URI = "INVALID_URI"
    const val BRIDGE_UNAVAILABLE = "BRIDGE_UNAVAILABLE"
    const val ACTIVITY_UNAVAILABLE = "ACTIVITY_UNAVAILABLE"
    const val OPERATION_UNAVAILABLE = "OPERATION_UNAVAILABLE"
    const val OPERATION_BUSY = "OPERATION_BUSY"
    const val REQUEST_ID_REUSED = "REQUEST_ID_REUSED"
    const val LAUNCH_FAILED = "LAUNCH_FAILED"
    const val CONTACT_ACCESS_DENIED = "CONTACT_ACCESS_DENIED"
    const val INVALID_SELECTION = "INVALID_SELECTION"
    const val CONTACT_READ_FAILED = "CONTACT_READ_FAILED"
    const val RESULT_PERSISTENCE_FAILED = "RESULT_PERSISTENCE_FAILED"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val RESULT_NOT_READY = "RESULT_NOT_READY"
    const val RESULT_ALREADY_CONSUMED = "RESULT_ALREADY_CONSUMED"
    const val RESULT_EXPIRED = "RESULT_EXPIRED"
    const val OPERATION_INTERRUPTED = "OPERATION_INTERRUPTED"
    const val INVALID_NATIVE_RESPONSE = "INVALID_NATIVE_RESPONSE"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    val operations = setOf(PICK, CREATE, OPEN)
    val modes = setOf(MODE_CONTACT, MODE_PHONE, MODE_EMAIL)

    val terminalStatuses = setOf(
        STATUS_SELECTED,
        STATUS_LAUNCHED,
        STATUS_CANCELLED,
        STATUS_FAILED,
        STATUS_UNKNOWN
    )

    private val requestIdPattern = Regex(
        "\\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z"
    )

    private val phonePattern = Regex("\\A\\+?[0-9(). -]+\\z")
    private val localEmailPattern =
        Regex("\\A[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]+\\z")
    private val domainLabelPattern =
        Regex("\\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\\z")
    private val topLevelDomainPattern =
        Regex("\\A[A-Za-z]{2,63}\\z")
    private val malformedEscapePattern =
        Regex("%(?![0-9A-Fa-f]{2})")
    private val contactPathPattern =
        Regex("\\A/contacts/([1-9][0-9]{0,18})\\z")
    private val lookupPathPattern =
        Regex("\\A/contacts/lookup/([^/]+)(?:/([1-9][0-9]{0,18}))?\\z")
    private val dataPathPattern =
        Regex("\\A/data/([1-9][0-9]{0,18})\\z")

    private val messages = mapOf(
        INVALID_PARAMETERS to "The contact request contains invalid fields.",
        REQUEST_TOO_LARGE to "The contact request is too large.",
        INVALID_REQUEST_ID to "The request ID is invalid.",
        INVALID_MODE to "The contact selection mode is invalid.",
        INVALID_NAME to "The contact name is invalid.",
        INVALID_PHONE to "The phone number format is invalid.",
        INVALID_EMAIL to "The email address format is invalid.",
        INVALID_URI to "The contact reference is invalid.",
        BRIDGE_UNAVAILABLE to "The native Contacts bridge is unavailable.",
        ACTIVITY_UNAVAILABLE to "The Android activity is unavailable.",
        OPERATION_UNAVAILABLE to "No compatible Android contact activity is available.",
        OPERATION_BUSY to "A contact operation is already pending.",
        REQUEST_ID_REUSED to "The request ID has already been used.",
        LAUNCH_FAILED to "The Android contact screen could not be launched.",
        CONTACT_ACCESS_DENIED to "Access to the selected contact item was denied.",
        INVALID_SELECTION to "The contact picker returned an invalid selection.",
        CONTACT_READ_FAILED to "The selected contact item could not be read.",
        RESULT_PERSISTENCE_FAILED to "The contact result could not be stored safely.",
        RESULT_NOT_FOUND to "No contact result exists for this request.",
        RESULT_NOT_READY to "The contact result is not ready.",
        RESULT_ALREADY_CONSUMED to "The contact result has already been consumed.",
        RESULT_EXPIRED to "The selected contact data has expired.",
        OPERATION_INTERRUPTED to "The contact operation outcome could not be established.",
        INVALID_NATIVE_RESPONSE to "The native Contacts response is invalid.",
        UNKNOWN_ERROR to "The contact operation could not be completed."
    )

    fun isKnownError(value: String): Boolean = messages.containsKey(value)

    fun message(value: String): String =
        messages[value] ?: messages.getValue(UNKNOWN_ERROR)

    fun requestId(value: Any?): String? =
        (value as? String)?.takeIf(requestIdPattern::matches)

    fun isSafeText(value: String): Boolean {
        var index = 0

        while (index < value.length) {
            val character = value[index]

            if (character.code < 0x20 || character.code == 0x7F) {
                return false
            }

            if (Character.isHighSurrogate(character)) {
                if (
                    index + 1 >= value.length ||
                    !Character.isLowSurrogate(value[index + 1])
                ) {
                    return false
                }
                index += 2
            } else {
                if (Character.isLowSurrogate(character)) {
                    return false
                }
                index++
            }
        }

        return true
    }

    fun isName(value: Any?): Boolean {
        val name = value as? String ?: return false

        return name.trim(' ', '\t', '\n', '\r', '\u0000', '\u000B').isNotEmpty() &&
            isSafeText(name) &&
            name.codePointCount(0, name.length) <= MAX_NAME_CODE_POINTS
    }

    fun isPhone(value: Any?): Boolean {
        val phone = value as? String ?: return false

        return phone.length <= MAX_PHONE_LENGTH &&
            phonePattern.matches(phone) &&
            phone.count { it in '0'..'9' } in 3..15
    }

    fun isEmail(value: Any?): Boolean {
        val email = value as? String ?: return false

        if (
            email.length > MAX_EMAIL_LENGTH ||
            email.count { it == '@' } != 1
        ) {
            return false
        }

        val local = email.substringBefore('@')
        val domain = email.substringAfter('@')

        if (
            local.length > 64 ||
            !localEmailPattern.matches(local) ||
            local.startsWith('.') ||
            local.endsWith('.') ||
            local.contains("..")
        ) {
            return false
        }

        val labels = domain.split('.')

        return labels.size >= 2 &&
            topLevelDomainPattern.matches(labels.last()) &&
            labels.all {
                it.length <= 63 && domainLabelPattern.matches(it)
            }
    }

    fun contactUri(value: Any?): Uri? {
        val uri = baseContactUri(value) ?: return null
        val path = uri.encodedPath ?: return null

        contactPathPattern.matchEntire(path)?.let {
            return uri.takeIf { _ -> isAndroidId(it.groupValues[1]) }
        }

        val lookupMatch = lookupPathPattern.matchEntire(path)
            ?: return null
        val lookup = decodeLookup(lookupMatch.groupValues[1])
            ?: return null
        val id = lookupMatch.groupValues[2]

        return uri.takeIf {
            lookup != "." &&
                lookup != ".." &&
                isSafeText(lookup) &&
                (id.isEmpty() || isAndroidId(id))
        }
    }

    fun selectedUri(value: Any?, mode: String): Uri? {
        if (mode == MODE_CONTACT) {
            return contactUri(value)
        }

        if (mode !in setOf(MODE_PHONE, MODE_EMAIL)) {
            return null
        }

        val uri = baseContactUri(value) ?: return null
        val match = dataPathPattern.matchEntire(uri.encodedPath ?: "")
            ?: return null

        return uri.takeIf { isAndroidId(match.groupValues[1]) }
    }

    private fun baseContactUri(value: Any?): Uri? {
        val text = value as? String ?: return null

        if (
            text.isEmpty() ||
            !isSafeText(text) ||
            text.any { it.code <= 0x20 } ||
            text.toByteArray(Charsets.UTF_8).size > MAX_URI_BYTES ||
            malformedEscapePattern.containsMatchIn(text)
        ) {
            return null
        }

        val uri = try {
            Uri.parse(text)
        } catch (_: RuntimeException) {
            return null
        }

        return uri.takeIf {
            it.scheme == "content" &&
                it.encodedAuthority == "com.android.contacts" &&
                it.encodedQuery == null &&
                it.encodedFragment == null
        }
    }

    private fun isAndroidId(value: String): Boolean =
        value.toLongOrNull()?.let { it > 0L } == true

    private fun decodeLookup(value: String): String? {
        return try {
            val bytes = ByteArrayOutputStream()
            var index = 0

            while (index < value.length) {
                if (value[index] == '%') {
                    if (index + 2 >= value.length) {
                        return null
                    }
                    val byte = value.substring(index + 1, index + 3)
                        .toIntOrNull(16) ?: return null
                    bytes.write(byte)
                    index += 3
                } else {
                    val count = Character.charCount(value.codePointAt(index))
                    bytes.write(
                        value.substring(index, index + count)
                            .toByteArray(Charsets.UTF_8)
                    )
                    index += count
                }
            }

            Charsets.UTF_8.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(bytes.toByteArray()))
                .toString()
        } catch (_: Exception) {
            null
        }
    }
}

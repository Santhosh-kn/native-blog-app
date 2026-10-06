package com.bbs.plugins.native_contacts

import org.json.JSONObject

internal data class NativeContactsSelection(
    val displayName: String? = null,
    val contactUri: String? = null,
    val phoneNumber: String? = null,
    val emailAddress: String? = null
) {

    override fun toString(): String = "NativeContactsSelection(redacted)"

    fun isValid(mode: String): Boolean {
        if (
            displayName != null && !NativeContactsContract.isName(displayName) ||
            contactUri != null && NativeContactsContract.contactUri(contactUri) == null
        ) {
            return false
        }

        return when (mode) {
            NativeContactsContract.MODE_CONTACT ->
                contactUri != null && phoneNumber == null && emailAddress == null

            NativeContactsContract.MODE_PHONE ->
                NativeContactsContract.isPhone(phoneNumber) && emailAddress == null

            NativeContactsContract.MODE_EMAIL ->
                NativeContactsContract.isEmail(emailAddress) && phoneNumber == null

            else -> false
        }
    }

    fun toBridgeMap(): Map<String, Any> = buildMap {
        displayName?.let { put("displayName", it) }
        contactUri?.let { put("contactUri", it) }
        phoneNumber?.let { put("phoneNumber", it) }
        emailAddress?.let { put("emailAddress", it) }
    }

    fun toStoredJson(): JSONObject = JSONObject().apply {
        put("displayName", displayName ?: JSONObject.NULL)
        put("contactUri", contactUri ?: JSONObject.NULL)
        put("phoneNumber", phoneNumber ?: JSONObject.NULL)
        put("emailAddress", emailAddress ?: JSONObject.NULL)
    }

    companion object {

        fun fromStoredJson(
            json: JSONObject,
            mode: String
        ): NativeContactsSelection? {
            val keys = setOf(
                "displayName", "contactUri", "phoneNumber", "emailAddress"
            )

            if (json.keys().asSequence().toSet() != keys) {
                return null
            }

            if (keys.any {
                !json.isNull(it) && json.opt(it) !is String
            }) {
                return null
            }

            return NativeContactsSelection(
                displayName = json.opt("displayName") as? String,
                contactUri = json.opt("contactUri") as? String,
                phoneNumber = json.opt("phoneNumber") as? String,
                emailAddress = json.opt("emailAddress") as? String
            ).takeIf { it.isValid(mode) }
        }
    }
}

internal data class NativeContactsResult(
    val id: String,
    val operation: String?,
    val mode: String?,
    val status: String,
    val createdAtMs: Long?,
    val completedAtMs: Long? = null,
    val consumed: Boolean = false,
    val errorCode: String? = null,
    val selection: NativeContactsSelection? = null
) {

    val success: Boolean
        get() = status == NativeContactsContract.STATUS_SELECTED ||
            status == NativeContactsContract.STATUS_LAUNCHED

    val cancelled: Boolean
        get() = status == NativeContactsContract.STATUS_CANCELLED

    override fun toString(): String = "NativeContactsResult(status=$status)"

    /**
     * Selection is supplied separately after durable redaction.
     * Ordinary status and event calls use the defaults.
     */
    fun toBridgeMap(
        accepted: Boolean? = null,
        selectionToDeliver: NativeContactsSelection? = null,
        errorOverride: String? = null
    ): Map<String, Any> {
        val safeError = (errorOverride ?: errorCode)?.let {
            if (NativeContactsContract.isKnownError(it)) {
                it
            } else {
                NativeContactsContract.UNKNOWN_ERROR
            }
        }

        if (selectionToDeliver != null) {
            require(
                status == NativeContactsContract.STATUS_SELECTED &&
                    operation == NativeContactsContract.PICK &&
                    consumed &&
                    selection == null &&
                    safeError == null &&
                    mode != null &&
                    selectionToDeliver.isValid(mode)
            )
        }

        return buildMap {
            put("id", id)
            operation?.let { put("operation", it) }
            mode?.let { put("mode", it) }
            put("status", status)
            put("success", success)
            put("cancelled", cancelled)
            put("consumed", consumed)
            accepted?.let { put("accepted", it) }
            createdAtMs?.let { put("createdAtMs", it) }
            completedAtMs?.let { put("completedAtMs", it) }
            safeError?.let {
                put("errorCode", it)
                put("errorMessage", NativeContactsContract.message(it))
            }
            selectionToDeliver?.let {
                put("selection", it.toBridgeMap())
            }
        }
    }

    fun toEventJson(): JSONObject = JSONObject(toBridgeMap())

    fun toStoredJson(): JSONObject = JSONObject().apply {
        put("id", id)
        put("operation", operation ?: JSONObject.NULL)
        put("mode", mode ?: JSONObject.NULL)
        put("status", status)
        put("createdAtMs", createdAtMs ?: JSONObject.NULL)
        put("completedAtMs", completedAtMs ?: JSONObject.NULL)
        put("consumed", consumed)
        put("errorCode", errorCode ?: JSONObject.NULL)
        put("selection", selection?.toStoredJson() ?: JSONObject.NULL)
    }

    fun isValidStored(): Boolean {
        val created = createdAtMs ?: return false

        if (
            NativeContactsContract.requestId(id) != id ||
            operation !in NativeContactsContract.operations ||
            created <= 0L ||
            (operation == NativeContactsContract.PICK &&
                mode !in NativeContactsContract.modes) ||
            (operation != NativeContactsContract.PICK && mode != null)
        ) {
            return false
        }

        if (status == NativeContactsContract.STATUS_PENDING) {
            return completedAtMs == null &&
                !consumed &&
                errorCode == null &&
                selection == null
        }

        val completed = completedAtMs ?: return false

        if (
            status !in NativeContactsContract.terminalStatuses ||
            completed < created
        ) {
            return false
        }

        return when (status) {
            NativeContactsContract.STATUS_SELECTED -> {
                operation == NativeContactsContract.PICK &&
                    errorCode == null &&
                    if (consumed) {
                        selection == null
                    } else {
                        mode != null && selection?.isValid(mode) == true
                    }
            }

            NativeContactsContract.STATUS_LAUNCHED ->
                operation != NativeContactsContract.PICK &&
                    !consumed && errorCode == null && selection == null

            NativeContactsContract.STATUS_CANCELLED ->
                operation == NativeContactsContract.PICK &&
                    !consumed && errorCode == null && selection == null

            NativeContactsContract.STATUS_UNKNOWN ->
                !consumed &&
                    errorCode == NativeContactsContract.OPERATION_INTERRUPTED &&
                    selection == null

            NativeContactsContract.STATUS_FAILED ->
                !consumed &&
                    errorCode != null &&
                    NativeContactsContract.isKnownError(errorCode) &&
                    selection == null

            else -> false
        }
    }

    fun complete(
        terminalStatus: String,
        error: String? = null,
        selected: NativeContactsSelection? = null,
        now: Long = System.currentTimeMillis()
    ): NativeContactsResult {
        require(status == NativeContactsContract.STATUS_PENDING)
        val created = requireNotNull(createdAtMs)

        return copy(
            status = terminalStatus,
            completedAtMs = maxOf(now, created),
            consumed = false,
            errorCode = error,
            selection = selected
        ).also {
            require(it.isValidStored())
        }
    }

    fun expiredSelection(): NativeContactsResult {
        require(
            status == NativeContactsContract.STATUS_SELECTED &&
                !consumed
        )

        return copy(
            status = NativeContactsContract.STATUS_FAILED,
            consumed = false,
            errorCode = NativeContactsContract.RESULT_EXPIRED,
            selection = null
        ).also {
            require(it.isValidStored())
        }
    }

    companion object {

        private val storedKeys = setOf(
            "id", "operation", "mode", "status",
            "createdAtMs", "completedAtMs",
            "consumed", "errorCode", "selection"
        )

        fun pending(
            request: NativeContactsRequest,
            now: Long = System.currentTimeMillis()
        ): NativeContactsResult = NativeContactsResult(
            id = request.id,
            operation = request.operation,
            mode = request.mode,
            status = NativeContactsContract.STATUS_PENDING,
            createdAtMs = now
        ).also {
            require(it.isValidStored())
        }

        fun failed(
            id: String,
            errorCode: String,
            operation: String? = null,
            mode: String? = null
        ): NativeContactsResult {
            val safeOperation = operation.takeIf {
                it in NativeContactsContract.operations
            }
            val safeMode = mode.takeIf {
                safeOperation == NativeContactsContract.PICK &&
                    it in NativeContactsContract.modes
            }

            return NativeContactsResult(
                id = id,
                operation = safeOperation,
                mode = safeMode,
                status = NativeContactsContract.STATUS_FAILED,
                createdAtMs = null,
                errorCode = if (NativeContactsContract.isKnownError(errorCode)) {
                    errorCode
                } else {
                    NativeContactsContract.UNKNOWN_ERROR
                }
            )
        }

        fun notFound(id: String): NativeContactsResult =
            NativeContactsResult(
                id = id,
                operation = null,
                mode = null,
                status = NativeContactsContract.STATUS_NOT_FOUND,
                createdAtMs = null,
                errorCode = NativeContactsContract.RESULT_NOT_FOUND
            )

        fun fromStoredJson(json: JSONObject): NativeContactsResult? {
            if (json.keys().asSequence().toSet() != storedKeys) {
                return null
            }

            val id = NativeContactsContract.requestId(json.opt("id"))
                ?: return null
            val operation = json.opt("operation") as? String
                ?: return null
            val modeValue = json.opt("mode")
            val errorValue = json.opt("errorCode")

            if (
                modeValue !== JSONObject.NULL && modeValue !is String ||
                errorValue !== JSONObject.NULL && errorValue !is String
            ) {
                return null
            }

            val mode = modeValue as? String
            val status = json.opt("status") as? String ?: return null
            val consumed = json.opt("consumed") as? Boolean ?: return null
            val created = positiveLong(json.opt("createdAtMs")) ?: return null
            val completedValue = json.opt("completedAtMs")
            val completed = if (completedValue === JSONObject.NULL) {
                null
            } else {
                positiveLong(completedValue) ?: return null
            }

            val rawSelection = json.opt("selection")
            val selection = if (rawSelection === JSONObject.NULL) {
                null
            } else {
                if (rawSelection !is JSONObject || mode == null) {
                    return null
                }
                NativeContactsSelection.fromStoredJson(rawSelection, mode)
                    ?: return null
            }

            return NativeContactsResult(
                id = id,
                operation = operation,
                mode = mode,
                status = status,
                createdAtMs = created,
                completedAtMs = completed,
                consumed = consumed,
                errorCode = errorValue as? String,
                selection = selection
            ).takeIf {
                it.isValidStored()
            }
        }

        private fun positiveLong(value: Any?): Long? {
            val number = when (value) {
                is Int -> value.toLong()
                is Long -> value
                else -> return null
            }

            return number.takeIf { it > 0L }
        }
    }
}

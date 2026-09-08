package com.bbs.plugins.native_document_picker

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONException
import org.json.JSONObject

internal sealed interface NativeDocumentPickerBeginResult {

    data object Stored : NativeDocumentPickerBeginResult

    data object Busy : NativeDocumentPickerBeginResult

    data object Failed : NativeDocumentPickerBeginResult
}

internal class NativeDocumentPickerStore(context: Context) {

    private val preferences: SharedPreferences =
        context.applicationContext.getSharedPreferences(
            PREFERENCES_NAME,
            Context.MODE_PRIVATE
        )

    fun begin(
        request: NativeDocumentPickerRequest
    ): NativeDocumentPickerBeginResult = synchronized(lock) {
        if (NativeDocumentPickerRequest.fromJson(request.toJson()) != request) {
            return@synchronized NativeDocumentPickerBeginResult.Failed
        }

        if (readActiveRequest() != null) {
            return@synchronized NativeDocumentPickerBeginResult.Busy
        }

        val editor = preferences.edit()
        val previousActiveId = normalizedPreferenceString(ACTIVE_REQUEST_KEY)

        editor.remove(ACTIVE_REQUEST_KEY)

        if (previousActiveId != null) {
            editor.remove(requestKey(previousActiveId))

            val previousResult = readResult(previousActiveId)

            if (
                previousResult == null ||
                previousResult.status == NativeDocumentPickerContract.STATUS_PENDING
            ) {
                editor.remove(resultKey(previousActiveId))
            }
        }

        val pendingResult = NativeDocumentPickerResult.pending(request)

        val stored = editor
            .putString(requestKey(request.id), request.toJson().toString())
            .putString(
                resultKey(request.id),
                pendingResult.toStoredJson().toString()
            )
            .putString(ACTIVE_REQUEST_KEY, request.id)
            .commit()

        if (stored) {
            NativeDocumentPickerBeginResult.Stored
        } else {
            NativeDocumentPickerBeginResult.Failed
        }
    }

    fun activeRequest(): NativeDocumentPickerRequest? = synchronized(lock) {
        readActiveRequest()
    }

    fun request(id: String): NativeDocumentPickerRequest? = synchronized(lock) {
        val normalizedId = NativeDocumentPickerContract.normalizeRequestId(id)
            ?: return@synchronized null

        readRequest(normalizedId)
    }

    fun result(id: String): NativeDocumentPickerResult? = synchronized(lock) {
        val normalizedId = NativeDocumentPickerContract.normalizeRequestId(id)
            ?: return@synchronized null

        readResult(normalizedId)
    }

    fun complete(result: NativeDocumentPickerResult): Boolean = synchronized(lock) {
        val normalizedId = NativeDocumentPickerContract.normalizeRequestId(
            result.id
        ) ?: return@synchronized false

        if (
            normalizedId != result.id ||
            result.status !in terminalStatuses
        ) {
            return@synchronized false
        }

        val storedResult = result.toStoredJson()

        if (NativeDocumentPickerResult.fromStoredJson(storedResult) != result) {
            return@synchronized false
        }

        val editor = preferences.edit()
            .putString(
                resultKey(result.id),
                storedResult.toString()
            )
            .remove(requestKey(result.id))

        if (normalizedPreferenceString(ACTIVE_REQUEST_KEY) == result.id) {
            editor.remove(ACTIVE_REQUEST_KEY)
        }

        editor.commit()
    }

    private fun readActiveRequest(): NativeDocumentPickerRequest? {
        val activeId = normalizedPreferenceString(ACTIVE_REQUEST_KEY)
            ?: return null

        val request = readRequest(activeId)
            ?: return null

        if (request.id != activeId) {
            return null
        }

        val result = readResult(activeId)

        if (
            result == null ||
            result.status != NativeDocumentPickerContract.STATUS_PENDING
        ) {
            return null
        }

        return request
    }

    private fun readRequest(id: String): NativeDocumentPickerRequest? {
        val json = storedJson(requestKey(id))
            ?: return null

        return NativeDocumentPickerRequest.fromJson(json)
            ?.takeIf { request -> request.id == id }
    }

    private fun readResult(id: String): NativeDocumentPickerResult? {
        val json = storedJson(resultKey(id))
            ?: return null

        return NativeDocumentPickerResult.fromStoredJson(json)
            ?.takeIf { result -> result.id == id }
    }

    private fun storedJson(key: String): JSONObject? {
        val stored = preferenceString(key)
            ?: return null

        return try {
            JSONObject(stored)
        } catch (_: JSONException) {
            null
        }
    }

    private fun normalizedPreferenceString(key: String): String? {
        return NativeDocumentPickerContract.normalizeRequestId(
            preferenceString(key)
        )
    }

    private fun preferenceString(key: String): String? {
        return try {
            preferences.getString(key, null)
        } catch (_: ClassCastException) {
            null
        }
    }

    private fun requestKey(id: String): String = "$REQUEST_PREFIX$id"

    private fun resultKey(id: String): String = "$RESULT_PREFIX$id"

    private companion object {
        const val PREFERENCES_NAME = "bbs_native_document_picker_v1"
        const val ACTIVE_REQUEST_KEY = "active_request_id"
        const val REQUEST_PREFIX = "request:"
        const val RESULT_PREFIX = "result:"

        val lock = Any()

        val terminalStatuses = setOf(
            NativeDocumentPickerContract.STATUS_SUCCEEDED,
            NativeDocumentPickerContract.STATUS_CANCELLED,
            NativeDocumentPickerContract.STATUS_FAILED
        )
    }
}

package com.bbs.plugins.native_passkeys

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONException
import org.json.JSONObject

internal sealed interface NativePasskeysBeginResult {

    data object Stored : NativePasskeysBeginResult

    data object Busy : NativePasskeysBeginResult

    data object Failed : NativePasskeysBeginResult
}

internal sealed interface NativePasskeysConsumeResult {

    data class Consumed(
        val result: NativePasskeysResult
    ) : NativePasskeysConsumeResult

    data class AlreadyConsumed(
        val result: NativePasskeysResult
    ) : NativePasskeysConsumeResult

    data class NotTerminal(
        val result: NativePasskeysResult
    ) : NativePasskeysConsumeResult

    data object NotFound : NativePasskeysConsumeResult

    data object Failed : NativePasskeysConsumeResult
}

internal class NativePasskeysStore(
    context: Context
) {
    private val preferences: SharedPreferences =
        context.applicationContext.getSharedPreferences(
            PREFERENCES_NAME,
            Context.MODE_PRIVATE
        )

    fun begin(
        request: NativePasskeysRequest
    ): NativePasskeysBeginResult = synchronized(lock) {
        val pending =
            NativePasskeysResult.pending(request)

        if (
            NativePasskeysResult.fromStoredJson(
                pending.toStoredJson()
            ) != pending
        ) {
            return@synchronized NativePasskeysBeginResult.Failed
        }

        val activeId =
            normalizedPreferenceString(
                ACTIVE_REQUEST_KEY
            )

        if (activeId != null) {
            val activeResult =
                readResult(activeId)

            if (
                activeResult?.status ==
                NativePasskeysContract.STATUS_PENDING
            ) {
                return@synchronized NativePasskeysBeginResult.Busy
            }
        }

        if (readResult(request.id) != null) {
            return@synchronized NativePasskeysBeginResult.Busy
        }

        val stored = preferences.edit()
            .remove(ACTIVE_REQUEST_KEY)
            .putString(
                resultKey(request.id),
                pending.toStoredJson().toString()
            )
            .putString(
                ACTIVE_REQUEST_KEY,
                request.id
            )
            .commit()

        if (stored) {
            NativePasskeysBeginResult.Stored
        } else {
            NativePasskeysBeginResult.Failed
        }
    }

    fun result(
        id: String
    ): NativePasskeysResult? = synchronized(lock) {
        val normalizedId =
            NativePasskeysContract.normalizeRequestId(id)
                ?: return@synchronized null

        readResult(normalizedId)
    }

    fun activeId(): String? = synchronized(lock) {
        val id =
            normalizedPreferenceString(
                ACTIVE_REQUEST_KEY
            ) ?: return@synchronized null

        val result =
            readResult(id)
                ?: return@synchronized null

        if (
            result.status !=
            NativePasskeysContract.STATUS_PENDING
        ) {
            return@synchronized null
        }

        id
    }

    fun complete(
        result: NativePasskeysResult
    ): Boolean = synchronized(lock) {
        val normalizedId =
            NativePasskeysContract.normalizeRequestId(
                result.id
            ) ?: return@synchronized false

        if (
            normalizedId != result.id ||
            !NativePasskeysContract
                .isTerminalStatus(result.status) ||
            result.consumed
        ) {
            return@synchronized false
        }

        val storedJson = result.toStoredJson()

        if (
            NativePasskeysResult.fromStoredJson(
                storedJson
            ) != result
        ) {
            return@synchronized false
        }

        val current =
            readResult(result.id)
                ?: return@synchronized false

        if (
            current.status !=
                NativePasskeysContract.STATUS_PENDING ||
            current.operation != result.operation
        ) {
            return@synchronized false
        }

        val editor = preferences.edit()
            .putString(
                resultKey(result.id),
                storedJson.toString()
            )

        if (
            normalizedPreferenceString(
                ACTIVE_REQUEST_KEY
            ) == result.id
        ) {
            editor.remove(ACTIVE_REQUEST_KEY)
        }

        editor.commit()
    }

    fun consume(
        id: String
    ): NativePasskeysConsumeResult =
        synchronized(lock) {
            val normalizedId =
                NativePasskeysContract
                    .normalizeRequestId(id)
                    ?: return@synchronized NativePasskeysConsumeResult.NotFound

            val current =
                readResult(normalizedId)
                    ?: return@synchronized NativePasskeysConsumeResult.NotFound

            if (
                !NativePasskeysContract
                    .isTerminalStatus(
                        current.status
                    )
            ) {
                return@synchronized NativePasskeysConsumeResult.NotTerminal(
                        current
                    )
            }

            if (current.consumed) {
                return@synchronized NativePasskeysConsumeResult.AlreadyConsumed(
                        current
                    )
            }

            val delivered =
                current.copy(consumed = true)

            val redacted =
                current.copy(
                    consumed = true,
                    responseJson = null
                )

            if (
                NativePasskeysResult.fromStoredJson(
                    redacted.toStoredJson()
                ) != redacted
            ) {
                return@synchronized NativePasskeysConsumeResult.Failed
            }

            val stored = preferences.edit()
                .putString(
                    resultKey(normalizedId),
                    redacted.toStoredJson().toString()
                )
                .commit()

            if (!stored) {
                return@synchronized NativePasskeysConsumeResult.Failed
            }

            NativePasskeysConsumeResult.Consumed(
                delivered
            )
        }

    private fun readResult(
        id: String
    ): NativePasskeysResult? {
        val stored =
            preferenceString(resultKey(id))
                ?: return null

        val json = try {
            JSONObject(stored)
        } catch (_: JSONException) {
            return null
        } catch (_: RuntimeException) {
            return null
        }

        return NativePasskeysResult
            .fromStoredJson(json)
            ?.takeIf { result ->
                result.id == id
            }
    }

    private fun normalizedPreferenceString(
        key: String
    ): String? {
        return NativePasskeysContract
            .normalizeRequestId(
                preferenceString(key)
            )
    }

    private fun preferenceString(
        key: String
    ): String? {
        return try {
            preferences.getString(key, null)
        } catch (_: ClassCastException) {
            null
        }
    }

    private fun resultKey(
        id: String
    ): String = "$RESULT_PREFIX$id"

    private companion object {
        const val PREFERENCES_NAME =
            "bbs_native_passkeys_v1"

        const val ACTIVE_REQUEST_KEY =
            "active_request_id"

        const val RESULT_PREFIX =
            "result:"

        val lock = Any()
    }
}

package com.bbs.plugins.firebase_push_notifications

import android.content.Context
import android.util.AtomicFile
import java.io.File
import java.nio.charset.StandardCharsets

internal object FirebasePushTokenStore {

    private const val TOKEN_FILE_NAME =
        "firebase_push_notifications_token"

    private const val LEGACY_PREFERENCES_NAME =
        "firebase_push_notifications"

    private const val LEGACY_TOKEN_KEY =
        "latest_fcm_token"

    fun save(
        context: Context,
        token: String
    ) {
        val normalizedToken = token
            .trim()
            .takeIf { it.isNotEmpty() }
            ?: return

        val atomicFile = AtomicFile(
            tokenFile(context)
        )

        val outputStream = atomicFile.startWrite()

        try {
            outputStream.write(
                normalizedToken.toByteArray(
                    StandardCharsets.UTF_8
                )
            )

            atomicFile.finishWrite(outputStream)
        } catch (exception: Exception) {
            atomicFile.failWrite(outputStream)

            throw exception
        }
    }

    fun get(context: Context): String? {
        readTokenFile(context)?.let {
            return it
        }

        val preferences = context.getSharedPreferences(
            LEGACY_PREFERENCES_NAME,
            Context.MODE_PRIVATE
        )

        val legacyToken = preferences
            .getString(LEGACY_TOKEN_KEY, null)
            ?.trim()
            ?.takeIf { it.isNotEmpty() }

        if (legacyToken !== null) {
            save(
                context = context,
                token = legacyToken
            )

            preferences.edit()
                .remove(LEGACY_TOKEN_KEY)
                .apply()
        }

        return legacyToken
    }

    fun readableFile(context: Context): File? {
        get(context) ?: return null

        return tokenFile(context)
            .takeIf {
                it.isFile && it.canRead()
            }
    }

    private fun readTokenFile(
        context: Context
    ): String? {
        val file = tokenFile(context)

        if (!file.isFile) {
            return null
        }

        return try {
            AtomicFile(file)
                .openRead()
                .bufferedReader(StandardCharsets.UTF_8)
                .use { reader ->
                    reader.readText()
                }
                .trim()
                .takeIf { it.isNotEmpty() }
        } catch (_: Exception) {
            null
        }
    }

    private fun tokenFile(context: Context): File {
        return File(
            context.filesDir,
            TOKEN_FILE_NAME
        )
    }
}
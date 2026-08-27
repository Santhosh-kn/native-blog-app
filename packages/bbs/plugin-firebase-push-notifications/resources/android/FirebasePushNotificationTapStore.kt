package com.bbs.plugins.firebase_push_notifications

import android.content.Context
import android.util.AtomicFile
import org.json.JSONObject
import java.io.File
import java.nio.charset.StandardCharsets
import java.util.UUID

internal object FirebasePushNotificationTapStore {

    private const val CONTRACT_VERSION = 1

    private const val VERSION_KEY =
        "navigation_version"

    private const val DESTINATION_KEY =
        "navigation_destination"

    private const val RESOURCE_ID_KEY =
        "navigation_resource_id"

    private const val HOME_DESTINATION =
        "home"

    private const val POST_EDIT_DESTINATION =
        "post_edit"

    private const val DIRECTORY_NAME =
        "firebase_push_notification_taps"

    private const val MAX_AGE_MILLIS =
        7L * 24L * 60L * 60L * 1000L

    private val simpleDestinations = setOf(
        HOME_DESTINATION,
        "posts",
        "post_create",
        "push_settings"
    )

    private val tapIdPattern = Regex(
        "^[0-9a-fA-F]{8}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{12}$"
    )

    fun create(
        context: Context,
        data: Map<String, String>
    ): String {
        val directory = tapDirectory(context)

        if (
            !directory.exists() &&
            !directory.mkdirs()
        ) {
            throw IllegalStateException(
                "Unable to create the notification tap directory"
            )
        }

        deleteExpiredFiles(directory)

        val tapId = UUID.randomUUID().toString()
        val payload = normalizedPayload(data)
        val atomicFile = AtomicFile(
            File(directory, "$tapId.json")
        )

        val outputStream = atomicFile.startWrite()

        try {
            outputStream.write(
                payload.toString().toByteArray(
                    StandardCharsets.UTF_8
                )
            )

            atomicFile.finishWrite(outputStream)
        } catch (exception: Exception) {
            atomicFile.failWrite(outputStream)

            throw exception
        }

        return tapId
    }

    fun readableFile(
        context: Context,
        tapId: String
    ): File? {
        if (!tapIdPattern.matches(tapId)) {
            return null
        }

        return File(
            tapDirectory(context),
            "$tapId.json"
        ).takeIf {
            it.isFile && it.canRead()
        }
    }

    private fun normalizedPayload(
        data: Map<String, String>
    ): JSONObject {
        if (
            data[VERSION_KEY]?.trim() !=
                CONTRACT_VERSION.toString()
        ) {
            return homePayload()
        }

        val destination =
            data[DESTINATION_KEY]?.trim()

        if (destination in simpleDestinations) {
            return JSONObject()
                .put("version", CONTRACT_VERSION)
                .put("destination", destination)
        }

        if (destination == POST_EDIT_DESTINATION) {
            val resourceId =
                data[RESOURCE_ID_KEY]
                    ?.trim()
                    ?.toLongOrNull()

            if (resourceId !== null && resourceId > 0) {
                return JSONObject()
                    .put("version", CONTRACT_VERSION)
                    .put(
                        "destination",
                        POST_EDIT_DESTINATION
                    )
                    .put("resource_id", resourceId)
            }
        }

        return homePayload()
    }

    private fun homePayload(): JSONObject {
        return JSONObject()
            .put("version", CONTRACT_VERSION)
            .put("destination", HOME_DESTINATION)
    }

    private fun tapDirectory(
        context: Context
    ): File {
        return File(
            context.filesDir,
            DIRECTORY_NAME
        )
    }

    private fun deleteExpiredFiles(
        directory: File
    ) {
        val cutoff =
            System.currentTimeMillis() -
                MAX_AGE_MILLIS

        directory.listFiles()
            ?.filter {
                it.isFile &&
                    it.lastModified() < cutoff
            }
            ?.forEach { file ->
                file.delete()
            }
    }
}
package com.bbs.plugins.native_background_transfer

import android.content.Context
import java.io.File

internal data class NativeBackgroundTransferFiles(
    val partialFile: File,
    val finalFile: File
)

internal class NativeBackgroundTransferFilePolicy(
    context: Context
) {

    private val applicationContext =
        context.applicationContext

    private val downloadsDirectory =
        File(
            applicationContext.filesDir,
            DOWNLOAD_DIRECTORY_NAME
        )

    fun filesFor(
        transferId: String,
        mimeType: String
    ): NativeBackgroundTransferFiles? {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(transferId)
                ?: return null

        if (normalizedId != transferId) {
            return null
        }

        val normalizedMimeType =
            NativeBackgroundTransferContract
                .normalizeConcreteMimeType(
                    mimeType
                )
                ?: return null

        if (!ensureDownloadsDirectory()) {
            return null
        }

        val extension =
            extensionForMimeType(
                normalizedMimeType
            )

        val partialFile = safeChild(
            ".$transferId.part"
        ) ?: return null

        val finalFile = safeChild(
            "$transferId.$extension"
        ) ?: return null

        if (
            partialFile == finalFile ||
            finalFile.exists()
        ) {
            return null
        }

        return NativeBackgroundTransferFiles(
            partialFile = partialFile,
            finalFile = finalFile
        )
    }

    fun preparePartial(
        files: NativeBackgroundTransferFiles
    ): Boolean {
        if (
            !isManagedFile(files.partialFile) ||
            !isManagedFile(files.finalFile) ||
            files.finalFile.exists()
        ) {
            return false
        }

        return try {
            if (files.partialFile.exists()) {
                files.partialFile.delete() &&
                    files.partialFile.createNewFile()
            } else {
                files.partialFile.createNewFile()
            }
        } catch (_: Exception) {
            false
        }
    }

    fun promote(
        files: NativeBackgroundTransferFiles
    ): Boolean {
        if (
            !isManagedFile(files.partialFile) ||
            !isManagedFile(files.finalFile) ||
            !files.partialFile.isFile ||
            !files.partialFile.canRead() ||
            files.finalFile.exists()
        ) {
            return false
        }

        return try {
            files.partialFile.renameTo(
                files.finalFile
            ) &&
                files.finalFile.isFile &&
                files.finalFile.canRead()
        } catch (_: Exception) {
            false
        }
    }

    fun deletePartial(
        files: NativeBackgroundTransferFiles
    ): Boolean {
        if (!isManagedFile(files.partialFile)) {
            return false
        }

        return try {
            !files.partialFile.exists() ||
                files.partialFile.delete()
        } catch (_: Exception) {
            false
        }
    }

    fun deleteFinal(
        files: NativeBackgroundTransferFiles
    ): Boolean {
        if (!isManagedFile(files.finalFile)) {
            return false
        }

        return try {
            !files.finalFile.exists() ||
                files.finalFile.delete()
        } catch (_: Exception) {
            false
        }
    }
    fun finalSize(
        files: NativeBackgroundTransferFiles
    ): Long? {
        if (
            !isManagedFile(files.finalFile) ||
            !files.finalFile.isFile ||
            !files.finalFile.canRead()
        ) {
            return null
        }

        return try {
            files.finalFile.length()
                .takeIf {
                    it >= 0L
                }
        } catch (_: Exception) {
            null
        }
    }

    fun deleteFinalFor(
        transferId: String,
        mimeType: String
    ): Boolean {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    transferId
                )
                ?: return false

        if (normalizedId != transferId) {
            return false
        }

        val normalizedMimeType =
            NativeBackgroundTransferContract
                .normalizeConcreteMimeType(
                    mimeType
                )
                ?: return false

        if (!ensureDownloadsDirectory()) {
            return false
        }

        val extension =
            extensionForMimeType(
                normalizedMimeType
            )

        val finalFile =
            safeChild(
                "$transferId.$extension"
            )
                ?: return false

        if (!isManagedFile(finalFile)) {
            return false
        }

        return try {
            !finalFile.exists() ||
                finalFile.delete()
        } catch (_: Exception) {
            false
        }
    }
    private fun ensureDownloadsDirectory(): Boolean {
        return try {
            if (downloadsDirectory.exists()) {
                downloadsDirectory.isDirectory &&
                    downloadsDirectory.canRead() &&
                    downloadsDirectory.canWrite()
            } else {
                downloadsDirectory.mkdirs() &&
                    downloadsDirectory.isDirectory &&
                    downloadsDirectory.canRead() &&
                    downloadsDirectory.canWrite()
            }
        } catch (_: SecurityException) {
            false
        }
    }

    private fun safeChild(
        fileName: String
    ): File? {
        if (
            fileName.isBlank() ||
            fileName.contains('/') ||
            fileName.contains('\\')
        ) {
            return null
        }

        val candidate =
            File(
                downloadsDirectory,
                fileName
            )

        return try {
            val directory =
                downloadsDirectory.canonicalFile

            val canonical =
                candidate.canonicalFile

            if (
                canonical.parentFile?.path !=
                directory.path
            ) {
                null
            } else {
                canonical
            }
        } catch (_: Exception) {
            null
        }
    }

    private fun isManagedFile(
        file: File
    ): Boolean {
        return try {
            val directory =
                downloadsDirectory.canonicalFile

            val canonical =
                file.canonicalFile

            canonical.parentFile?.path ==
                directory.path
        } catch (_: Exception) {
            false
        }
    }

    private fun extensionForMimeType(
        mimeType: String
    ): String {
        return when (mimeType) {
            "application/pdf" ->
                "pdf"

            "text/plain" ->
                "txt"

            "text/csv" ->
                "csv"

            "image/jpeg" ->
                "jpg"

            "image/png" ->
                "png"

            "image/webp" ->
                "webp"

            "application/zip" ->
                "zip"

            "application/msword" ->
                "doc"

            "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ->
                "docx"

            else ->
                "bin"
        }
    }

    private companion object {
        const val DOWNLOAD_DIRECTORY_NAME =
            "native_background_transfer_downloads"
    }
}
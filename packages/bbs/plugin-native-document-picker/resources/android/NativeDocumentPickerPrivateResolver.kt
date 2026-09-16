package com.bbs.plugins.native_document_picker

import android.content.Context
import java.io.File
import java.io.IOException

enum class NativeDocumentPickerPrivateSourceFailure {
    INVALID_DOCUMENT_ID,
    RESULT_NOT_FOUND,
    RESULT_NOT_SUCCEEDED,
    INVALID_METADATA,
    FILE_UNAVAILABLE
}

sealed interface NativeDocumentPickerPrivateSourceResult {

    data class Resolved(
        val documentId: String,
        val file: File,
        val originalName: String,
        val mimeType: String,
        val size: Long
    ) : NativeDocumentPickerPrivateSourceResult

    data class Rejected(
        val reason: NativeDocumentPickerPrivateSourceFailure
    ) : NativeDocumentPickerPrivateSourceResult
}

object NativeDocumentPickerPrivateResolver {

    private const val MAX_EXTENSION_LENGTH = 16

    private val safeExtensionPattern =
        Regex("^[a-z0-9]{1,$MAX_EXTENSION_LENGTH}$")

    fun resolve(
        context: Context,
        documentId: String
    ): NativeDocumentPickerPrivateSourceResult {
        val normalizedId =
            NativeDocumentPickerContract.normalizeRequestId(documentId)
                ?: return rejected(
                    NativeDocumentPickerPrivateSourceFailure.INVALID_DOCUMENT_ID
                )

        if (normalizedId != documentId) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_DOCUMENT_ID
            )
        }

        val result = try {
            NativeDocumentPickerStore(context).result(normalizedId)
        } catch (_: RuntimeException) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE
            )
        } ?: return rejected(
            NativeDocumentPickerPrivateSourceFailure.RESULT_NOT_FOUND
        )

        if (
            result.id != normalizedId ||
            result.status != NativeDocumentPickerContract.STATUS_SUCCEEDED ||
            !result.success ||
            result.cancelled
        ) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.RESULT_NOT_SUCCEEDED
            )
        }

        val storedPath = result.path
            ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )

        val originalName = result.originalName
            ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )

        val mimeType =
            NativeDocumentPickerContract.normalizeConcreteMimeType(
                result.mimeType
            ) ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )

        if (mimeType != result.mimeType) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )
        }

        val storedSize = result.size
            ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )

        if (
            storedSize < 0L ||
            storedSize > NativeDocumentPickerContract.MAX_CONFIGURABLE_SIZE
        ) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )
        }

        val applicationRoot = canonicalApplicationRoot(context)
            ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE
            )

        val file = canonicalFile(storedPath)
            ?: return rejected(
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE
            )

        if (
            file.path != storedPath ||
            !isStrictlyInside(applicationRoot, file) ||
            !hasExpectedFileName(file, normalizedId)
        ) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )
        }

        if (
            !file.exists() ||
            !file.isFile ||
            !file.canRead()
        ) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE
            )
        }

        val actualSize = try {
            file.length()
        } catch (_: SecurityException) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE
            )
        }

        if (actualSize != storedSize) {
            return rejected(
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA
            )
        }

        return NativeDocumentPickerPrivateSourceResult.Resolved(
            documentId = normalizedId,
            file = file,
            originalName = originalName,
            mimeType = mimeType,
            size = storedSize
        )
    }

    private fun canonicalApplicationRoot(
        context: Context
    ): File? {
        return try {
            File(context.applicationInfo.dataDir)
                .canonicalFile
                .takeIf { root -> root.isDirectory }
        } catch (_: IOException) {
            null
        } catch (_: SecurityException) {
            null
        }
    }

    private fun canonicalFile(path: String): File? {
        return try {
            File(path).canonicalFile
        } catch (_: IOException) {
            null
        } catch (_: SecurityException) {
            null
        }
    }

    private fun isStrictlyInside(
        root: File,
        candidate: File
    ): Boolean {
        val prefix =
            root.path.trimEnd(File.separatorChar) + File.separator

        return candidate.path.startsWith(prefix)
    }

    private fun hasExpectedFileName(
        file: File,
        documentId: String
    ): Boolean {
        if (file.name == documentId) {
            return true
        }

        val prefix = "$documentId."

        if (!file.name.startsWith(prefix)) {
            return false
        }

        val extension = file.name.removePrefix(prefix)

        return safeExtensionPattern.matches(extension)
    }

    private fun rejected(
        reason: NativeDocumentPickerPrivateSourceFailure
    ): NativeDocumentPickerPrivateSourceResult.Rejected {
        return NativeDocumentPickerPrivateSourceResult.Rejected(
            reason = reason
        )
    }
}
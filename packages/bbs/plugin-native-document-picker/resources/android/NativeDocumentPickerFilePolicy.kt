package com.bbs.plugins.native_document_picker

import android.content.Context
import android.webkit.MimeTypeMap
import java.io.File
import java.io.IOException
import java.util.Locale

internal sealed interface NativeDocumentPickerDestinationResult {

    data class Ready(
        val directory: File,
        val partialFile: File,
        val finalFile: File
    ) : NativeDocumentPickerDestinationResult

    data class Rejected(
        val errorCode: String
    ) : NativeDocumentPickerDestinationResult
}

internal object NativeDocumentPickerFilePolicy {

    private const val MAX_INSPECTED_FILE_NAME_CODE_POINTS = 1024
    private const val MAX_EXTENSION_LENGTH = 16

    private val safeExtensionPattern = Regex("^[a-z0-9]{1,$MAX_EXTENSION_LENGTH}$")

    fun prepareDestination(context: Context, request: NativeDocumentPickerRequest, mimeType: String): NativeDocumentPickerDestinationResult {
        val normalizedRequestId = NativeDocumentPickerContract.normalizeRequestId(request.id)

        val normalizedDestination = NativeDocumentPickerContract.normalizePath(request.destinationPath)

        val normalizedMimeType = NativeDocumentPickerContract.normalizeConcreteMimeType(mimeType)

        if (normalizedRequestId != request.id || normalizedDestination != request.destinationPath || normalizedMimeType != mimeType ) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION)
        }

        val applicationRoot = canonicalApplicationRoot(context)?: return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)

        val requestedDirectory = try {
            File(request.destinationPath).canonicalFile
        } catch (_: IOException) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION)
        } catch (_: SecurityException) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION)
        }

        if (!isStrictlyInside(applicationRoot, requestedDirectory)) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION)
        }

        if (requestedDirectory.exists() && !requestedDirectory.isDirectory ) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION)
        }

        if (!requestedDirectory.exists() && !requestedDirectory.mkdirs() ) {
            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        }

        val directory = try {
            requestedDirectory.canonicalFile
        } catch (_: IOException) {
            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        } catch (_: SecurityException) {
            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        }

        if (!directory.isDirectory || !directory.canWrite() || !isStrictlyInside(applicationRoot, directory)) {
            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        }

        val extension = safeExtension(normalizedMimeType)
        val finalName = if (extension == null) {
            request.id
        } else {
            "${request.id}.$extension"
        }

        val finalFile = canonicalChild(directory, finalName)?: return rejected( NativeDocumentPickerContract.INVALID_DESTINATION )

        val partialFile = canonicalChild(
            directory,
            ".${request.id}.part"
        ) ?: return rejected(
            NativeDocumentPickerContract.INVALID_DESTINATION
        )

        if (finalFile.exists()) {
            return rejected(
                NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED
            )
        }

        return NativeDocumentPickerDestinationResult.Ready(
            directory = directory,
            partialFile = partialFile,
            finalFile = finalFile
        )
    }

    fun safeOriginalName(value: String?, mimeType: String): String {
        val fallback = fallbackName(mimeType)

        if (value.isNullOrEmpty()) {
            return fallback
        }

        val sanitized = StringBuilder()
        var index = 0
        var inspected = 0
        var accepted = 0

        while (
            index < value.length &&
            inspected < MAX_INSPECTED_FILE_NAME_CODE_POINTS &&
            accepted < NativeDocumentPickerContract.MAX_FILE_NAME_CODE_POINTS
        ) {
            val codePoint = value.codePointAt(index)
            val characterCount = Character.charCount(codePoint)

            if (characterCount == 2 && index + 1 >= value.length) {
                break
            }

            if (isUnsafeFileNameCodePoint(codePoint)) {
                sanitized.append('_')
            } else {
                sanitized.appendCodePoint(codePoint)
            }

            index += characterCount
            inspected++
            accepted++
        }

        val candidate = sanitized.toString().trim(' ', '.')

        if (candidate.isEmpty() || candidate == "." || candidate == "..") {
            return fallback
        }

        return candidate
    }

    fun discardPrivateFile(context: Context, file: File ): Boolean {
        val applicationRoot = canonicalApplicationRoot(context) ?: return false

        val candidate = try {
            file.canonicalFile
        } catch (_: IOException) {
            return false
        } catch (_: SecurityException) {
            return false
        }

        if (!isStrictlyInside(applicationRoot, candidate) || !candidate.exists() || !candidate.isFile) {
            return false
        }

        return try {
            candidate.delete()
        } catch (_: SecurityException) {
            false
        }
    }

    private fun canonicalApplicationRoot(context: Context): File? {
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

    private fun canonicalChild(
        directory: File,
        name: String
    ): File? {
        val child = try {
            File(directory, name).canonicalFile
        } catch (_: IOException) {
            return null
        } catch (_: SecurityException) {
            return null
        }

        if (child.parentFile?.path != directory.path) {
            return null
        }

        return child
    }

    private fun isStrictlyInside(root: File, candidate: File): Boolean {
        return candidate.path.startsWith(root.path.trimEnd(File.separatorChar) + File.separator)
    }

    private fun fallbackName(mimeType: String): String {
        val extension = safeExtension(mimeType)

        return if (extension == null) {
            "document"
        } else {
            "document.$extension"
        }
    }

    private fun safeExtension(mimeType: String): String? {
        val knownExtension = when (mimeType) {
            "application/pdf" -> "pdf"
            "text/plain" -> "txt"
            "application/msword" -> "doc"
            "application/vnd.openxmlformats-officedocument." +
                "wordprocessingml.document" -> "docx"
            else -> null
        }

        val candidate = knownExtension ?: MimeTypeMap.getSingleton() .getExtensionFromMimeType(mimeType) ?.lowercase(Locale.ROOT)
        return candidate?.takeIf(safeExtensionPattern::matches)
    }

    private fun isUnsafeFileNameCodePoint(codePoint: Int): Boolean {
        if (codePoint == '/'.code || codePoint == '\\'.code || codePoint == '<'.code || codePoint == '>'.code || codePoint == ':'.code ||
            codePoint == '"'.code || codePoint == '|'.code || codePoint == '?'.code || codePoint == '*'.code || Character.isISOControl(codePoint)
        ) {
            return true
        }
        return when (Character.getType(codePoint)) {
            Character.FORMAT.toInt(),
            Character.LINE_SEPARATOR.toInt(),
            Character.PARAGRAPH_SEPARATOR.toInt(),
            Character.PRIVATE_USE.toInt(),
            Character.SURROGATE.toInt(),
            Character.UNASSIGNED.toInt() -> true
            else -> false
        }
    }

    private fun rejected(
        errorCode: String
    ): NativeDocumentPickerDestinationResult.Rejected {
        return NativeDocumentPickerDestinationResult.Rejected(
            errorCode = errorCode
        )
    }
}
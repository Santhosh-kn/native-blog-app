package com.bbs.plugins.native_document_picker

import android.content.ContentResolver
import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import java.io.File
import java.io.FileNotFoundException
import java.io.FileOutputStream
import java.io.IOException

internal sealed interface NativeDocumentPickerCopyResult {

    data class Copied(
        val file: File,
        val originalName: String,
        val mimeType: String,
        val size: Long
    ) : NativeDocumentPickerCopyResult

    data class Rejected( val errorCode: String ) : NativeDocumentPickerCopyResult
}

private data class NativeDocumentPickerMetadata(
    val displayName: String?,
    val declaredSize: Long?
)

private sealed interface NativeDocumentPickerMimeTypeResult {

    data class Read(val mimeType: String ) : NativeDocumentPickerMimeTypeResult

    data object Unsupported : NativeDocumentPickerMimeTypeResult

    data object Unreadable : NativeDocumentPickerMimeTypeResult
}

private sealed interface NativeDocumentPickerMetadataResult {

    data class Read(val metadata: NativeDocumentPickerMetadata ) : NativeDocumentPickerMetadataResult

    data object Unreadable : NativeDocumentPickerMetadataResult
}

private sealed interface NativeDocumentPickerStreamResult {

    data class Copied(val size: Long ) : NativeDocumentPickerStreamResult

    data object TooLarge : NativeDocumentPickerStreamResult

    data object SourceUnreadable : NativeDocumentPickerStreamResult

    data object PrivateStorageFailed : NativeDocumentPickerStreamResult

    data object CopyFailed : NativeDocumentPickerStreamResult
}

internal class NativeDocumentPickerCopier(context: Context) {

    private val applicationContext = context.applicationContext
    private val contentResolver: ContentResolver = applicationContext.contentResolver

    fun copy(
        uri: Uri,
        request: NativeDocumentPickerRequest
    ): NativeDocumentPickerCopyResult {
        if (uri.scheme != ContentResolver.SCHEME_CONTENT) {
            return rejected(NativeDocumentPickerContract.SOURCE_UNREADABLE )
        }

        val mimeType = when (val result = readMimeType(uri)) {
            is NativeDocumentPickerMimeTypeResult.Read -> result.mimeType
            NativeDocumentPickerMimeTypeResult.Unsupported -> return rejected(NativeDocumentPickerContract.UNSUPPORTED_MIME_TYPE)

            NativeDocumentPickerMimeTypeResult.Unreadable -> return rejected(NativeDocumentPickerContract.SOURCE_UNREADABLE )
        }

        if (!NativeDocumentPickerContract.isMimeTypeAllowed(mimeType = mimeType, allowedMimeTypes = request.mimeTypes )) {
            return rejected(NativeDocumentPickerContract.UNSUPPORTED_MIME_TYPE )
        }

        val metadata = when (val result = readMetadata(uri)) {
            is NativeDocumentPickerMetadataResult.Read -> result.metadata
            NativeDocumentPickerMetadataResult.Unreadable -> return rejected(NativeDocumentPickerContract.SOURCE_UNREADABLE )
        }

        if (metadata.declaredSize != null && metadata.declaredSize > request.maxSize ) {
            return rejected(NativeDocumentPickerContract.FILE_TOO_LARGE )
        }

        val destination = when (
            val result = NativeDocumentPickerFilePolicy.prepareDestination(context = applicationContext, request = request, mimeType = mimeType )) {
            is NativeDocumentPickerDestinationResult.Ready -> result
            is NativeDocumentPickerDestinationResult.Rejected -> return rejected(result.errorCode)
        }

        if (
            destination.partialFile.name != ".${request.id}.part" ||
            !hasExpectedFinalName(file = destination.finalFile, requestId = request.id )
        ) {
            return rejected(NativeDocumentPickerContract.INVALID_DESTINATION )
        }

        if (!preparePartialFile(destination.partialFile)) {
            return rejected( NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED )
        }

        val streamResult = streamToPrivateFile(uri = uri, partialFile = destination.partialFile, maximumSize = request.maxSize )

        if (streamResult !is NativeDocumentPickerStreamResult.Copied) {
            discardIfPresent(destination.partialFile)

            return when (streamResult) {
                NativeDocumentPickerStreamResult.TooLarge -> rejected(NativeDocumentPickerContract.FILE_TOO_LARGE)

                NativeDocumentPickerStreamResult.SourceUnreadable -> rejected(NativeDocumentPickerContract.SOURCE_UNREADABLE)

                NativeDocumentPickerStreamResult.PrivateStorageFailed -> rejected( NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED )

                NativeDocumentPickerStreamResult.CopyFailed -> rejected(NativeDocumentPickerContract.COPY_FAILED)

                is NativeDocumentPickerStreamResult.Copied -> rejected(NativeDocumentPickerContract.UNKNOWN_ERROR)
            }
        }

        if (destination.partialFile.length() != streamResult.size) {
            discardIfPresent(destination.partialFile)

            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED )
        }

        if (!promote( partialFile = destination.partialFile, finalFile = destination.finalFile ) ) {
            discardIfPresent(destination.partialFile)

            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        }

        if (!destination.finalFile.isFile || !destination.finalFile.canRead() || destination.finalFile.length() != streamResult.size ) {
            discardIfPresent(destination.finalFile)

            return rejected(NativeDocumentPickerContract.PRIVATE_STORAGE_FAILED)
        }

        return NativeDocumentPickerCopyResult.Copied(
            file = destination.finalFile,
            originalName = NativeDocumentPickerFilePolicy.safeOriginalName(value = metadata.displayName, mimeType = mimeType ),
            mimeType = mimeType,
            size = streamResult.size
        )
    }

    fun discard(file: File): Boolean {
        return NativeDocumentPickerFilePolicy.discardPrivateFile(context = applicationContext, file = file)
    }

    private fun readMimeType(uri: Uri ): NativeDocumentPickerMimeTypeResult {
        val value = try {
            contentResolver.getType(uri)
        } catch (_: SecurityException) {
            return NativeDocumentPickerMimeTypeResult.Unreadable
        } catch (_: RuntimeException) {
            return NativeDocumentPickerMimeTypeResult.Unreadable
        }

        val mimeType = NativeDocumentPickerContract.normalizeConcreteMimeType(value) ?: return NativeDocumentPickerMimeTypeResult.Unsupported

        return NativeDocumentPickerMimeTypeResult.Read(mimeType)
    }

    private fun readMetadata(uri: Uri ): NativeDocumentPickerMetadataResult {
        return try {
            val cursor = contentResolver.query(
                uri,
                arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE ),
                null,
                null,
                null
            )

            if (cursor == null) {
                return NativeDocumentPickerMetadataResult.Read(NativeDocumentPickerMetadata(displayName = null, declaredSize = null ) )
            }

            cursor.use { openCursor ->
                if (!openCursor.moveToFirst()) {
                    return NativeDocumentPickerMetadataResult.Read(
                        NativeDocumentPickerMetadata(
                            displayName = null,
                            declaredSize = null
                        )
                    )
                }

                val nameIndex = openCursor.getColumnIndex(OpenableColumns.DISPLAY_NAME )
                val sizeIndex = openCursor.getColumnIndex(OpenableColumns.SIZE)

                val displayName = if (nameIndex >= 0 && !openCursor.isNull(nameIndex)) {
                    openCursor.getString(nameIndex)
                } else {
                    null
                }

                val declaredSize = if (sizeIndex >= 0 && !openCursor.isNull(sizeIndex) ) {
                    openCursor.getLong(sizeIndex).takeIf { size -> size >= 0L }
                } else {
                    null
                }

                NativeDocumentPickerMetadataResult.Read(
                    NativeDocumentPickerMetadata(displayName = displayName, declaredSize = declaredSize )
                )
            }
        } catch (_: SecurityException) {
            NativeDocumentPickerMetadataResult.Unreadable
        } catch (_: RuntimeException) {
            NativeDocumentPickerMetadataResult.Unreadable
        }
    }

    private fun preparePartialFile(partialFile: File): Boolean {
        return try {
            if (partialFile.exists() && !discardIfPresent(partialFile) ) {
                return false
            }

            partialFile.createNewFile()
        } catch (_: IOException) {
            false
        } catch (_: SecurityException) {
            false
        }
    }

    private fun streamToPrivateFile(
        uri: Uri,
        partialFile: File,
        maximumSize: Long
    ): NativeDocumentPickerStreamResult {
        val input = try {
            contentResolver.openInputStream(uri)
        } catch (_: FileNotFoundException) {
            return NativeDocumentPickerStreamResult.SourceUnreadable
        } catch (_: SecurityException) {
            return NativeDocumentPickerStreamResult.SourceUnreadable
        } catch (_: RuntimeException) {
            return NativeDocumentPickerStreamResult.SourceUnreadable
        } ?: return NativeDocumentPickerStreamResult.SourceUnreadable

        val output = try {
            FileOutputStream(partialFile, false)
        } catch (_: FileNotFoundException) {
            try {
                input.close()
            } catch (_: Exception) {
                // The source descriptor is already unusable.
            }

            return NativeDocumentPickerStreamResult.PrivateStorageFailed
        } catch (_: SecurityException) {
            try {
                input.close()
            } catch (_: Exception) {
                // The source descriptor is already unusable.
            }

            return NativeDocumentPickerStreamResult.PrivateStorageFailed
        }

        return try {
            input.use { source ->
                output.use { destination ->
                    val buffer = ByteArray(BUFFER_SIZE)
                    var copiedSize = 0L
                    var consecutiveEmptyReads = 0

                    while (true) {
                        if (Thread.currentThread().isInterrupted) {
                            return NativeDocumentPickerStreamResult.CopyFailed
                        }

                        val count = source.read(buffer)

                        if (count < 0) {
                            break
                        }

                        if (count == 0) {
                            consecutiveEmptyReads++

                            if (consecutiveEmptyReads > MAX_CONSECUTIVE_EMPTY_READS ) {
                                return NativeDocumentPickerStreamResult.CopyFailed
                            }

                            continue
                        }

                        consecutiveEmptyReads = 0

                        if (copiedSize > maximumSize - count.toLong() ) {
                            return NativeDocumentPickerStreamResult.TooLarge
                        }

                        destination.write(buffer, 0, count)
                        copiedSize += count.toLong()
                    }

                    destination.flush()
                    destination.fd.sync()

                    NativeDocumentPickerStreamResult.Copied( size = copiedSize )
                }
            }
        } catch (_: IOException) {
            NativeDocumentPickerStreamResult.CopyFailed
        } catch (_: SecurityException) {
            NativeDocumentPickerStreamResult.CopyFailed
        } catch (_: RuntimeException) {
            NativeDocumentPickerStreamResult.CopyFailed
        }
    }

    private fun promote(partialFile: File, finalFile: File ): Boolean {
        return try {
            !finalFile.exists() &&
                partialFile.renameTo(finalFile)
        } catch (_: SecurityException) {
            false
        }
    }

    private fun hasExpectedFinalName(file: File, requestId: String ): Boolean {
        return file.name == requestId || file.name.startsWith("$requestId.")
    }

    private fun discardIfPresent(file: File): Boolean {
        if (!file.exists()) {
            return true
        }

        return discard(file)
    }

    private fun rejected(
        errorCode: String
    ): NativeDocumentPickerCopyResult.Rejected {
        return NativeDocumentPickerCopyResult.Rejected(
            errorCode = errorCode
        )
    }

    private companion object {
        const val BUFFER_SIZE = 64 * 1024
        const val MAX_CONSECUTIVE_EMPTY_READS = 16
    }
}

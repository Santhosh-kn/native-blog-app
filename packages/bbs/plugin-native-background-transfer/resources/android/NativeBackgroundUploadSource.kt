package com.bbs.plugins.native_background_transfer

import android.content.Context
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateResolver
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateSourceFailure
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateSourceResult
import java.io.File

internal sealed interface NativeBackgroundUploadSourceResolution {

    data class Resolved(
        val documentId: String,
        val file: File,
        val displayName: String,
        val mimeType: String,
        val size: Long
    ) : NativeBackgroundUploadSourceResolution

    data class Rejected(
        val errorCode: String
    ) : NativeBackgroundUploadSourceResolution
}

internal object NativeBackgroundUploadSource {

    fun resolve(
        context: Context,
        request: NativeBackgroundUploadRequest
    ): NativeBackgroundUploadSourceResolution {
        val source =
            NativeDocumentPickerPrivateResolver.resolve(
                context = context,
                documentId = request.sourceDocumentId
            )

        return when (source) {
            is NativeDocumentPickerPrivateSourceResult.Resolved ->
                resolved(
                    request = request,
                    source = source
                )

            is NativeDocumentPickerPrivateSourceResult.Rejected ->
                rejected(source.reason)
        }
    }

    private fun resolved(
        request: NativeBackgroundUploadRequest,
        source: NativeDocumentPickerPrivateSourceResult.Resolved
    ): NativeBackgroundUploadSourceResolution {
        if (
            source.documentId != request.sourceDocumentId
        ) {
            return NativeBackgroundUploadSourceResolution.Rejected(
                NativeBackgroundTransferContract
                    .INVALID_SOURCE_DOCUMENT_ID
            )
        }

        if (source.size > request.maxSize) {
            return NativeBackgroundUploadSourceResolution.Rejected(
                NativeBackgroundTransferContract.FILE_TOO_LARGE
            )
        }

        val displayName =
            NativeBackgroundTransferContract
                .safeDisplayName(source.originalName)
                ?: return NativeBackgroundUploadSourceResolution.Rejected(
                    NativeBackgroundTransferContract
                        .SOURCE_DOCUMENT_UNAVAILABLE
                )

        val mimeType =
            NativeBackgroundTransferContract
                .normalizeConcreteMimeType(source.mimeType)
                ?: return NativeBackgroundUploadSourceResolution.Rejected(
                    NativeBackgroundTransferContract
                        .SOURCE_DOCUMENT_UNAVAILABLE
                )

        return NativeBackgroundUploadSourceResolution.Resolved(
            documentId = source.documentId,
            file = source.file,
            displayName = displayName,
            mimeType = mimeType,
            size = source.size
        )
    }

    private fun rejected(
        reason: NativeDocumentPickerPrivateSourceFailure
    ): NativeBackgroundUploadSourceResolution.Rejected {
        val errorCode =
            when (reason) {
                NativeDocumentPickerPrivateSourceFailure.INVALID_DOCUMENT_ID ->
                    NativeBackgroundTransferContract
                        .INVALID_SOURCE_DOCUMENT_ID

                NativeDocumentPickerPrivateSourceFailure.RESULT_NOT_FOUND,
                NativeDocumentPickerPrivateSourceFailure.RESULT_NOT_SUCCEEDED,
                NativeDocumentPickerPrivateSourceFailure.INVALID_METADATA,
                NativeDocumentPickerPrivateSourceFailure.FILE_UNAVAILABLE ->
                    NativeBackgroundTransferContract
                        .SOURCE_DOCUMENT_UNAVAILABLE
            }

        return NativeBackgroundUploadSourceResolution.Rejected(
            errorCode
        )
    }
}
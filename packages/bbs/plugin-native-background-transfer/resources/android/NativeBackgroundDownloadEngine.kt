package com.bbs.plugins.native_background_transfer

import android.content.Context
import java.io.FileOutputStream
import java.io.IOException
import java.net.URI
import java.net.URL
import java.net.URLDecoder
import java.nio.charset.StandardCharsets
import javax.net.ssl.HttpsURLConnection

internal sealed interface NativeBackgroundDownloadOutcome {

    data class Succeeded(
        val result: NativeBackgroundTransferResult
    ) : NativeBackgroundDownloadOutcome

    data class Failed(
        val errorCode: String,
        val transferredBytes: Long = 0L,
        val totalBytes: Long? = null,
        val progress: Int? = null
    ) : NativeBackgroundDownloadOutcome

    data class Cancelled(
        val transferredBytes: Long = 0L,
        val totalBytes: Long? = null,
        val progress: Int? = null
    ) : NativeBackgroundDownloadOutcome
}

internal class NativeBackgroundDownloadEngine(
    context: Context
) {

    private val filePolicy =
        NativeBackgroundTransferFilePolicy(
            context.applicationContext
        )

    private val contentValidator =
        NativeBackgroundTransferContentValidator()

    fun download(
        request: NativeBackgroundTransferRequest,
        isCancelled: () -> Boolean = { false },
        onProgress: (
            transferredBytes: Long,
            totalBytes: Long?,
            progress: Int?
        ) -> Unit = { _, _, _ -> }
    ): NativeBackgroundDownloadOutcome {
        var currentUrl = request.url
        var redirectCount = 0

        while (true) {
            if (isCancelled()) {
                return NativeBackgroundDownloadOutcome
                    .Cancelled()
            }

            val validatedUrl = when (
                val validation =
                    NativeBackgroundTransferContract
                        .validateHttpsUrl(
                            currentUrl
                        )
            ) {
                is NativeBackgroundTransferUrlValidation.Valid ->
                    validation.url

                is NativeBackgroundTransferUrlValidation.Invalid ->
                    return NativeBackgroundDownloadOutcome
                        .Failed(
                            errorCode =
                                validation.errorCode
                        )
            }

            val connection = try {
                URL(validatedUrl)
                    .openConnection() as?
                    HttpsURLConnection
            } catch (_: Exception) {
                null
            } ?: return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_URL
                )

            try {
                configureConnection(
                    connection = connection,
                    request = request
                )

                val responseCode =
                    connection.responseCode

                if (isRedirect(responseCode)) {
                    if (redirectCount >= MAX_REDIRECTS) {
                        return NativeBackgroundDownloadOutcome
                            .Failed(
                                errorCode =
                                    NativeBackgroundTransferContract
                                        .HTTP_ERROR
                            )
                    }

                    val location =
                        connection.getHeaderField(
                            "Location"
                        )

                    if (location.isNullOrBlank()) {
                        return NativeBackgroundDownloadOutcome
                            .Failed(
                                errorCode =
                                    NativeBackgroundTransferContract
                                        .HTTP_ERROR
                            )
                    }

                    val redirectedUrl =
                        resolveRedirect(
                            currentUrl = validatedUrl,
                            location = location
                        )
                            ?: return NativeBackgroundDownloadOutcome
                                .Failed(
                                    errorCode =
                                        NativeBackgroundTransferContract
                                            .INVALID_URL
                                )

                    when (
                        val redirectValidation =
                            NativeBackgroundTransferContract
                                .validateHttpsUrl(
                                    redirectedUrl
                                )
                    ) {
                        is NativeBackgroundTransferUrlValidation.Valid -> {
                            currentUrl =
                                redirectValidation.url

                            redirectCount++

                            continue
                        }

                        is NativeBackgroundTransferUrlValidation.Invalid ->
                            return NativeBackgroundDownloadOutcome
                                .Failed(
                                    errorCode =
                                        redirectValidation
                                            .errorCode
                                )
                    }
                }

                if (responseCode != HttpsURLConnection.HTTP_OK) {
                    return NativeBackgroundDownloadOutcome
                        .Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .HTTP_ERROR
                        )
                }

                return streamResponse(
                    request = request,
                    finalUrl = validatedUrl,
                    connection = connection,
                    isCancelled = isCancelled,
                    onProgress = onProgress
                )
            } catch (_: IOException) {
                return NativeBackgroundDownloadOutcome
                    .Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .NETWORK_ERROR
                    )
            } catch (_: SecurityException) {
                return NativeBackgroundDownloadOutcome
                    .Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .NETWORK_ERROR
                    )
            } finally {
                connection.disconnect()
            }
        }
    }

    private fun streamResponse(
        request: NativeBackgroundTransferRequest,
        finalUrl: String,
        connection: HttpsURLConnection,
        isCancelled: () -> Boolean,
        onProgress: (
            transferredBytes: Long,
            totalBytes: Long?,
            progress: Int?
        ) -> Unit
    ): NativeBackgroundDownloadOutcome {
        val mimeType =
            responseMimeType(connection)
                ?: return NativeBackgroundDownloadOutcome
                    .Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .INVALID_CONTENT_TYPE
                    )

        if (
            !NativeBackgroundTransferContract
                .isMimeTypeAllowed(
                    mimeType = mimeType,
                    allowedMimeTypes =
                        request.mimeTypes
                )
        ) {
            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_CONTENT_TYPE
                )
        }

        val declaredSize =
            connection.contentLengthLong
                .takeIf {
                    it >= 0L
                }

        if (
            declaredSize != null &&
            declaredSize > request.maxSize
        ) {
            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .FILE_TOO_LARGE,
                    totalBytes = declaredSize
                )
        }

        if (declaredSize == 0L) {
            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .HTTP_ERROR,
                    totalBytes = 0L
                )
        }

        val files =
            filePolicy.filesFor(
                transferId = request.id,
                mimeType = mimeType
            )
                ?: return NativeBackgroundDownloadOutcome
                    .Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .STORAGE_UNAVAILABLE
                    )

        if (!filePolicy.preparePartial(files)) {
            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .STORAGE_UNAVAILABLE
                )
        }

        var transferredBytes = 0L

        try {
            connection.inputStream.use { input ->
                FileOutputStream(
                    files.partialFile,
                    false
                ).use { output ->
                    val buffer =
                        ByteArray(BUFFER_SIZE)

                    onProgress(
                        transferredBytes,
                        declaredSize,
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                    )

                    while (true) {
                        if (isCancelled()) {
                            filePolicy.deletePartial(files)

                            return NativeBackgroundDownloadOutcome
                                .Cancelled(
                                    transferredBytes =
                                        transferredBytes,
                                    totalBytes =
                                        declaredSize,
                                    progress =
                                        calculateProgress(
                                            transferredBytes,
                                            declaredSize
                                        )
                                )
                        }

                        val count =
                            input.read(buffer)

                        if (count == -1) {
                            break
                        }

                        if (count == 0) {
                            continue
                        }

                        if (
                            count.toLong() >
                            request.maxSize -
                                transferredBytes
                        ) {
                            filePolicy.deletePartial(files)

                            return NativeBackgroundDownloadOutcome
                                .Failed(
                                    errorCode =
                                        NativeBackgroundTransferContract
                                            .FILE_TOO_LARGE,
                                    transferredBytes =
                                        transferredBytes,
                                    totalBytes =
                                        declaredSize,
                                    progress =
                                        calculateProgress(
                                            transferredBytes,
                                            declaredSize
                                        )
                                )
                        }

                        output.write(
                            buffer,
                            0,
                            count
                        )

                        transferredBytes +=
                            count.toLong()

                        onProgress(
                            transferredBytes,
                            declaredSize,
                            calculateProgress(
                                transferredBytes,
                                declaredSize
                            )
                        )
                    }

                    output.flush()
                    output.fd.sync()
                }
            }
        } catch (_: IOException) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .NETWORK_ERROR,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        } catch (_: SecurityException) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .STORAGE_UNAVAILABLE,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        if (transferredBytes <= 0L) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .HTTP_ERROR,
                    transferredBytes = 0L,
                    totalBytes = declaredSize
                )
        }

        if (
            declaredSize != null &&
            transferredBytes != declaredSize
        ) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .NETWORK_ERROR,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        /*
         * Cancellation immediately before content validation.
         * The partial file has not been promoted yet.
         */
        if (isCancelled()) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Cancelled(
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        if (
            !contentValidator.validate(
                file = files.partialFile,
                mimeType = mimeType
            )
        ) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_CONTENT_TYPE,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        /*
         * Validation can take measurable time for container files,
         * so check cancellation once more before promotion.
         */
        if (isCancelled()) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Cancelled(
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        if (!filePolicy.promote(files)) {
            filePolicy.deletePartial(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .STORAGE_UNAVAILABLE,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress =
                        calculateProgress(
                            transferredBytes,
                            declaredSize
                        )
                )
        }

        val finalSize =
            filePolicy.finalSize(files)

        if (
            finalSize == null ||
            finalSize != transferredBytes
        ) {
            filePolicy.deleteFinal(files)

            return NativeBackgroundDownloadOutcome
                .Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .STORAGE_UNAVAILABLE,
                    transferredBytes =
                        transferredBytes,
                    totalBytes =
                        declaredSize,
                    progress = 100
                )
        }

        val displayName =
            safeDisplayName(
                finalUrl
            )

        return NativeBackgroundDownloadOutcome
            .Succeeded(
                NativeBackgroundTransferResult(
                    id = request.id,
                    status =
                        NativeBackgroundTransferContract
                            .STATUS_SUCCEEDED,
                    transferredBytes =
                        finalSize,
                    totalBytes =
                        finalSize,
                    progress = 100,
                    fileId = request.id,
                    displayName = displayName,
                    mimeType = mimeType,
                    size = finalSize,
                    consumed = false
                )
            )
    }

    private fun configureConnection(
        connection: HttpsURLConnection,
        request: NativeBackgroundTransferRequest
    ) {
        connection.requestMethod = "GET"

        connection.instanceFollowRedirects =
            false

        connection.connectTimeout =
            CONNECT_TIMEOUT_MILLISECONDS

        connection.readTimeout =
            READ_TIMEOUT_MILLISECONDS

        connection.useCaches = false
        connection.doInput = true

        connection.setRequestProperty(
            "Accept",
            request.mimeTypes.joinToString(",")
        )

        /*
         * Keep the streamed byte count aligned with the transfer
         * body rather than transparently decoding compressed data.
         */
        connection.setRequestProperty(
            "Accept-Encoding",
            "identity"
        )
    }

    private fun responseMimeType(
        connection: HttpsURLConnection
    ): String? {
        val raw =
            connection.contentType
                ?.substringBefore(';')
                ?.trim()

        return NativeBackgroundTransferContract
            .normalizeConcreteMimeType(
                raw
            )
    }

    private fun resolveRedirect(
        currentUrl: String,
        location: String
    ): String? {
        return try {
            URI(currentUrl)
                .resolve(location.trim())
                .toString()
        } catch (_: Exception) {
            null
        }
    }

    private fun safeDisplayName(
        url: String
    ): String {
        val rawName = try {
            URI(url)
                .rawPath
                ?.substringAfterLast('/')
                ?.takeIf {
                    it.isNotBlank()
                }
        } catch (_: Exception) {
            null
        }

        val decodedName = rawName?.let {
            try {
                URLDecoder.decode(
                    it,
                    StandardCharsets.UTF_8.name()
                )
            } catch (_: Exception) {
                it
            }
        }

        return NativeBackgroundTransferContract
            .safeDisplayName(
                decodedName
            )
            ?: "download"
    }

    private fun calculateProgress(
        transferredBytes: Long,
        totalBytes: Long?
    ): Int? {
        if (
            totalBytes == null ||
            totalBytes <= 0L
        ) {
            return null
        }

        return (
            (transferredBytes * 100L) /
                totalBytes
            )
            .coerceIn(
                0L,
                100L
            )
            .toInt()
    }

    private fun isRedirect(
        responseCode: Int
    ): Boolean {
        return responseCode in setOf(
            HttpsURLConnection.HTTP_MOVED_PERM,
            HttpsURLConnection.HTTP_MOVED_TEMP,
            HttpsURLConnection.HTTP_SEE_OTHER,
            307,
            308
        )
    }

    private companion object {
        const val MAX_REDIRECTS = 5

        const val BUFFER_SIZE =
            64 * 1024

        const val CONNECT_TIMEOUT_MILLISECONDS =
            15_000

        const val READ_TIMEOUT_MILLISECONDS =
            30_000
    }
}
package com.bbs.plugins.native_background_transfer

import java.io.FileInputStream
import java.io.IOException
import java.net.URI
import java.net.URL
import java.util.UUID
import javax.net.ssl.HttpsURLConnection

internal sealed interface NativeBackgroundUploadOutcome {

    data class Succeeded(
        val result: NativeBackgroundTransferResult
    ) : NativeBackgroundUploadOutcome

    data class Failed(
        val errorCode: String,
        val transferredBytes: Long = 0L,
        val totalBytes: Long? = null,
        val progress: Int? = null
    ) : NativeBackgroundUploadOutcome

    data class Cancelled(
        val transferredBytes: Long = 0L,
        val totalBytes: Long? = null,
        val progress: Int? = null
    ) : NativeBackgroundUploadOutcome
}

internal data class NativeBackgroundMultipartBody(
    val contentType: String,
    val contentLength: Long,
    val prefixBytes: ByteArray,
    val suffixBytes: ByteArray
)

internal class NativeBackgroundUploadEngine(
    private val connectionOpener:
        NativeBackgroundTransferConnectionOpener =
            NativeBackgroundTransferConnectionOpener.default()
) {

    fun upload(
        request: NativeBackgroundUploadRequest,
        source: NativeBackgroundUploadSourceResolution.Resolved,
        isCancelled: () -> Boolean = { false },
        onProgress: (
            transferredBytes: Long,
            totalBytes: Long?,
            progress: Int?
        ) -> Unit = { _, _, _ -> },
        onRequestBodyStarting: () -> Boolean = { true }
    ): NativeBackgroundUploadOutcome {
        if (
            request.type !=
                NativeBackgroundTransferContract.TYPE_UPLOAD ||
            source.documentId != request.sourceDocumentId
        ) {
            return NativeBackgroundUploadOutcome.Failed(
                errorCode =
                    NativeBackgroundTransferContract
                        .INVALID_SOURCE_DOCUMENT_ID
            )
        }

        if (
            source.size < 0L ||
            source.size > request.maxSize
        ) {
            return NativeBackgroundUploadOutcome.Failed(
                errorCode =
                    NativeBackgroundTransferContract.FILE_TOO_LARGE,
                totalBytes = source.size
            )
        }

        if (
            !source.file.exists() ||
            !source.file.isFile ||
            !source.file.canRead() ||
            source.file.length() != source.size
        ) {
            return NativeBackgroundUploadOutcome.Failed(
                errorCode =
                    NativeBackgroundTransferContract
                        .SOURCE_DOCUMENT_UNAVAILABLE,
                totalBytes = source.size
            )
        }

        val normalizedMimeType =
            NativeBackgroundTransferContract
                .normalizeConcreteMimeType(
                    source.mimeType
                )
                ?: return NativeBackgroundUploadOutcome.Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .SOURCE_DOCUMENT_UNAVAILABLE,
                    totalBytes = source.size
                )

        val safeDisplayName =
            NativeBackgroundTransferContract
                .safeDisplayName(
                    source.displayName
                )
                ?: return NativeBackgroundUploadOutcome.Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .SOURCE_DOCUMENT_UNAVAILABLE,
                    totalBytes = source.size
                )

        val method =
            NativeBackgroundTransferContract
                .normalizeUploadMethod(
                    request.method
                )
                ?: return NativeBackgroundUploadOutcome.Failed(
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_HTTP_METHOD,
                    totalBytes = source.size
                )

        val normalizedHeaders =
            NativeBackgroundTransferContract
                .normalizeUploadHeaders(
                    request.headers
                )

        if (
            normalizedHeaders == null ||
            normalizedHeaders != request.headers
        ) {
            return NativeBackgroundUploadOutcome.Failed(
                errorCode =
                    NativeBackgroundTransferContract
                        .INVALID_HEADERS,
                totalBytes = source.size
            )
        }

        val configuredMultipart = request.multipart

        val normalizedMultipart =
            if (configuredMultipart == null) {
                null
            } else {
                val fileField =
                    NativeBackgroundTransferContract
                        .normalizeMultipartFieldName(
                            configuredMultipart.fileField
                        )
                        ?: return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .INVALID_MULTIPART,
                            totalBytes = source.size
                        )

                val fields =
                    NativeBackgroundTransferContract
                        .normalizeMultipartFields(
                            configuredMultipart.fields
                        )
                        ?: return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .INVALID_MULTIPART,
                            totalBytes = source.size
                        )

                NativeBackgroundUploadMultipart(
                    fileField = fileField,
                    fields = fields
                ).also {
                    if (it != configuredMultipart) {
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .INVALID_MULTIPART,
                            totalBytes = source.size
                        )
                    }
                }
            }

        val multipartBody =
            normalizedMultipart?.let {
                createMultipartBody(
                    multipart = it,
                    displayName = safeDisplayName,
                    mimeType = normalizedMimeType,
                    sourceSize = source.size
                )
            }

        var currentUrl = request.url
        var redirectCount = 0

        while (true) {
            if (isCancelled()) {
                return NativeBackgroundUploadOutcome.Cancelled(
                    totalBytes = source.size,
                    progress = initialProgress(source.size)
                )
            }

            val validatedUrl = when (
                val validation =
                    NativeBackgroundTransferContract
                        .validateHttpsUrl(currentUrl)
            ) {
                is NativeBackgroundTransferUrlValidation.Valid ->
                    validation.url

                is NativeBackgroundTransferUrlValidation.Invalid ->
                    return NativeBackgroundUploadOutcome.Failed(
                        errorCode = validation.errorCode,
                        totalBytes = source.size
                    )
            }

            val connection = try {
                connectionOpener.open(
                    URL(validatedUrl)
                )
            } catch (_: Exception) {
                null
            } ?: return NativeBackgroundUploadOutcome.Failed(
                errorCode =
                    NativeBackgroundTransferContract.INVALID_URL,
                totalBytes = source.size
            )

            var transferredBytes = 0L

            try {
                request.headers.forEach {
                        (name, value) ->

                    connection.setRequestProperty(
                        name,
                        value
                    )
                }

                configureConnection(
                    connection = connection,
                    method = method,
                    contentType =
                        multipartBody?.contentType
                            ?: normalizedMimeType,
                    contentLength =
                        multipartBody?.contentLength
                            ?: source.size
                )

                onProgress(
                    0L,
                    source.size,
                    initialProgress(source.size)
                )

                if (
                    method == "POST" &&
                    !onRequestBodyStarting()
                ) {
                    return NativeBackgroundUploadOutcome.Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .RESULT_PERSISTENCE_FAILED,
                        totalBytes = source.size
                    )
                }

                val streamOutcome =
                    streamRequestBody(
                        connection = connection,
                        source = source,
                        multipartBody = multipartBody,
                        isCancelled = isCancelled,
                        onProgress = onProgress
                    )

                when (streamOutcome) {
                    is StreamOutcome.Cancelled ->
                        return NativeBackgroundUploadOutcome.Cancelled(
                            transferredBytes =
                                streamOutcome.transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    streamOutcome.transferredBytes,
                                    source.size
                                )
                        )

                    is StreamOutcome.SourceFailed ->
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .SOURCE_DOCUMENT_UNAVAILABLE,
                            transferredBytes =
                                streamOutcome.transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    streamOutcome.transferredBytes,
                                    source.size
                                )
                        )

                    is StreamOutcome.NetworkFailed ->
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .NETWORK_ERROR,
                            transferredBytes =
                                streamOutcome.transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    streamOutcome.transferredBytes,
                                    source.size
                                )
                        )

                    is StreamOutcome.Completed -> {
                        transferredBytes =
                            streamOutcome.transferredBytes
                    }
                }

                if (isCancelled()) {
                    return NativeBackgroundUploadOutcome.Cancelled(
                        transferredBytes = transferredBytes,
                        totalBytes = source.size,
                        progress =
                            progress(
                                transferredBytes,
                                source.size
                            )
                    )
                }

                val responseCode =
                    connection.responseCode

                if (isUploadRedirect(responseCode)) {
                    if (method == "POST") {
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .UPLOAD_OUTCOME_UNCERTAIN,
                            transferredBytes =
                                transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    transferredBytes,
                                    source.size
                                )
                        )
                    }

                    if (redirectCount >= MAX_REDIRECTS) {
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .HTTP_ERROR,
                            transferredBytes =
                                transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    transferredBytes,
                                    source.size
                                )
                        )
                    }

                    val location =
                        connection.getHeaderField(
                            "Location"
                        )

                    if (location.isNullOrBlank()) {
                        return NativeBackgroundUploadOutcome.Failed(
                            errorCode =
                                NativeBackgroundTransferContract
                                    .HTTP_ERROR,
                            transferredBytes =
                                transferredBytes,
                            totalBytes = source.size,
                            progress =
                                progress(
                                    transferredBytes,
                                    source.size
                                )
                        )
                    }

                    val redirectedUrl =
                        resolveRedirect(
                            currentUrl = validatedUrl,
                            location = location
                        )
                            ?: return NativeBackgroundUploadOutcome.Failed(
                                errorCode =
                                    NativeBackgroundTransferContract
                                        .INVALID_URL,
                                transferredBytes =
                                    transferredBytes,
                                totalBytes = source.size,
                                progress =
                                    progress(
                                        transferredBytes,
                                        source.size
                                    )
                            )

                    when (
                        val validation =
                            NativeBackgroundTransferContract
                                .validateHttpsUrl(
                                    redirectedUrl
                                )
                    ) {
                        is NativeBackgroundTransferUrlValidation.Valid -> {
                            if (
                                !isSameOrigin(
                                    validatedUrl,
                                    validation.url
                                )
                            ) {
                                return NativeBackgroundUploadOutcome.Failed(
                                    errorCode =
                                        NativeBackgroundTransferContract
                                            .HTTP_ERROR,
                                    transferredBytes =
                                        transferredBytes,
                                    totalBytes = source.size,
                                    progress =
                                        progress(
                                            transferredBytes,
                                            source.size
                                        )
                                )
                            }

                            currentUrl = validation.url
                        }

                        is NativeBackgroundTransferUrlValidation.Invalid ->
                            return NativeBackgroundUploadOutcome.Failed(
                                errorCode =
                                    validation.errorCode,
                                transferredBytes =
                                    transferredBytes,
                                totalBytes = source.size,
                                progress =
                                    progress(
                                        transferredBytes,
                                        source.size
                                    )
                            )
                    }

                    redirectCount++
                    continue
                }

                if (responseCode !in 200..299) {
                    return NativeBackgroundUploadOutcome.Failed(
                        errorCode =
                            NativeBackgroundTransferContract.HTTP_ERROR,
                        transferredBytes = transferredBytes,
                        totalBytes = source.size,
                        progress =
                            progress(
                                transferredBytes,
                                source.size
                            )
                    )
                }

                if (
                    transferredBytes != source.size
                ) {
                    return NativeBackgroundUploadOutcome.Failed(
                        errorCode =
                            NativeBackgroundTransferContract
                                .SOURCE_DOCUMENT_UNAVAILABLE,
                        transferredBytes =
                            transferredBytes,
                        totalBytes = source.size,
                        progress =
                            progress(
                                transferredBytes,
                                source.size
                            )
                    )
                }

                return NativeBackgroundUploadOutcome.Succeeded(
                    NativeBackgroundTransferResult(
                        id = request.id,
                        type =
                            NativeBackgroundTransferContract
                                .TYPE_UPLOAD,
                        status =
                            NativeBackgroundTransferContract
                                .STATUS_SUCCEEDED,
                        transferredBytes = source.size,
                        totalBytes = source.size,
                        progress = 100,
                        fileId = source.documentId,
                        displayName = safeDisplayName,
                        mimeType = normalizedMimeType,
                        size = source.size,
                        consumed = false
                    )
                )
            } catch (_: IOException) {
                if (isCancelled()) {
                    return NativeBackgroundUploadOutcome.Cancelled(
                        transferredBytes =
                            transferredBytes,
                        totalBytes = source.size,
                        progress =
                            progress(
                                transferredBytes,
                                source.size
                            )
                    )
                }

                return NativeBackgroundUploadOutcome.Failed(
                    errorCode =
                        NativeBackgroundTransferContract.NETWORK_ERROR,
                    transferredBytes = transferredBytes,
                    totalBytes = source.size,
                    progress =
                        progress(
                            transferredBytes,
                            source.size
                        )
                )
            } catch (_: SecurityException) {
                return NativeBackgroundUploadOutcome.Failed(
                    errorCode =
                        NativeBackgroundTransferContract.NETWORK_ERROR,
                    transferredBytes = transferredBytes,
                    totalBytes = source.size,
                    progress =
                        progress(
                            transferredBytes,
                            source.size
                        )
                )
            } finally {
                connection.disconnect()
            }
        }
    }

    private fun streamRequestBody(
        connection: HttpsURLConnection,
        source: NativeBackgroundUploadSourceResolution.Resolved,
        multipartBody: NativeBackgroundMultipartBody?,
        isCancelled: () -> Boolean,
        onProgress: (
            transferredBytes: Long,
            totalBytes: Long?,
            progress: Int?
        ) -> Unit
    ): StreamOutcome {
        val input = try {
            FileInputStream(source.file)
        } catch (_: IOException) {
            return StreamOutcome.SourceFailed()
        } catch (_: SecurityException) {
            return StreamOutcome.SourceFailed()
        }

        return input.use { inputStream ->
            val output = try {
                connection.outputStream
            } catch (_: IOException) {
                return@use StreamOutcome.NetworkFailed()
            } catch (_: SecurityException) {
                return@use StreamOutcome.NetworkFailed()
            }

            output.use { outputStream ->
                val buffer =
                    ByteArray(BUFFER_SIZE)

                var transferredBytes = 0L

                if (multipartBody != null) {
                    if (isCancelled()) {
                        return@use StreamOutcome.Cancelled()
                    }

                    try {
                        outputStream.write(
                            multipartBody.prefixBytes
                        )
                    } catch (_: IOException) {
                        return@use StreamOutcome.NetworkFailed()
                    } catch (_: SecurityException) {
                        return@use StreamOutcome.NetworkFailed()
                    }
                }

                while (true) {
                    if (isCancelled()) {
                        return@use StreamOutcome.Cancelled(
                            transferredBytes
                        )
                    }

                    val read = try {
                        inputStream.read(buffer)
                    } catch (_: IOException) {
                        return@use StreamOutcome.SourceFailed(
                            transferredBytes
                        )
                    } catch (_: SecurityException) {
                        return@use StreamOutcome.SourceFailed(
                            transferredBytes
                        )
                    }

                    if (read < 0) {
                        break
                    }

                    if (read == 0) {
                        continue
                    }

                    val nextBytes =
                        transferredBytes + read

                    if (
                        nextBytes > source.size
                    ) {
                        return@use StreamOutcome.SourceFailed(
                            transferredBytes
                        )
                    }

                    try {
                        outputStream.write(
                            buffer,
                            0,
                            read
                        )
                    } catch (_: IOException) {
                        return@use StreamOutcome.NetworkFailed(
                            transferredBytes
                        )
                    } catch (_: SecurityException) {
                        return@use StreamOutcome.NetworkFailed(
                            transferredBytes
                        )
                    }

                    transferredBytes =
                        nextBytes

                    onProgress(
                        transferredBytes,
                        source.size,
                        progress(
                            transferredBytes,
                            source.size
                        )
                    )
                }

                if (
                    transferredBytes != source.size
                ) {
                    return@use StreamOutcome.SourceFailed(
                        transferredBytes
                    )
                }

                if (isCancelled()) {
                    return@use StreamOutcome.Cancelled(
                        transferredBytes
                    )
                }

                if (multipartBody != null) {
                    try {
                        outputStream.write(
                            multipartBody.suffixBytes
                        )
                    } catch (_: IOException) {
                        return@use StreamOutcome.NetworkFailed(
                            transferredBytes
                        )
                    } catch (_: SecurityException) {
                        return@use StreamOutcome.NetworkFailed(
                            transferredBytes
                        )
                    }
                }

                if (isCancelled()) {
                    return@use StreamOutcome.Cancelled(
                        transferredBytes
                    )
                }

                try {
                    outputStream.flush()
                } catch (_: IOException) {
                    return@use StreamOutcome.NetworkFailed(
                        transferredBytes
                    )
                }

                StreamOutcome.Completed(
                    transferredBytes
                )
            }
        }
    }

    private fun configureConnection(
        connection: HttpsURLConnection,
        method: String,
        contentType: String,
        contentLength: Long
    ) {
        connection.requestMethod = method
        connection.instanceFollowRedirects = false

        connection.connectTimeout =
            CONNECT_TIMEOUT_MILLISECONDS

        connection.readTimeout =
            READ_TIMEOUT_MILLISECONDS

        connection.useCaches = false
        connection.doInput = true
        connection.doOutput = true

        connection.setRequestProperty(
            "Content-Type",
            contentType
        )

        connection.setFixedLengthStreamingMode(
            contentLength
        )
    }

    private fun createMultipartBody(
        multipart: NativeBackgroundUploadMultipart,
        displayName: String,
        mimeType: String,
        sourceSize: Long
    ): NativeBackgroundMultipartBody {
        val boundary =
            "native-background-transfer-" +
                UUID.randomUUID().toString()

        val prefix = StringBuilder()

        multipart.fields.forEach {
                (name, value) ->

            prefix
                .append("--")
                .append(boundary)
                .append(CRLF)
                .append(
                    "Content-Disposition: form-data; " +
                        "name=\""
                )
                .append(name)
                .append("\"")
                .append(CRLF)
                .append(CRLF)
                .append(value)
                .append(CRLF)
        }

        prefix
            .append("--")
            .append(boundary)
            .append(CRLF)
            .append(
                "Content-Disposition: form-data; " +
                    "name=\""
            )
            .append(multipart.fileField)
            .append("\"; filename=\"")
            .append(displayName)
            .append("\"")
            .append(CRLF)
            .append("Content-Type: ")
            .append(mimeType)
            .append(CRLF)
            .append(CRLF)

        val prefixBytes =
            prefix.toString()
                .toByteArray(Charsets.UTF_8)

        val suffixBytes =
            (
                CRLF +
                    "--" +
                    boundary +
                    "--" +
                    CRLF
                )
                .toByteArray(Charsets.UTF_8)

        return NativeBackgroundMultipartBody(
            contentType =
                "multipart/form-data; boundary=$boundary",
            contentLength =
                prefixBytes.size.toLong() +
                    sourceSize +
                    suffixBytes.size.toLong(),
            prefixBytes = prefixBytes,
            suffixBytes = suffixBytes
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

    private fun isSameOrigin(
        firstUrl: String,
        secondUrl: String
    ): Boolean {
        return try {
            val first = URI(firstUrl)
            val second = URI(secondUrl)

            first.scheme.equals(
                second.scheme,
                ignoreCase = true
            ) &&
                first.host.equals(
                    second.host,
                    ignoreCase = true
                ) &&
                effectivePort(first) ==
                effectivePort(second)
        } catch (_: Exception) {
            false
        }
    }

    private fun effectivePort(
        uri: URI
    ): Int {
        return if (uri.port == -1) {
            DEFAULT_HTTPS_PORT
        } else {
            uri.port
        }
    }

    private fun isUploadRedirect(
        responseCode: Int
    ): Boolean {
        return responseCode == 307 ||
            responseCode == 308
    }

    private fun initialProgress(
        totalBytes: Long
    ): Int? {
        return if (totalBytes > 0L) {
            0
        } else {
            null
        }
    }

    private fun progress(
        transferredBytes: Long,
        totalBytes: Long
    ): Int? {
        if (totalBytes <= 0L) {
            return null
        }

        return (
            transferredBytes
                .coerceIn(
                    0L,
                    totalBytes
                ) *
                100L /
                totalBytes
            )
            .toInt()
    }

    private sealed interface StreamOutcome {

        data class Completed(
            val transferredBytes: Long
        ) : StreamOutcome

        data class Cancelled(
            val transferredBytes: Long = 0L
        ) : StreamOutcome

        data class SourceFailed(
            val transferredBytes: Long = 0L
        ) : StreamOutcome

        data class NetworkFailed(
            val transferredBytes: Long = 0L
        ) : StreamOutcome
    }

    private companion object {
        const val MAX_REDIRECTS = 5

        const val DEFAULT_HTTPS_PORT = 443

        const val CRLF = "\r\n"

        const val BUFFER_SIZE =
            64 * 1024

        const val CONNECT_TIMEOUT_MILLISECONDS =
            15_000

        const val READ_TIMEOUT_MILLISECONDS =
            30_000
    }
}
package com.bbs.plugins.native_background_transfer

import android.content.Context
import android.util.AtomicFile
import org.json.JSONException
import org.json.JSONObject
import java.io.File
import java.nio.charset.StandardCharsets

internal sealed interface NativeBackgroundTransferBeginResult {

    data object Stored :
        NativeBackgroundTransferBeginResult

    data object Duplicate :
        NativeBackgroundTransferBeginResult

    data object Failed :
        NativeBackgroundTransferBeginResult
}

internal sealed interface NativeBackgroundTransferConsumeResult {

    data class Consumed(
        val result: NativeBackgroundTransferResult
    ) : NativeBackgroundTransferConsumeResult

    data class AlreadyConsumed(
        val result: NativeBackgroundTransferResult
    ) : NativeBackgroundTransferConsumeResult

    data class NotTerminal(
        val result: NativeBackgroundTransferResult
    ) : NativeBackgroundTransferConsumeResult

    data object NotFound :
        NativeBackgroundTransferConsumeResult

    data object Failed :
        NativeBackgroundTransferConsumeResult
}

internal class NativeBackgroundTransferStore(
    context: Context
) {

    private val applicationContext =
        context.applicationContext

    private val recordsDirectory =
        File(
            applicationContext.filesDir,
            RECORD_DIRECTORY_NAME
        )

    fun begin(
        request: NativeBackgroundTransferStoredRequest
    ): NativeBackgroundTransferBeginResult =
        synchronized(lock) {
            val normalizedId =
                NativeBackgroundTransferContract
                    .normalizeRequestId(request.id)

            if (
                normalizedId != request.id ||
                NativeBackgroundTransferStoredRequest
                    .fromStoredJson(
                        request.toStoredJson()
                    ) != request
            ) {
                return@synchronized NativeBackgroundTransferBeginResult.Failed
            }

            if (!ensureRecordsDirectory()) {
                return@synchronized NativeBackgroundTransferBeginResult.Failed
            }

            val recordFile = recordFile(request.id)
                ?: return@synchronized NativeBackgroundTransferBeginResult.Failed

            if (recordFile.exists()) {
                return@synchronized NativeBackgroundTransferBeginResult.Duplicate
            }

            val result =
                NativeBackgroundTransferResult
                    .queued(request)

            if (
                !writeRecord(
                    file = recordFile,
                    request = request,
                    result = result
                )
            ) {
                return@synchronized NativeBackgroundTransferBeginResult.Failed
            }

            NativeBackgroundTransferBeginResult.Stored
        }

    fun request(
        id: String
    ): NativeBackgroundTransferRequest? =
        synchronized(lock) {
            readRecord(id)?.request as? NativeBackgroundTransferRequest
        }

    fun storedRequest(
        id: String
    ): NativeBackgroundTransferStoredRequest? =
        synchronized(lock) {
            readRecord(id)?.request
        }

    fun result(
        id: String
    ): NativeBackgroundTransferResult? =
        synchronized(lock) {
            readRecord(id)?.result
        }

    fun listResults():
        List<NativeBackgroundTransferResult> =
        synchronized(lock) {
            if (!ensureRecordsDirectory()) {
                return@synchronized emptyList()
            }

            val files = try {
                recordsDirectory.listFiles()
                    ?.toList()
                    .orEmpty()
            } catch (_: SecurityException) {
                return@synchronized emptyList()
            }

            files
                .asSequence()
                .filter {
                    it.isFile &&
                        it.name.endsWith(RECORD_EXTENSION)
                }
                .mapNotNull { file ->
                    readRecordFile(file)
                }
                .sortedByDescending {
                    it.request.createdAt
                }
                .map {
                    it.result
                }
                .toList()
        }

    fun update(
        result: NativeBackgroundTransferResult
    ): Boolean =
        synchronized(lock) {
            val normalizedId =
                NativeBackgroundTransferContract
                    .normalizeRequestId(result.id)

            if (normalizedId != result.id) {
                return@synchronized false
            }

            if (
                NativeBackgroundTransferResult
                    .fromStoredJson(
                        result.toStoredJson()
                    ) != result
            ) {
                return@synchronized false
            }

            val current = readRecord(result.id)
                ?: return@synchronized false

            if (
                !isAllowedTransition(
                    current = current.result,
                    next = result
                )
            ) {
                return@synchronized false
            }

            val file = recordFile(result.id)
                ?: return@synchronized false

            writeRecord(
                file = file,
                request = current.request,
                result = result
            )
        }

    fun consume(
        id: String
    ): NativeBackgroundTransferConsumeResult =
        synchronized(lock) {
            val current = readRecord(id)
                ?: return@synchronized NativeBackgroundTransferConsumeResult.NotFound

            if (
                !NativeBackgroundTransferContract
                    .isTerminalStatus(
                        current.result.status
                    )
            ) {
                return@synchronized NativeBackgroundTransferConsumeResult
                        .NotTerminal(
                            current.result
                        )
            }

            if (current.result.consumed) {
                return@synchronized NativeBackgroundTransferConsumeResult
                        .AlreadyConsumed(
                            current.result
                        )
            }

            val consumedResult =
                current.result.copy(
                    consumed = true,
                    updatedAt =
                        System.currentTimeMillis()
                )

            val file = recordFile(id)
                ?: return@synchronized NativeBackgroundTransferConsumeResult.Failed

            if (
                !writeRecord(
                    file = file,
                    request = current.request,
                    result = consumedResult
                )
            ) {
                return@synchronized NativeBackgroundTransferConsumeResult.Failed
            }

            NativeBackgroundTransferConsumeResult
                .Consumed(
                    consumedResult
                )
        }

    private fun readRecord(
        id: String
    ): StoredRecord? {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(id)
                ?: return null

        val file = recordFile(normalizedId)
            ?: return null

        return readRecordFile(file)
            ?.takeIf {
                it.request.id == normalizedId &&
                    it.result.id == normalizedId
            }
    }

    private fun readRecordFile(
        file: File
    ): StoredRecord? {
        if (
            !file.isFile ||
            !file.canRead()
        ) {
            return null
        }

        val json = try {
            AtomicFile(file)
                .openRead()
                .bufferedReader(
                    StandardCharsets.UTF_8
                )
                .use { reader ->
                    JSONObject(
                        reader.readText()
                    )
                }
        } catch (_: JSONException) {
            return null
        } catch (_: Exception) {
            return null
        }

        if (
            json.optInt(
                "version",
                -1
            ) != RECORD_VERSION
        ) {
            return null
        }

        val requestJson = try {
            json.getJSONObject("request")
        } catch (_: JSONException) {
            return null
        }

        val resultJson = try {
            json.getJSONObject("result")
        } catch (_: JSONException) {
            return null
        }

        val request =
            NativeBackgroundTransferStoredRequest
                .fromStoredJson(requestJson)
                ?: return null

        val result =
            NativeBackgroundTransferResult
                .fromStoredJson(resultJson)
                ?: return null

        if (
            request.id != result.id ||
            request.type != result.type
        ) {
            return null
        }

        return StoredRecord(
            request = request,
            result = result
        )
    }

    private fun writeRecord(
        file: File,
        request: NativeBackgroundTransferStoredRequest,
        result: NativeBackgroundTransferResult
    ): Boolean {
        if (
            request.id != result.id ||
            request.type != result.type ||
            !ensureRecordsDirectory()
        ) {
            return false
        }

        val payload = JSONObject().apply {
            put("version", RECORD_VERSION)
            put(
                "request",
                request.toStoredJson()
            )
            put(
                "result",
                result.toStoredJson()
            )
        }

        val atomicFile = AtomicFile(file)

        val output = try {
            atomicFile.startWrite()
        } catch (_: Exception) {
            return false
        }

        return try {
            output.write(
                payload
                    .toString()
                    .toByteArray(
                        StandardCharsets.UTF_8
                    )
            )

            output.flush()
            output.fd.sync()

            atomicFile.finishWrite(output)

            true
        } catch (_: Exception) {
            runCatching {
                atomicFile.failWrite(output)
            }

            false
        }
    }

    private fun ensureRecordsDirectory(): Boolean {
        return try {
            if (recordsDirectory.exists()) {
                recordsDirectory.isDirectory &&
                    recordsDirectory.canRead() &&
                    recordsDirectory.canWrite()
            } else {
                recordsDirectory.mkdirs() &&
                    recordsDirectory.isDirectory &&
                    recordsDirectory.canRead() &&
                    recordsDirectory.canWrite()
            }
        } catch (_: SecurityException) {
            false
        }
    }

    private fun recordFile(
        id: String
    ): File? {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(id)
                ?: return null

        if (normalizedId != id) {
            return null
        }

        val candidate = File(
            recordsDirectory,
            "$id$RECORD_EXTENSION"
        )

        return try {
            val directory =
                recordsDirectory.canonicalFile

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

    private fun isAllowedTransition(
        current: NativeBackgroundTransferResult,
        next: NativeBackgroundTransferResult
    ): Boolean {
        if (
            current.id != next.id ||
            current.type != next.type ||
            current.consumed
        ) {
            return false
        }

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(
                    current.status
                )
        ) {
            return false
        }

        return when (current.status) {
            NativeBackgroundTransferContract
                .STATUS_QUEUED ->
                next.status in setOf(
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED,
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                    NativeBackgroundTransferContract
                        .STATUS_SUCCEEDED,
                    NativeBackgroundTransferContract
                        .STATUS_FAILED,
                    NativeBackgroundTransferContract
                        .STATUS_CANCELLED
                )

            NativeBackgroundTransferContract
                .STATUS_RUNNING ->
                next.status in setOf(
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED,
                    NativeBackgroundTransferContract
                        .STATUS_SUCCEEDED,
                    NativeBackgroundTransferContract
                        .STATUS_FAILED,
                    NativeBackgroundTransferContract
                        .STATUS_CANCELLED
                )

            else -> false
        }
    }

    private data class StoredRecord(
        val request: NativeBackgroundTransferStoredRequest,
        val result: NativeBackgroundTransferResult
    )

    private companion object {
        const val RECORD_VERSION = 1

        const val RECORD_DIRECTORY_NAME =
            "native_background_transfer_records"

        const val RECORD_EXTENSION = ".json"

        val lock = Any()
    }
}
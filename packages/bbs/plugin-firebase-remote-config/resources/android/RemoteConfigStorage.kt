package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import android.util.AtomicFile
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileNotFoundException
import java.io.FileOutputStream

/** No exception message, path or SDK input is exposed at the bridge boundary. */
internal class RemoteConfigStorageException : Exception("Remote Config persistence failed")

internal interface RemoteConfigRecordStorage {
    fun load(): List<RemoteConfigStoredRecord>
    fun save(records: List<RemoteConfigStoredRecord>)
}

/** One runtime owns one instance in its private no-backup directory, on worker threads. */
internal class RemoteConfigStorage(private val directory: File) : RemoteConfigRecordStorage {
    private val base = File(directory, "operations-v1.json")
    private val atomic = AtomicFile(base)

    @Synchronized override fun load(): List<RemoteConfigStoredRecord> {
        requireWorker()
        return try {
            val bytes = readBytes()
            if (bytes == null) emptyList() else RemoteConfigRecordCodec.decode(bytes)
        } catch (_: Exception) { throw RemoteConfigStorageException() }
    }

    @Synchronized override fun save(records: List<RemoteConfigStoredRecord>) {
        requireWorker()
        var stream: FileOutputStream? = null
        try {
            val bytes = RemoteConfigRecordCodec.encode(records)
            require(directory.isDirectory || directory.mkdirs())
            stream = atomic.startWrite()
            stream.write(bytes)
            stream.fd.sync() // AtomicFile may log sync/rename failures rather than throwing.
            atomic.finishWrite(stream)
            stream = null
            require(readBytes()?.contentEquals(bytes) == true)
        } catch (failure: Throwable) {
            stream?.let { try { atomic.failWrite(it) } catch (_: Exception) { /* Preserve original failure. */ } }
            if (failure is Error) throw failure
            throw RemoteConfigStorageException()
        }
    }

    private fun readBytes(): ByteArray? {
        val stream = try { atomic.openRead() } catch (_: FileNotFoundException) {
            if (!base.exists() && !File(base.path + ".bak").exists() &&
                (!directory.exists() || directory.isDirectory)) return null
            throw RemoteConfigStorageException()
        }
        return stream.use { input ->
            val output = ByteArrayOutputStream()
            val buffer = ByteArray(4096)
            while (true) {
                val count = input.read(buffer)
                if (count < 0) break
                require(output.size() + count <= RemoteConfigRecordCodec.MAX_STORAGE_BYTES)
                output.write(buffer, 0, count)
            }
            output.toByteArray()
        }
    }

    private fun requireWorker() { check(Looper.myLooper() != Looper.getMainLooper()) }
}

package com.bbs.plugins.native_media_optimizer

import android.content.Context
import android.system.Os
import android.system.OsConstants
import android.util.AtomicFile
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileNotFoundException
import java.io.FileOutputStream
import java.io.IOException
import java.io.RandomAccessFile

internal class NativeMediaOptimizerStorageException : IOException("Native media state is unavailable.")

internal interface NativeMediaOptimizerStateFile {
    fun <T> locked(action: () -> T): T
    fun read(): ByteArray?
    fun write(bytes: ByteArray)
}

/** Private, bounded AtomicFile with verified commits and a cross-process lock. */
internal class NativeMediaOptimizerAtomicStateFile(private val directory: File) : NativeMediaOptimizerStateFile {
    private val baseFile = File(directory, "state.json")
    private val atomicFile = AtomicFile(baseFile)
    private val held = ThreadLocal<Boolean>()
    override fun toString() = "NativeMediaOptimizerAtomicStateFile(private)"

    override fun <T> locked(action: () -> T): T = synchronized(PROCESS_LOCK) {
        try {
            check(held.get() != true)
            if (!directory.isDirectory) {
                check(directory.mkdirs() || directory.isDirectory)
                syncDirectory(requireNotNull(directory.parentFile))
            }
            RandomAccessFile(File(directory, "state.lock"), "rw").use { lockFile ->
                lockFile.channel.lock().use {
                    held.set(true)
                    try { action() } finally { held.remove() }
                }
            }
        } catch (_: Exception) { throw NativeMediaOptimizerStorageException() }
    }

    override fun read(): ByteArray? {
        try {
            check(held.get() == true)
            val input = try { atomicFile.openRead() } catch (_: FileNotFoundException) {
                check(!baseFile.exists() && !File(baseFile.path + ".bak").exists())
                val pending = File(baseFile.path + ".new")
                if (pending.exists()) {
                    atomicFile.delete()
                    check(!baseFile.exists() && !pending.exists() && !File(baseFile.path + ".bak").exists())
                    syncDirectory(directory)
                }
                return null
            }
            return input.use { stream ->
                val bytes = ByteArrayOutputStream()
                val buffer = ByteArray(8192)
                while (true) {
                    val count = stream.read(buffer)
                    if (count < 0) break
                    if (count == 0) continue
                    check(bytes.size() + count <= NativeMediaOptimizerContract.MAX_STATE_BYTES)
                    bytes.write(buffer, 0, count)
                }
                check(bytes.size() > 0)
                bytes.toByteArray()
            }
        } catch (_: Exception) { throw NativeMediaOptimizerStorageException() }
    }

    override fun write(bytes: ByteArray) {
        var pending: FileOutputStream? = null
        try {
            check(held.get() == true && bytes.isNotEmpty() && bytes.size <= NativeMediaOptimizerContract.MAX_STATE_BYTES)
            val stream = atomicFile.startWrite()
            pending = stream
            stream.write(bytes)
            stream.fd.sync()
            atomicFile.finishWrite(stream)
            pending = null
            syncDirectory(directory)
            val committed = read() ?: throw NativeMediaOptimizerStorageException()
            try { check(committed.contentEquals(bytes)) } finally { committed.fill(0) }
            check(!File(baseFile.path + ".new").exists() && !File(baseFile.path + ".bak").exists())
        } catch (_: Exception) {
            pending?.let { try { atomicFile.failWrite(it) } catch (_: Exception) { } }
            throw NativeMediaOptimizerStorageException()
        }
    }

    private fun syncDirectory(target: File) {
        val descriptor = Os.open(target.absolutePath, OsConstants.O_RDONLY, 0)
        try { Os.fsync(descriptor) } finally { Os.close(descriptor) }
    }

    companion object {
        private val PROCESS_LOCK = Any()
        fun forContext(context: Context) = NativeMediaOptimizerAtomicStateFile(File(context.applicationContext.noBackupFilesDir, "bbs-native-media-optimizer-v1"))
    }
}

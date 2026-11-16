package com.bbs.plugins.native_nfc

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

internal class NativeNfcStorageException :
    IOException("Native NFC storage is unavailable.") {

    val errorCode: String = NativeNfcContract.ERROR_IO
}

internal interface NativeNfcStateFile {

    fun <T> locked(action: () -> T): T

    fun read(): ByteArray?

    fun writeVerified(bytes: ByteArray)
}

internal class NativeNfcAtomicStateFile(
    private val directory: File
) : NativeNfcStateFile {

    private val baseFile = File(directory, "state.json")
    private val atomicFile = AtomicFile(baseFile)
    private val transactionHeld = ThreadLocal<Boolean>()

    override fun toString(): String =
        "NativeNfcAtomicStateFile(private)"

    override fun <T> locked(action: () -> T): T =
        synchronized(PROCESS_LOCK) {
            val lockFile = try {
                ensureDirectory()

                RandomAccessFile(
                    File(directory, "state.lock"),
                    "rw"
                )
            } catch (_: Exception) {
                throw NativeNfcStorageException()
            }

            lockFile.use { file ->
                val fileLock = try {
                    file.channel.lock()
                } catch (_: Exception) {
                    throw NativeNfcStorageException()
                }

                fileLock.use {
                    transactionHeld.set(true)

                    try {
                        action()
                    } finally {
                        transactionHeld.remove()
                    }
                }
            }
        }

    override fun read(): ByteArray? {
        try {
            requireTransaction()

            val input = try {
                atomicFile.openRead()
            } catch (_: FileNotFoundException) {
                if (
                    baseFile.exists() ||
                    File(baseFile.path + ".bak").exists()
                ) {
                    throw NativeNfcStorageException()
                }

                val unfinished = File(baseFile.path + ".new")

                if (unfinished.exists()) {
                    atomicFile.delete()

                    check(
                        !baseFile.exists() &&
                            !unfinished.exists() &&
                            !File(baseFile.path + ".bak").exists()
                    )

                    syncDirectory(directory)
                }

                return null
            }

            return input.use { stream ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(8_192)

                while (true) {
                    val count = stream.read(buffer)

                    if (count < 0) break
                    if (count == 0) continue

                    check(output.size() + count <= MAX_STATE_BYTES)
                    output.write(buffer, 0, count)
                }

                check(output.size() > 0)
                output.toByteArray()
            }
        } catch (_: Exception) {
            throw NativeNfcStorageException()
        }
    }

    override fun writeVerified(bytes: ByteArray) {
        var pendingStream: FileOutputStream? = null

        try {
            requireTransaction()
            check(bytes.isNotEmpty() && bytes.size <= MAX_STATE_BYTES)

            val output = atomicFile.startWrite()
            pendingStream = output

            output.write(bytes)
            output.fd.sync()

            atomicFile.finishWrite(output)
            pendingStream = null

            syncDirectory(directory)

            val committed = read()
                ?: throw NativeNfcStorageException()

            try {
                check(committed.contentEquals(bytes))
            } finally {
                committed.fill(0)
            }

            check(
                !File(baseFile.path + ".new").exists() &&
                    !File(baseFile.path + ".bak").exists()
            )
        } catch (_: Exception) {
            pendingStream?.let { output ->
                try {
                    atomicFile.failWrite(output)
                } catch (_: Exception) {
                    // Do not expose underlying storage errors.
                }
            }

            throw NativeNfcStorageException()
        }
    }

    private fun requireTransaction() {
        check(transactionHeld.get() == true)
    }

    private fun ensureDirectory() {
        if (directory.isDirectory) return

        check(directory.mkdirs() || directory.isDirectory)

        syncDirectory(
            directory.parentFile
                ?: throw NativeNfcStorageException()
        )
    }

    private fun syncDirectory(target: File) {
        check(target.isDirectory)

        val descriptor = Os.open(
            target.absolutePath,
            OsConstants.O_RDONLY,
            0
        )

        try {
            Os.fsync(descriptor)
        } finally {
            Os.close(descriptor)
        }
    }

    companion object {

        private val PROCESS_LOCK = Any()

        private const val MAX_STATE_BYTES = 32 * 1024

        fun forContext(context: Context): NativeNfcAtomicStateFile =
            NativeNfcAtomicStateFile(
                File(
                    context.applicationContext.noBackupFilesDir,
                    "bbs-native-nfc-v1"
                )
            )
    }
}

package com.bbs.plugins.native_contacts

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

/**
 * Controlled failure only. Never retain an underlying exception that might
 * contain private state, contact fields, or filesystem details.
 */
internal class NativeContactsStorageException :
    IOException("Native contacts storage is unavailable.") {

    val errorCode: String =
        NativeContactsContract.RESULT_PERSISTENCE_FAILED
}

/**
 * Separate the disk boundary so lifecycle tests can inject read/write failures.
 * All reads and writes must run inside locked().
 */
internal interface NativeContactsStateFile {

    fun <T> locked(action: () -> T): T

    fun read(): ByteArray?

    fun writeVerified(bytes: ByteArray)
}

/**
 * One fixed state file in app-private storage. Filenames contain no contact
 * data or user-supplied request values.
 */
internal class NativeContactsAtomicStateFile(
    private val directory: File
) : NativeContactsStateFile {

    private val baseFile = File(directory, "state.json")
    private val atomicFile = AtomicFile(baseFile)
    private val transactionHeld = ThreadLocal<Boolean>()

    override fun toString(): String =
        "NativeContactsAtomicStateFile(private)"

    override fun <T> locked(action: () -> T): T =
        synchronized(PROCESS_LOCK) {
            try {
                // Prevent nested transactions from acquiring overlapping locks.
                check(transactionHeld.get() != true)
                ensureDirectory()

                RandomAccessFile(
                    File(directory, "state.lock"),
                    "rw"
                ).use { lockFile ->
                    lockFile.channel.lock().use {
                        transactionHeld.set(true)

                        try {
                            action()
                        } finally {
                            transactionHeld.remove()
                        }
                    }
                }
            } catch (_: Exception) {
                throw NativeContactsStorageException()
            }
        }

    override fun read(): ByteArray? {
        try {
            requireTransaction()

            val input = try {
                atomicFile.openRead()
            } catch (_: FileNotFoundException) {
                // A committed file that cannot be opened is an error.
                if (
                    baseFile.exists() ||
                    File(baseFile.path + ".bak").exists()
                ) {
                    throw NativeContactsStorageException()
                }

                // A crash during the first write can leave only an uncommitted
                // .new file. It was never accepted as durable state.
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
                    if (count < 0) {
                        break
                    }
                    if (count == 0) {
                        continue
                    }

                    check(output.size() + count <= MAX_STATE_BYTES)
                    output.write(buffer, 0, count)
                }

                check(output.size() > 0)
                output.toByteArray()
            }
        } catch (_: Exception) {
            throw NativeContactsStorageException()
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

            // Surface synchronization failures before committing the new file.
            output.fd.sync()
            atomicFile.finishWrite(output)
            pendingStream = null

            // Persist the directory entry change as well as the file contents.
            syncDirectory(directory)

            // AtomicFile's completion methods return no success flag.
            // Verify the committed contents before the caller delivers data.
            val committed = read()
                ?: throw NativeContactsStorageException()

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
                    // Do not expose or log underlying storage exceptions.
                }
            }

            throw NativeContactsStorageException()
        }
    }

    private fun requireTransaction() {
        check(transactionHeld.get() == true)
    }

    private fun ensureDirectory() {
        if (directory.isDirectory) {
            return
        }

        check(directory.mkdirs() || directory.isDirectory)

        // Persist creation of the plugin directory in its parent directory.
        syncDirectory(
            directory.parentFile
                ?: throw NativeContactsStorageException()
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

        private const val MAX_STATE_BYTES =
            NativeContactsContract.MAX_STORED_RESULTS *
                NativeContactsContract.MAX_RESULT_BYTES + 4_096

        fun forContext(context: Context): NativeContactsAtomicStateFile =
            NativeContactsAtomicStateFile(
                File(
                    context.applicationContext.noBackupFilesDir,
                    "bbs-native-contacts-v1"
                )
            )
    }
}
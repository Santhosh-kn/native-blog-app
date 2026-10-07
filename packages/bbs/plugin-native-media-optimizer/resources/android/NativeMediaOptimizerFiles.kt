package com.bbs.plugins.native_media_optimizer

import android.content.Context
import android.system.Os
import android.system.OsConstants
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateResolver
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateSourceResult
import java.io.File
import java.io.FileOutputStream
import java.io.OutputStream
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerPreparedSource(
    val documentId: String,
    val file: File,
    val declaredMimeType: String,
    val size: Long,
    val outputDirectory: File
) {
    override fun toString() = "NativeMediaOptimizerPreparedSource(redacted)"
}

/** Only picker-owned inputs and fixed sibling outputs; no bridge-supplied paths. */
internal class NativeMediaOptimizerFiles(context: Context) {
    private val appContext = context.applicationContext
    private val applicationRoot by lazy { requireMediaWorker(); File(appContext.applicationInfo.dataDir).canonicalFile }

    fun resolve(documentId: String): NativeMediaOptimizerPreparedSource {
        requireMediaWorker()
        if (Contract.requestId(documentId) != documentId) fail(Contract.INVALID_SOURCE_ID)
        val selected = NativeDocumentPickerPrivateResolver.resolve(appContext, documentId)
            as? NativeDocumentPickerPrivateSourceResult.Resolved ?: fail(Contract.SOURCE_UNAVAILABLE)
        return prepareResolved(selected)
    }

    internal fun prepareResolved(selected: NativeDocumentPickerPrivateSourceResult.Resolved): NativeMediaOptimizerPreparedSource {
        requireMediaWorker()
        try {
            if (Contract.requestId(selected.documentId) != selected.documentId) fail(Contract.INVALID_SOURCE_ID)
            val file = selected.file
            val picker = file.parentFile ?: fail(Contract.SOURCE_UNAVAILABLE)
            if (!canonicalInside(file) || !file.isFile || !file.canRead() ||
                picker.name != "native-document-picker" || picker.parentFile?.name != "app" || picker.parentFile?.parentFile?.name != "storage" ||
                !Regex("${selected.documentId}(?:\\.[a-z0-9]{1,16})?").matches(file.name) ||
                selected.size !in 1L..Contract.MAX_INPUT_BYTES || file.length() != selected.size) fail(Contract.SOURCE_UNAVAILABLE)
            if (selected.mimeType !in NativeMediaOptimizerMetadata.MIME_TYPES) fail(Contract.UNSUPPORTED_MEDIA)
            val directory = directory(File(picker.parentFile, "native-media-optimizer").path, true)
            return NativeMediaOptimizerPreparedSource(selected.documentId, file, selected.mimeType, selected.size, directory)
        } catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: Exception) { fail(Contract.SOURCE_UNAVAILABLE) }
    }

    fun verifySource(source: NativeMediaOptimizerPreparedSource) {
        val prepared = prepareResolved(NativeDocumentPickerPrivateSourceResult.Resolved(
            source.documentId, source.file, "media", source.declaredMimeType, source.size
        ))
        if (prepared.outputDirectory.path != source.outputDirectory.path) fail(Contract.SOURCE_UNAVAILABLE)
    }

    fun beginOutput(root: String, handle: NativeMediaOptimizerHandle, mimeType: String): NativeMediaOptimizerOutputTransaction {
        requireMediaWorker()
        if (Contract.requestId(handle.id) != handle.id || Contract.requestId(handle.token) != handle.token) fail(Contract.OUTPUT_FAILED)
        val extension = NativeMediaOptimizerOutput.EXTENSIONS[mimeType] ?: fail(Contract.INVALID_FORMAT)
        val directory = directory(root, false)
        val finalFile = child(directory, "${handle.id}.$extension")
        val partialFile = child(directory, ".${handle.id}.${handle.token}.part")
        if (existsNoFollow(finalFile) || existsNoFollow(partialFile)) fail(Contract.OUTPUT_FAILED)
        return NativeMediaOptimizerOutputTransaction(this, directory, partialFile, finalFile)
    }

    fun verifiedOutput(root: String, output: NativeMediaOptimizerOutput): File? {
        return try {
            val directory = directory(root, false)
            val extension = NativeMediaOptimizerOutput.EXTENSIONS[output.metadata.mimeType] ?: return null
            val file = child(directory, "${output.id}.$extension")
            if (file.path != output.path || !file.isFile || !file.canRead() || file.length() != output.metadata.size) null else file
        } catch (_: Exception) { null }
    }

    fun deleteOutput(root: String, output: NativeMediaOptimizerOutput): Boolean {
        requireMediaWorker()
        val file = verifiedOutput(root, output) ?: return false
        return try { Os.remove(file.path); syncDirectory(file.parentFile!!); true } catch (_: Exception) { false }
    }

    /** Exact files for a persisted interrupted job, never a directory sweep. */
    fun discardInterrupted(record: NativeMediaOptimizerRecord): Boolean {
        requireMediaWorker()
        if (record.result.status != "interrupted" || record.result.output != null || record.outputDirectory == null) return false
        return try {
            val root = directory(record.outputDirectory, false)
            val names = listOf(".${record.handle.id}.${record.handle.token}.part") +
                NativeMediaOptimizerOutput.EXTENSIONS.values.map { "${record.handle.id}.$it" }
            names.map { name -> removeOwned(root, name) }.all { it }
        } catch (_: Exception) { false }
    }

    internal fun directory(path: String, create: Boolean): File {
        if (path.length !in 1..4096 || path.any { it.code < 32 || it.code == 127 }) fail(Contract.OUTPUT_FAILED)
        val file = File(path)
        if (!canonicalInside(file) || file.name != "native-media-optimizer" || file.parentFile?.name != "app" || file.parentFile?.parentFile?.name != "storage") fail(Contract.OUTPUT_FAILED)
        if (create && !file.exists() && !file.mkdir()) fail(Contract.OUTPUT_FAILED)
        if (!canonicalInside(file) || !file.isDirectory || !file.canWrite()) fail(Contract.OUTPUT_FAILED)
        return file
    }

    internal fun child(root: File, name: String): File {
        val file = File(root, name)
        if (!canonicalInside(file) || file.parentFile?.path != root.path) fail(Contract.OUTPUT_FAILED)
        return file
    }

    internal fun removeOwned(root: File, name: String): Boolean = try {
        directory(root.path, false)
        val file = child(root, name)
        if (!existsNoFollow(file)) true
        else if (!file.isFile) false
        else { Os.remove(file.path); syncDirectory(root); true }
    } catch (_: Exception) { false }

    internal fun syncDirectory(root: File) {
        val fd = Os.open(root.path, OsConstants.O_RDONLY or OsConstants.O_NOFOLLOW, 0)
        try { Os.fsync(fd) } finally { Os.close(fd) }
    }

    private fun canonicalInside(file: File): Boolean = file.isAbsolute && file.canonicalFile.path == file.path &&
        file.path.startsWith(applicationRoot.path.trimEnd('/') + "/")

    internal fun existsNoFollow(file: File): Boolean = try { Os.lstat(file.path); true }
        catch (error: android.system.ErrnoException) { if (error.errno == OsConstants.ENOENT) false else throw error }

    private fun fail(code: String): Nothing = throw NativeMediaOptimizerProcessingException(code)
}

/** A new exclusive partial file is synced and moved into place without replacement. */
internal class NativeMediaOptimizerOutputTransaction(
    private val files: NativeMediaOptimizerFiles,
    private val root: File,
    private val partial: File,
    val finalFile: File
) {
    private var ownsPartial = false
    private var ownsFinal = false
    private var written = false
    private var encoderDevice: Long? = null
    private var encoderInode: Long? = null
    override fun toString() = "NativeMediaOptimizerOutputTransaction(private)"

    /** Reserve the exact private file before a native muxer opens it for writing. */
    fun prepareForEncoder(cancellation: NativeMediaOptimizerCancellation): File {
        requireMediaWorker()
        check(!written && !ownsPartial && !ownsFinal)
        cancellation.check()
        try {
            files.directory(root.path, false); files.child(root, partial.name)
            val fd = Os.open(partial.path, OsConstants.O_RDWR or OsConstants.O_CREAT or OsConstants.O_EXCL or OsConstants.O_NOFOLLOW, 384)
            ownsPartial = true
            try {
                val stat = Os.fstat(fd)
                encoderDevice = stat.st_dev; encoderInode = stat.st_ino
            } finally { Os.close(fd) }
            return partial
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (_: Exception) { throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED) }
    }

    /** Poll while encoding, then verify the same inode before sealing and publication. */
    fun checkEncoderOutput(cancellation: NativeMediaOptimizerCancellation): Long {
        requireMediaWorker(); cancellation.check()
        check(ownsPartial && !ownsFinal && encoderInode != null)
        try {
            files.directory(root.path, false); files.child(root, partial.name)
            val stat = Os.lstat(partial.path)
            if (!sameEncoderFile(stat) || (stat.st_mode and OsConstants.S_IFMT) != OsConstants.S_IFREG) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
            if (stat.st_size !in 0L..Contract.MAX_INPUT_BYTES) throw NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)
            return stat.st_size
        } catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: Exception) { throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED) }
    }

    /** The encoder must have stopped and closed its writer before this is called. */
    fun finishEncoderOutput(cancellation: NativeMediaOptimizerCancellation) {
        requireMediaWorker(); cancellation.check()
        check(!written && ownsPartial && !ownsFinal && encoderInode != null)
        if (checkEncoderOutput(cancellation) == 0L) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
        try {
            val fd = Os.open(partial.path, OsConstants.O_RDWR or OsConstants.O_NOFOLLOW, 0)
            try {
                if (!sameEncoderFile(Os.fstat(fd))) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
                Os.fsync(fd); cancellation.check()
            } finally { Os.close(fd) }
            written = true
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: Exception) { throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED) }
    }

    private fun sameEncoderFile(stat: android.system.StructStat): Boolean = stat.st_dev == encoderDevice && stat.st_ino == encoderInode

    private fun removeTransactionFile(file: File): Boolean {
        // A substituted encoder inode is not ours to delete.
        if (encoderInode != null && files.existsNoFollow(file) && !sameEncoderFile(Os.lstat(file.path))) return false
        return files.removeOwned(root, file.name)
    }

    fun write(cancellation: NativeMediaOptimizerCancellation, action: (OutputStream) -> Unit) {
        requireMediaWorker()
        check(!written && !ownsPartial && !ownsFinal)
        cancellation.check()
        try {
            files.directory(root.path, false); files.child(root, partial.name)
            val fd = Os.open(partial.path, OsConstants.O_WRONLY or OsConstants.O_CREAT or OsConstants.O_EXCL or OsConstants.O_NOFOLLOW, 384)
            ownsPartial = true
            try {
                FileOutputStream(fd).use { stream ->
                    val bounded = BoundedOutputStream(stream, cancellation)
                    action(bounded)
                    bounded.flush(); cancellation.check(); stream.fd.sync()
                }
            } finally { if (fd.valid()) Os.close(fd) }
            if (partial.length() !in 1L..Contract.MAX_INPUT_BYTES) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
            written = true
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: Exception) { throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED) }
    }

    fun publish(cancellation: NativeMediaOptimizerCancellation): File {
        requireMediaWorker()
        check(written && ownsPartial && !ownsFinal)
        cancellation.check()
        try {
            if (encoderInode != null) checkEncoderOutput(cancellation)
            files.directory(root.path, false); files.child(root, partial.name); files.child(root, finalFile.name)
            // The source and target share a private directory. Do not request replacement.
            if (files.existsNoFollow(finalFile)) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
            java.nio.file.Files.move(partial.toPath(), finalFile.toPath())
            ownsFinal = true; ownsPartial = false
            files.syncDirectory(root); cancellation.check()
            return finalFile
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (_: Exception) { throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED) }
    }

    fun discard(): Boolean {
        requireMediaWorker()
        var success = true
        if (ownsFinal) { val removed = try { removeTransactionFile(finalFile) } catch (_: Exception) { false }; if (removed) ownsFinal = false; success = removed && success }
        if (ownsPartial) { val removed = try { removeTransactionFile(partial) } catch (_: Exception) { false }; if (removed) ownsPartial = false; success = removed && success }
        return success
    }

    private class BoundedOutputStream(private val delegate: OutputStream, private val cancellation: NativeMediaOptimizerCancellation) : OutputStream() {
        private var count = 0L
        private fun reserve(length: Int) {
            cancellation.check()
            if (length < 0 || count + length > Contract.MAX_INPUT_BYTES) throw NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)
            count += length
        }
        override fun write(value: Int) { reserve(1); delegate.write(value) }
        override fun write(bytes: ByteArray, offset: Int, length: Int) { reserve(length); delegate.write(bytes, offset, length) }
        override fun flush() { delegate.flush() }
    }
}

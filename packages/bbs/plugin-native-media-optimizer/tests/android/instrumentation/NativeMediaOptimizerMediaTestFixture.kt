package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import androidx.test.platform.app.InstrumentationRegistry
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateSourceResult
import java.io.File
import java.util.UUID

internal class NativeMediaOptimizerMediaTestFixture : AutoCloseable {
    val context = InstrumentationRegistry.getInstrumentation().targetContext
    val base = File(context.cacheDir, "media-files-test-${UUID.randomUUID()}").canonicalFile
    val picker = File(base, "storage/app/native-document-picker").apply { check(mkdirs()) }
    val files = NativeMediaOptimizerFiles(context)
    val engine = NativeMediaOptimizerImageEngine(files)

    fun image(bitmap: Bitmap, mime: String = "image/png"): NativeMediaOptimizerPreparedSource {
        val format = when (mime) {
            "image/jpeg" -> Bitmap.CompressFormat.JPEG
            "image/webp" -> Bitmap.CompressFormat.WEBP_LOSSY
            else -> Bitmap.CompressFormat.PNG
        }
        val id = UUID.randomUUID().toString()
        val extension = NativeMediaOptimizerOutput.EXTENSIONS[mime]!!
        val file = File(picker, "$id.$extension")
        file.outputStream().use { check(bitmap.compress(format, 100, it)) }
        return prepared(id, file, mime)
    }

    fun raw(bytes: ByteArray, mime: String): NativeMediaOptimizerPreparedSource {
        val id = UUID.randomUUID().toString()
        val file = File(picker, "$id.bin").apply { writeBytes(bytes) }
        return prepared(id, file, mime)
    }

    fun prepared(id: String, file: File, mime: String) = files.prepareResolved(
        NativeDocumentPickerPrivateSourceResult.Resolved(id, file, "media", mime, file.length())
    )

    fun request(source: NativeMediaOptimizerPreparedSource, options: Map<String, Any> = emptyMap(), operation: String = "OptimizeImage"): NativeMediaOptimizerRequest {
        val parsed = NativeMediaOptimizerRequest.parse(operation, mapOf("id" to UUID.randomUUID().toString(), "source_document_id" to source.documentId) + options)
        return (parsed as NativeMediaOptimizerRequestParseResult.Valid).request
    }

    fun handle(request: NativeMediaOptimizerRequest) = NativeMediaOptimizerHandle(request.id, UUID.randomUUID().toString(), UUID.randomUUID().toString())
    fun outputs(source: NativeMediaOptimizerPreparedSource): List<File> = source.outputDirectory.listFiles()?.toList() ?: emptyList()
    override fun close() { base.deleteRecursively() }
}

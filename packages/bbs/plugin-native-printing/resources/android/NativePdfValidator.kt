package com.bbs.plugins.native_printing

import android.content.Context
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import java.io.File
import java.io.FileInputStream
import java.io.RandomAccessFile
import java.nio.charset.StandardCharsets

internal sealed class NativePdfValidationResult {

    data class Valid(
        val file: File,
        val pageCount: Int
    ) : NativePdfValidationResult()

    data class Invalid(
        val errorCode: String,
        val errorMessage: String
    ) : NativePdfValidationResult()
}

internal object NativePdfValidator {

    private const val TAIL_BYTES = 8192

    private val pdfSignature = "%PDF-".toByteArray(
        StandardCharsets.US_ASCII
    )

    fun validate(
        context: Context,
        path: String
    ): NativePdfValidationResult {
        val candidatePath = path.trim()

        if (candidatePath.isEmpty()) {
            return invalid(
                NativePrintingContract.FILE_NOT_FOUND,
                "The selected PDF could not be found."
            )
        }

        val candidate = try {
            File(candidatePath).canonicalFile
        } catch (_: Exception) {
            return invalid(
                NativePrintingContract.FILE_NOT_FOUND,
                "The selected PDF could not be found."
            )
        }

        if (!candidate.exists()) {
            return invalid(
                NativePrintingContract.FILE_NOT_FOUND,
                "The selected PDF could not be found."
            )
        }

        if (!candidate.isFile) {
            return invalid(
                NativePrintingContract.INVALID_FILE_TYPE,
                "The selected item is not a PDF file."
            )
        }

        if (!isInsideApplicationStorage(context, candidate)) {
            return invalid(
                NativePrintingContract.FILE_OUTSIDE_APP_STORAGE,
                "The PDF must be stored in application-local storage."
            )
        }

        if (!candidate.canRead()) {
            return invalid(
                NativePrintingContract.FILE_NOT_READABLE,
                "The selected PDF cannot be read."
            )
        }

        if (!candidate.extension.equals("pdf", ignoreCase = true)) {
            return invalid(
                NativePrintingContract.INVALID_FILE_TYPE,
                "The selected file must use the PDF file type."
            )
        }

        if (!containsPdfMarkers(candidate)) {
            return invalid(
                NativePrintingContract.INVALID_PDF,
                "The selected file is not a valid PDF."
            )
        }

        val pageCount = readPageCount(candidate)

        if (pageCount == null) {
            return invalid(
                NativePrintingContract.INVALID_PDF,
                "The selected file is not a valid PDF."
            )
        }

        return NativePdfValidationResult.Valid(
            file = candidate,
            pageCount = pageCount
        )
    }

    private fun invalid(
        errorCode: String,
        errorMessage: String
    ): NativePdfValidationResult.Invalid {
        return NativePdfValidationResult.Invalid(
            errorCode = errorCode,
            errorMessage = errorMessage
        )
    }

    private fun isInsideApplicationStorage(
        context: Context,
        candidate: File
    ): Boolean {
        return allowedRoots(context).any { root ->
            candidate.path == root.path ||
                candidate.path.startsWith(
                    root.path + File.separator
                )
        }
    }

    private fun allowedRoots(context: Context): List<File> {
        val roots = mutableListOf<File>()

        roots.add(File(context.applicationInfo.dataDir))
        roots.add(context.dataDir)
        roots.add(context.filesDir)
        roots.add(context.cacheDir)
        roots.add(context.noBackupFilesDir)
        roots.add(context.codeCacheDir)

        context.getExternalFilesDirs(null)
            .filterNotNull()
            .forEach { roots.add(it) }

        context.externalCacheDirs
            .filterNotNull()
            .forEach { roots.add(it) }

        return roots.mapNotNull { root ->
            try {
                root.canonicalFile
            } catch (_: Exception) {
                null
            }
        }.filter { root ->
            root.exists() && root.isDirectory
        }.distinctBy { root ->
            root.path
        }
    }

    private fun containsPdfMarkers(file: File): Boolean {
        return try {
            if (file.length() < pdfSignature.size) {
                return false
            }

            val headerMatches = FileInputStream(file).use { input ->
                val header = ByteArray(pdfSignature.size)
                var offset = 0

                while (offset < header.size) {
                    val count = input.read(
                        header,
                        offset,
                        header.size - offset
                    )

                    if (count < 0) {
                        return@use false
                    }

                    offset += count
                }

                header.contentEquals(pdfSignature)
            }

            if (!headerMatches) {
                return false
            }

            val tailLength = minOf(
                TAIL_BYTES.toLong(),
                file.length()
            ).toInt()

            RandomAccessFile(file, "r").use { input ->
                input.seek(file.length() - tailLength)

                val tail = ByteArray(tailLength)
                input.readFully(tail)

                String(
                    tail,
                    StandardCharsets.ISO_8859_1
                ).contains("%%EOF")
            }
        } catch (_: Exception) {
            false
        }
    }

    private fun readPageCount(file: File): Int? {
        return try {
            ParcelFileDescriptor.open(
                file,
                ParcelFileDescriptor.MODE_READ_ONLY
            ).use { descriptor ->
                PdfRenderer(descriptor).use { renderer ->
                    renderer.pageCount.takeIf { count ->
                        count > 0
                    }
                }
            }
        } catch (_: Exception) {
            null
        }
    }
}

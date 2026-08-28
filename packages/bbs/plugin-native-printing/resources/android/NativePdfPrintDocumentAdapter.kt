package com.bbs.plugins.native_printing

import android.os.Bundle
import android.os.CancellationSignal
import android.os.ParcelFileDescriptor
import android.print.PageRange
import android.print.PrintAttributes
import android.print.PrintDocumentAdapter
import android.print.PrintDocumentInfo
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

internal class NativePdfPrintDocumentAdapter(
    private val file: File,
    private val documentName: String,
    private val pageCount: Int,
    private val onFailure: (
        errorCode: String,
        errorMessage: String
    ) -> Unit
) : PrintDocumentAdapter() {

    private val executor =
        Executors.newSingleThreadExecutor()

    private val closed = AtomicBoolean(false)

    private val failureReported =
        AtomicBoolean(false)

    override fun onLayout(
        oldAttributes: PrintAttributes,
        newAttributes: PrintAttributes,
        cancellationSignal: CancellationSignal,
        callback: LayoutResultCallback,
        extras: Bundle?
    ) {
        if (
            cancellationSignal.isCanceled ||
            closed.get()
        ) {
            callback.onLayoutCancelled()
            return
        }

        if (
            !file.isFile ||
            !file.canRead() ||
            pageCount <= 0
        ) {
            val message =
                "The PDF could not be prepared for printing."

            callback.onLayoutFailed(message)

            reportFailure(
                NativePrintingContract.PRINT_JOB_FAILED,
                message
            )

            return
        }

        val information = PrintDocumentInfo.Builder(
            documentName
        ).setContentType(
            PrintDocumentInfo.CONTENT_TYPE_DOCUMENT
        ).setPageCount(
            pageCount
        ).build()

        callback.onLayoutFinished(
            information,
            oldAttributes != newAttributes
        )
    }

    @Suppress("UNUSED_PARAMETER")
    override fun onWrite(
        pages: Array<out PageRange>,
        destination: ParcelFileDescriptor,
        cancellationSignal: CancellationSignal,
        callback: WriteResultCallback
    ) {
        if (
            cancellationSignal.isCanceled ||
            closed.get()
        ) {
            callback.onWriteCancelled()
            return
        }

        try {
            executor.execute {
                copyPdf(
                    destination,
                    cancellationSignal,
                    callback
                )
            }
        } catch (_: Exception) {
            val message =
                "The PDF could not be prepared for printing."

            callback.onWriteFailed(message)

            reportFailure(
                NativePrintingContract.PRINT_JOB_FAILED,
                message
            )
        }
    }

    override fun onFinish() {
        closed.set(true)
        executor.shutdownNow()

        super.onFinish()
    }

    private fun copyPdf(
        destination: ParcelFileDescriptor,
        cancellationSignal: CancellationSignal,
        callback: WriteResultCallback
    ) {
        try {
            FileInputStream(file).use { input ->
                FileOutputStream(
                    destination.fileDescriptor
                ).use { output ->
                    val buffer = ByteArray(DEFAULT_BUFFER_SIZE)

                    while (true) {
                        if (
                            cancellationSignal.isCanceled ||
                            closed.get() ||
                            Thread.currentThread().isInterrupted
                        ) {
                            callback.onWriteCancelled()
                            return
                        }

                        val count = input.read(buffer)

                        if (count < 0) {
                            break
                        }

                        output.write(buffer, 0, count)
                    }

                    output.flush()
                }
            }

            if (
                cancellationSignal.isCanceled ||
                closed.get()
            ) {
                callback.onWriteCancelled()
                return
            }

            callback.onWriteFinished(
                arrayOf(PageRange.ALL_PAGES)
            )
        } catch (_: Exception) {
            if (
                cancellationSignal.isCanceled ||
                closed.get()
            ) {
                callback.onWriteCancelled()
                return
            }

            val message =
                "The PDF could not be prepared for printing."

            callback.onWriteFailed(message)

            reportFailure(
                NativePrintingContract.PRINT_JOB_FAILED,
                message
            )
        }
    }

    private fun reportFailure(
        errorCode: String,
        errorMessage: String
    ) {
        if (failureReported.compareAndSet(false, true)) {
            onFailure(errorCode, errorMessage)
        }
    }
}

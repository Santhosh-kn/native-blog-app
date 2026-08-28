package com.bbs.plugins.native_printing

import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.pdf.PdfRenderer
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.util.LruCache
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import java.io.File
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sqrt

internal class NativePdfPageAdapter(
    file: File,
    private val targetWidthProvider: () -> Int,
    private val onFirstPageRendered: () -> Unit,
    private val onFirstPageFailed: () -> Unit
) : RecyclerView.Adapter<
    NativePdfPageAdapter.PageViewHolder
>(), AutoCloseable {

    private companion object {
        const val MAXIMUM_BITMAP_HEIGHT = 4096
        const val MAXIMUM_BITMAP_PIXELS = 8_000_000.0
    }

    private val descriptor = ParcelFileDescriptor.open(
        file,
        ParcelFileDescriptor.MODE_READ_ONLY
    )

    private val renderer: PdfRenderer = try {
        PdfRenderer(descriptor)
    } catch (exception: Exception) {
        descriptor.close()
        throw exception
    }

    val pageCount: Int = renderer.pageCount

    private val rendererLock = Any()

    private val mainHandler = Handler(
        Looper.getMainLooper()
    )

    private val executor =
        Executors.newSingleThreadExecutor()

    private val closed = AtomicBoolean(false)

    private val firstPageRendered =
        AtomicBoolean(false)

    private val firstPageFailureReported =
        AtomicBoolean(false)

    private val pagesBeingRendered =
        ConcurrentHashMap.newKeySet<Int>()

    private val failedPages =
        ConcurrentHashMap.newKeySet<Int>()

    private val bitmapCache = object :
        LruCache<Int, Bitmap>(cacheSizeKilobytes()) {

        override fun sizeOf(
            key: Int,
            value: Bitmap
        ): Int {
            return (value.byteCount / 1024)
                .coerceAtLeast(1)
        }
    }

    init {
        setHasStableIds(true)
    }

    override fun getItemCount(): Int = pageCount

    override fun getItemId(position: Int): Long {
        return position.toLong()
    }

    override fun onCreateViewHolder(
        parent: ViewGroup,
        viewType: Int
    ): PageViewHolder {
        val context = parent.context
        val margin = densityPixels(parent, 8)
        val padding = densityPixels(parent, 8)

        val container = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            setPadding(
                padding,
                padding,
                padding,
                padding
            )
            setBackgroundColor(
                Color.rgb(232, 232, 232)
            )

            layoutParams = RecyclerView.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply {
                setMargins(
                    margin,
                    margin,
                    margin,
                    margin
                )
            }
        }

        val pageLabel = TextView(context).apply {
            gravity = Gravity.CENTER
            setTextColor(Color.DKGRAY)
            setPadding(0, 0, 0, padding)
        }

        val progress = ProgressBar(context).apply {
            isIndeterminate = true
        }

        val image = ZoomablePdfImageView(context).apply {
            visibility = View.GONE
            setBackgroundColor(Color.WHITE)

            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        }

        val error = TextView(context).apply {
            visibility = View.GONE
            gravity = Gravity.CENTER
            setTextColor(Color.DKGRAY)
            text = "Unable to render this page."
            setPadding(
                padding,
                densityPixels(parent, 32),
                padding,
                densityPixels(parent, 32)
            )
        }

        container.addView(pageLabel)
        container.addView(progress)
        container.addView(image)
        container.addView(error)

        return PageViewHolder(
            container = container,
            pageLabel = pageLabel,
            progress = progress,
            image = image,
            error = error
        )
    }

    override fun onBindViewHolder(
        holder: PageViewHolder,
        position: Int
    ) {
        holder.bind(position)
    }

    override fun onViewRecycled(
        holder: PageViewHolder
    ) {
        holder.recycle()
        super.onViewRecycled(holder)
    }

    override fun close() {
        if (!closed.compareAndSet(false, true)) {
            return
        }

        executor.shutdownNow()

        synchronized(rendererLock) {
            renderer.close()
            descriptor.close()
        }

        bitmapCache.evictAll()
        pagesBeingRendered.clear()
        failedPages.clear()
    }

    private fun requestPage(position: Int) {
        if (
            closed.get() ||
            bitmapCache.get(position) != null ||
            failedPages.contains(position)
        ) {
            return
        }

        if (!pagesBeingRendered.add(position)) {
            return
        }

        try {
            executor.execute {
                val bitmap = renderPage(position)

                mainHandler.post {
                    pagesBeingRendered.remove(position)

                    if (closed.get()) {
                        return@post
                    }

                    if (bitmap == null) {
                        failedPages.add(position)

                        if (
                            position == 0 &&
                            firstPageFailureReported
                                .compareAndSet(false, true)
                        ) {
                            onFirstPageFailed()
                        }
                    } else {
                        bitmapCache.put(position, bitmap)

                        if (
                            position == 0 &&
                            firstPageRendered
                                .compareAndSet(false, true)
                        ) {
                            onFirstPageRendered()
                        }
                    }

                    notifyItemChanged(position)
                }
            }
        } catch (_: Exception) {
            pagesBeingRendered.remove(position)
            failedPages.add(position)

            if (
                position == 0 &&
                firstPageFailureReported
                    .compareAndSet(false, true)
            ) {
                onFirstPageFailed()
            }

            notifyItemChanged(position)
        }
    }

    private fun renderPage(position: Int): Bitmap? {
        return try {
            synchronized(rendererLock) {
                if (closed.get()) {
                    return null
                }

                renderer.openPage(position).use { page ->
                    if (
                        page.width <= 0 ||
                        page.height <= 0
                    ) {
                        return null
                    }

                    val requestedWidth =
                        targetWidthProvider()
                            .coerceAtLeast(1)

                    val widthScale =
                        requestedWidth.toDouble() /
                            page.width.toDouble()

                    val heightScale =
                        MAXIMUM_BITMAP_HEIGHT.toDouble() /
                            page.height.toDouble()

                    val pixelScale = sqrt(
                        MAXIMUM_BITMAP_PIXELS /
                            (
                                page.width.toDouble() *
                                    page.height.toDouble()
                                )
                    )

                    val renderScale = min(
                        widthScale,
                        min(heightScale, pixelScale)
                    ).coerceAtMost(1_000.0)

                    if (
                        !renderScale.isFinite() ||
                        renderScale <= 0.0
                    ) {
                        return null
                    }

                    val bitmapWidth = (
                        page.width * renderScale
                    ).roundToInt().coerceAtLeast(1)

                    val bitmapHeight = (
                        page.height * renderScale
                    ).roundToInt().coerceAtLeast(1)

                    val bitmap = Bitmap.createBitmap(
                        bitmapWidth,
                        bitmapHeight,
                        Bitmap.Config.ARGB_8888
                    )

                    bitmap.eraseColor(Color.WHITE)

                    val transform = Matrix().apply {
                        setScale(
                            renderScale.toFloat(),
                            renderScale.toFloat()
                        )
                    }

                    page.render(
                        bitmap,
                        null,
                        transform,
                        PdfRenderer.Page
                            .RENDER_MODE_FOR_DISPLAY
                    )

                    bitmap
                }
            }
        } catch (_: Exception) {
            null
        }
    }

    private fun cacheSizeKilobytes(): Int {
        return (
            Runtime.getRuntime().maxMemory() /
                1024L /
                8L
            ).coerceIn(
                8_192L,
                32_768L
            ).toInt()
    }

    private fun densityPixels(
        view: View,
        value: Int
    ): Int {
        return (
            value *
                view.resources.displayMetrics.density
            ).roundToInt()
    }

    inner class PageViewHolder(
        container: View,
        private val pageLabel: TextView,
        private val progress: ProgressBar,
        private val image: ZoomablePdfImageView,
        private val error: TextView
    ) : RecyclerView.ViewHolder(container) {

        private var boundPage = RecyclerView.NO_POSITION

        fun bind(position: Int) {
            boundPage = position

            pageLabel.text =
                "Page ${position + 1} of $pageCount"

            image.resetZoom()
            image.setImageDrawable(null)
            image.visibility = View.GONE
            error.visibility = View.GONE
            progress.visibility = View.VISIBLE

            val cached = bitmapCache.get(position)

            if (cached != null) {
                showBitmap(cached)

                if (
                    position == 0 &&
                    firstPageRendered
                        .compareAndSet(false, true)
                ) {
                    onFirstPageRendered()
                }

                return
            }

            if (failedPages.contains(position)) {
                showError()
                return
            }

            requestPage(position)
        }

        fun recycle() {
            boundPage = RecyclerView.NO_POSITION
            image.resetZoom()
            image.setImageDrawable(null)
        }

        private fun showBitmap(bitmap: Bitmap) {
            if (boundPage == RecyclerView.NO_POSITION) {
                return
            }

            progress.visibility = View.GONE
            error.visibility = View.GONE
            image.visibility = View.VISIBLE
            image.setImageBitmap(bitmap)
        }

        private fun showError() {
            progress.visibility = View.GONE
            image.visibility = View.GONE
            error.visibility = View.VISIBLE
        }
    }
}

package com.bbs.plugins.native_printing

import android.content.Context
import android.content.res.Configuration
import android.graphics.Color
import android.os.Bundle
import android.print.PrintManager
import android.text.TextUtils
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.appcompat.app.AppCompatActivity
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import java.util.UUID
import kotlin.math.roundToInt

class NativePdfPreviewActivity : AppCompatActivity() {

    private lateinit var recyclerView: RecyclerView

    private lateinit var progressIndicator: ProgressBar

    private lateinit var errorText: TextView

    private lateinit var printButton: Button

    private var pageAdapter: NativePdfPageAdapter? = null

    private var validatedPdf:
        NativePdfValidationResult.Valid? = null

    private var requestId = UUID.randomUUID().toString()

    private var previewTitle = "PDF Preview"

    private var previewActive = false

    private var previewPresented = false

    private var failureDispatched = false

    private var closedDispatched = false

    @Volatile
    private var renderWidthPixels = 1

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        window.statusBarColor = Color.rgb(250, 250, 250)
        window.navigationBarColor = Color.WHITE

        val suppliedRequestId = intent.getStringExtra(
            NativePrintingContract.EXTRA_REQUEST_ID
        )

        val resolvedRequestId =
            NativePrintingContract.resolveRequestId(
                suppliedRequestId
            )

        requestId = resolvedRequestId
            ?: NativePrintingContract.safeRejectedRequestId(
                suppliedRequestId
            )

        previewTitle = NativePrintingContract.normalizeLabel(
            value = intent.getStringExtra(
                NativePrintingContract.EXTRA_TITLE
            ),
            fallback = "PDF Preview"
        )

        buildInterface()
        registerBackNavigation()

        if (resolvedRequestId == null) {
            showFailure(
                errorCode =
                    NativePrintingContract.INVALID_REQUEST_ID,
                errorMessage =
                    "The request ID must be a valid UUID."
            )

            return
        }

        openPdf(
            intent.getStringExtra(
                NativePrintingContract.EXTRA_PATH
            ).orEmpty()
        )
    }

    override fun onConfigurationChanged(
        newConfiguration: Configuration
    ) {
        super.onConfigurationChanged(newConfiguration)

        renderWidthPixels = calculateRenderWidth()
    }

    override fun finish() {
        dispatchClosedIfNeeded()
        super.finish()
    }

    override fun onDestroy() {
        if (::recyclerView.isInitialized) {
            recyclerView.adapter = null
        }

        pageAdapter?.close()
        pageAdapter = null

        super.onDestroy()
    }

    private fun buildInterface() {
        renderWidthPixels = calculateRenderWidth()

        val horizontalPadding = densityPixels(12)
        val verticalPadding = densityPixels(8)

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(
                Color.rgb(238, 238, 238)
            )

            layoutParams = ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        }

        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(
                horizontalPadding,
                verticalPadding,
                horizontalPadding,
                verticalPadding
            )
            setBackgroundColor(Color.WHITE)
            elevation = densityPixels(4).toFloat()
        }

        val closeButton = Button(this).apply {
            text = "Close"
            setAllCaps(false)

            setOnClickListener {
                finish()
            }
        }

        val titleView = TextView(this).apply {
            text = previewTitle
            textSize = 18.0f
            setTextColor(Color.rgb(32, 32, 32))
            gravity = Gravity.CENTER
            maxLines = 1
            ellipsize = TextUtils.TruncateAt.END
            setPadding(
                horizontalPadding,
                0,
                horizontalPadding,
                0
            )

            layoutParams = LinearLayout.LayoutParams(
                0,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                1.0f
            )
        }

        printButton = Button(this).apply {
            text = "Print"
            setAllCaps(false)
            isEnabled = false

            setOnClickListener {
                printCurrentPdf()
            }
        }

        header.addView(closeButton)
        header.addView(titleView)
        header.addView(printButton)

        val content = FrameLayout(this).apply {
            setBackgroundColor(
                Color.rgb(238, 238, 238)
            )

            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                0,
                1.0f
            )
        }

        recyclerView = RecyclerView(this).apply {
            layoutManager = LinearLayoutManager(
                this@NativePdfPreviewActivity,
                RecyclerView.VERTICAL,
                false
            )
            itemAnimator = null
            clipToPadding = false
            setPadding(
                densityPixels(4),
                densityPixels(4),
                densityPixels(4),
                densityPixels(12)
            )
            visibility = View.VISIBLE

            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        }

        progressIndicator = ProgressBar(this).apply {
            isIndeterminate = true

            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.CENTER
            )
        }

        errorText = TextView(this).apply {
            visibility = View.GONE
            gravity = Gravity.CENTER
            textSize = 16.0f
            setTextColor(Color.DKGRAY)
            setPadding(
                densityPixels(24),
                densityPixels(24),
                densityPixels(24),
                densityPixels(24)
            )

            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.CENTER
            )
        }

        content.addView(recyclerView)
        content.addView(progressIndicator)
        content.addView(errorText)

        root.addView(
            header,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            )
        )

        root.addView(content)

        setContentView(root)

        ViewCompat.setOnApplyWindowInsetsListener(root) { _, insets ->
            val safeInsets = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or
                    WindowInsetsCompat.Type.displayCutout()
            )

            header.setPadding(
                horizontalPadding + safeInsets.left,
                verticalPadding + safeInsets.top,
                horizontalPadding + safeInsets.right,
                verticalPadding
            )

            recyclerView.setPadding(
                densityPixels(4) + safeInsets.left,
                densityPixels(4),
                densityPixels(4) + safeInsets.right,
                densityPixels(12) + safeInsets.bottom
            )

            insets
        }

        ViewCompat.requestApplyInsets(root)
    }

    private fun registerBackNavigation() {
        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    finish()
                }
            }
        )
    }

    private fun openPdf(path: String) {
        val validation = NativePdfValidator.validate(
            context = this,
            path = path
        )

        if (
            validation is
                NativePdfValidationResult.Invalid
        ) {
            showFailure(
                errorCode = validation.errorCode,
                errorMessage = validation.errorMessage
            )

            return
        }

        validation as NativePdfValidationResult.Valid
        validatedPdf = validation

        val adapter = try {
            NativePdfPageAdapter(
                file = validation.file,
                targetWidthProvider = {
                    renderWidthPixels
                },
                onFirstPageRendered = {
                    handleFirstPageRendered()
                },
                onFirstPageFailed = {
                    showFailure(
                        errorCode =
                            NativePrintingContract.PREVIEW_FAILED,
                        errorMessage =
                            "The PDF preview could not be rendered."
                    )
                }
            )
        } catch (_: Exception) {
            showFailure(
                errorCode =
                    NativePrintingContract.PREVIEW_FAILED,
                errorMessage =
                    "The PDF preview could not be rendered."
            )

            return
        }

        if (adapter.pageCount <= 0) {
            adapter.close()

            showFailure(
                errorCode =
                    NativePrintingContract.INVALID_PDF,
                errorMessage =
                    "The selected file is not a valid PDF."
            )

            return
        }

        pageAdapter = adapter
        previewActive = true
        progressIndicator.visibility = View.VISIBLE
        recyclerView.adapter = adapter
    }

    private fun handleFirstPageRendered() {
        if (
            failureDispatched ||
            isFinishing ||
            isDestroyed
        ) {
            return
        }

        progressIndicator.visibility = View.GONE

        printButton.isEnabled =
            isPrintingAvailable()

        if (previewPresented) {
            return
        }

        previewPresented = true

        NativePrintingEvents.dispatchState(
            sourceActivity = this,
            requestId = requestId,
            action =
                NativePrintingContract.ACTION_PREVIEW,
            status =
                NativePrintingContract.STATUS_PRESENTED
        )
    }

    private fun printCurrentPdf() {
        val pdf = validatedPdf ?: return

        if (
            failureDispatched ||
            !previewPresented ||
            !isPrintingAvailable()
        ) {
            return
        }

        val printRequestId =
            UUID.randomUUID().toString()

        printButton.isEnabled = false

        val scheduled = NativePrintController.enqueue(
            activity = this,
            file = pdf.file,
            documentName = previewTitle,
            pageCount = pdf.pageCount,
            requestId = printRequestId
        )

        if (!scheduled) {
            NativePrintingEvents.dispatchState(
                sourceActivity = this,
                requestId = printRequestId,
                action =
                    NativePrintingContract.ACTION_PRINT,
                status =
                    NativePrintingContract.STATUS_FAILED,
                errorCode =
                    NativePrintingContract.ACTIVITY_UNAVAILABLE,
                errorMessage =
                    "The native activity is unavailable."
            )
        }

        printButton.postDelayed(
            {
                if (
                    !isFinishing &&
                    !isDestroyed &&
                    previewPresented &&
                    !failureDispatched
                ) {
                    printButton.isEnabled =
                        isPrintingAvailable()
                }
            },
            1_000L
        )
    }

    private fun showFailure(
        errorCode: String,
        errorMessage: String
    ) {
        if (failureDispatched) {
            return
        }

        failureDispatched = true
        printButton.isEnabled = false
        progressIndicator.visibility = View.GONE
        recyclerView.visibility = View.GONE

        recyclerView.adapter = null
        pageAdapter?.close()
        pageAdapter = null

        errorText.text = errorMessage
        errorText.visibility = View.VISIBLE

        NativePrintingEvents.dispatchState(
            sourceActivity = this,
            requestId = requestId,
            action =
                NativePrintingContract.ACTION_PREVIEW,
            status =
                NativePrintingContract.STATUS_FAILED,
            errorCode = errorCode,
            errorMessage = errorMessage
        )
    }

    private fun dispatchClosedIfNeeded() {
        if (
            !previewActive ||
            failureDispatched ||
            closedDispatched ||
            isChangingConfigurations
        ) {
            return
        }

        closedDispatched = true

        NativePrintingEvents.dispatchState(
            sourceActivity = this,
            requestId = requestId,
            action =
                NativePrintingContract.ACTION_PREVIEW,
            status =
                NativePrintingContract.STATUS_CLOSED
        )
    }

    private fun isPrintingAvailable(): Boolean {
        return getSystemService(
            Context.PRINT_SERVICE
        ) is PrintManager
    }

    private fun calculateRenderWidth(): Int {
        return (
            resources.displayMetrics.widthPixels -
                densityPixels(32)
            ).coerceAtLeast(1)
    }

    private fun densityPixels(value: Int): Int {
        return (
            value *
                resources.displayMetrics.density
            ).roundToInt()
    }
}

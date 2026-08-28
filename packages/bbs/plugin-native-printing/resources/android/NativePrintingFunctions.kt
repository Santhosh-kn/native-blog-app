package com.bbs.plugins.native_printing

import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.print.PrintManager
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

object NativePrintingFunctions {

    private const val PRINTING_FEATURE =
        "android.software.print"

    private val mainHandler = Handler(
        Looper.getMainLooper()
    )

    class IsAvailable(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val previewSupported =
                Build.VERSION.SDK_INT >=
                    Build.VERSION_CODES.LOLLIPOP

            val printingSupported =
                isPrintingSupported(context)

            val available =
                previewSupported || printingSupported

            val response = mutableMapOf<String, Any>(
                "available" to available,
                "preview_supported" to previewSupported,
                "printing_supported" to printingSupported
            )

            if (!available) {
                response["error_code"] =
                    NativePrintingContract.PRINTING_UNAVAILABLE

                response["error_message"] =
                    "Native PDF preview and printing are unavailable."
            }

            return BridgeResponse.success(response)
        }
    }

    class Preview(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val requestId =
                NativePrintingContract.resolveRequestId(
                    parameters["request_id"]
                )

            if (requestId == null) {
                return rejected(
                    requestId =
                        NativePrintingContract
                            .safeRejectedRequestId(
                                parameters["request_id"]
                            ),
                    action =
                        NativePrintingContract.ACTION_PREVIEW,
                    errorCode =
                        NativePrintingContract.INVALID_REQUEST_ID,
                    errorMessage =
                        "The request ID must be a valid UUID."
                )
            }

            if (
                Build.VERSION.SDK_INT <
                Build.VERSION_CODES.LOLLIPOP
            ) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PREVIEW,
                    errorCode =
                        NativePrintingContract.PREVIEW_UNAVAILABLE,
                    errorMessage =
                        "Native PDF preview is unavailable."
                )
            }

            if (
                activity.isFinishing ||
                activity.isDestroyed
            ) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PREVIEW,
                    errorCode =
                        NativePrintingContract.ACTIVITY_UNAVAILABLE,
                    errorMessage =
                        "The native activity is unavailable."
                )
            }

            val path = parameters["path"] as? String ?: ""

            val validation = NativePdfValidator.validate(
                context = activity,
                path = path
            )

            if (
                validation is
                    NativePdfValidationResult.Invalid
            ) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PREVIEW,
                    errorCode = validation.errorCode,
                    errorMessage = validation.errorMessage
                )
            }

            validation as NativePdfValidationResult.Valid

            val title = NativePrintingContract.normalizeLabel(
                value = parameters["title"],
                fallback = "PDF Preview"
            )

            NativePrintingEvents.rememberHost(activity)

            val scheduled = mainHandler.post {
                if (
                    activity.isFinishing ||
                    activity.isDestroyed
                ) {
                    NativePrintingEvents.dispatchState(
                        sourceActivity = activity,
                        requestId = requestId,
                        action =
                            NativePrintingContract.ACTION_PREVIEW,
                        status =
                            NativePrintingContract.STATUS_FAILED,
                        errorCode =
                            NativePrintingContract
                                .ACTIVITY_UNAVAILABLE,
                        errorMessage =
                            "The native activity is unavailable."
                    )

                    return@post
                }

                try {
                    val intent = Intent(
                        activity,
                        NativePdfPreviewActivity::class.java
                    ).putExtra(
                        NativePrintingContract.EXTRA_PATH,
                        validation.file.absolutePath
                    ).putExtra(
                        NativePrintingContract.EXTRA_TITLE,
                        title
                    ).putExtra(
                        NativePrintingContract.EXTRA_REQUEST_ID,
                        requestId
                    )

                    activity.startActivity(intent)
                } catch (_: Exception) {
                    NativePrintingEvents.dispatchState(
                        sourceActivity = activity,
                        requestId = requestId,
                        action =
                            NativePrintingContract.ACTION_PREVIEW,
                        status =
                            NativePrintingContract.STATUS_FAILED,
                        errorCode =
                            NativePrintingContract.PREVIEW_FAILED,
                        errorMessage =
                            "The native PDF preview could not be opened."
                    )
                }
            }

            if (!scheduled) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PREVIEW,
                    errorCode =
                        NativePrintingContract.ACTIVITY_UNAVAILABLE,
                    errorMessage =
                        "The native activity is unavailable."
                )
            }

            return accepted(
                requestId = requestId,
                action =
                    NativePrintingContract.ACTION_PREVIEW
            )
        }
    }

    class Print(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val requestId =
                NativePrintingContract.resolveRequestId(
                    parameters["request_id"]
                )

            if (requestId == null) {
                return rejected(
                    requestId =
                        NativePrintingContract
                            .safeRejectedRequestId(
                                parameters["request_id"]
                            ),
                    action =
                        NativePrintingContract.ACTION_PRINT,
                    errorCode =
                        NativePrintingContract.INVALID_REQUEST_ID,
                    errorMessage =
                        "The request ID must be a valid UUID."
                )
            }

            if (!isPrintingSupported(activity)) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PRINT,
                    errorCode =
                        NativePrintingContract
                            .PRINTING_UNAVAILABLE,
                    errorMessage =
                        "Android printing is unavailable."
                )
            }

            if (
                activity.isFinishing ||
                activity.isDestroyed
            ) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PRINT,
                    errorCode =
                        NativePrintingContract.ACTIVITY_UNAVAILABLE,
                    errorMessage =
                        "The native activity is unavailable."
                )
            }

            val path = parameters["path"] as? String ?: ""

            val validation = NativePdfValidator.validate(
                context = activity,
                path = path
            )

            if (
                validation is
                    NativePdfValidationResult.Invalid
            ) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PRINT,
                    errorCode = validation.errorCode,
                    errorMessage = validation.errorMessage
                )
            }

            validation as NativePdfValidationResult.Valid

            val documentName =
                NativePrintingContract.normalizeLabel(
                    value = parameters["job_name"],
                    fallback = "Document"
                )

            NativePrintingEvents.rememberHost(activity)

            val scheduled = NativePrintController.enqueue(
                activity = activity,
                file = validation.file,
                documentName = documentName,
                pageCount = validation.pageCount,
                requestId = requestId
            )

            if (!scheduled) {
                return rejected(
                    requestId = requestId,
                    action =
                        NativePrintingContract.ACTION_PRINT,
                    errorCode =
                        NativePrintingContract.ACTIVITY_UNAVAILABLE,
                    errorMessage =
                        "The native activity is unavailable."
                )
            }

            return accepted(
                requestId = requestId,
                action =
                    NativePrintingContract.ACTION_PRINT
            )
        }
    }

    private fun isPrintingSupported(
        context: Context
    ): Boolean {
        if (
            Build.VERSION.SDK_INT <
            Build.VERSION_CODES.KITKAT
        ) {
            return false
        }

        val declaresPrinting =
            context.packageManager.hasSystemFeature(
                PRINTING_FEATURE
            )

        val hasPrintManager =
            context.getSystemService(
                Context.PRINT_SERVICE
            ) is PrintManager

        return declaresPrinting && hasPrintManager
    }

    private fun accepted(
        requestId: String,
        action: String
    ): Map<String, Any> {
        return BridgeResponse.success(
            mapOf(
                "accepted" to true,
                "request_id" to requestId,
                "action" to action,
                "status" to
                    NativePrintingContract.STATUS_ACCEPTED
            )
        )
    }

    private fun rejected(
        requestId: String,
        action: String,
        errorCode: String,
        errorMessage: String
    ): Map<String, Any> {
        return BridgeResponse.success(
            mapOf(
                "accepted" to false,
                "request_id" to requestId,
                "action" to action,
                "status" to
                    NativePrintingContract.STATUS_FAILED,
                "error_code" to errorCode,
                "error_message" to errorMessage
            )
        )
    }
}

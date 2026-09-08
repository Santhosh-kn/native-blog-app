package com.bbs.plugins.native_document_picker

import android.content.Context
import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import java.util.UUID

object NativeDocumentPickerFunctions {

    class Pick(private val activity: FragmentActivity) : BridgeFunction {

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val validation = try {
                NativeDocumentPickerRequest.fromParameters(parameters)
            } catch (_: RuntimeException) {
                NativeDocumentPickerRequestValidation.Invalid(id = UUID.randomUUID().toString(), errorCode = NativeDocumentPickerContract.UNKNOWN_ERROR)
            }

            return when (validation) {
                is NativeDocumentPickerRequestValidation.Invalid -> {
                    rejected(
                        NativeDocumentPickerResult.failed(
                            id = validation.id,
                            errorCode = validation.errorCode
                        )
                    )
                }

                is NativeDocumentPickerRequestValidation.Valid -> {
                    start(validation.request)
                }
            }
        }

        private fun start(
            request: NativeDocumentPickerRequest
        ): Map<String, Any> {
            if (Looper.myLooper() == Looper.getMainLooper()) {
                val failure = launchOnMainThread(request)

                if (failure != null) {
                    persistTerminalResult(failure)
                    return rejected(failure)
                }

                return accepted(request)
            }

            val scheduled = try {
                Handler(Looper.getMainLooper()).post {
                    val failure = launchOnMainThread(request)

                    if (failure != null) {
                        persistTerminalResult(failure)
                        NativeDocumentPickerEventDispatcher.dispatch(
                            activity = activity,
                            result = failure
                        )
                    }
                }
            } catch (_: RuntimeException) {
                false
            }

            if (!scheduled) {
                val failure = activityUnavailable(request.id)

                persistTerminalResult(failure)
                return rejected(failure)
            }

            return accepted(request)
        }

        private fun launchOnMainThread(
            request: NativeDocumentPickerRequest
        ): NativeDocumentPickerResult? {
            val coordinator = NativeDocumentPickerCoordinator.install(
                activity
            ) ?: return activityUnavailable(request.id)

            return try {
                coordinator.launch(request)
                null
            } catch (_: RuntimeException) {
                NativeDocumentPickerResult.failed(
                    id = request.id,
                    errorCode =
                        NativeDocumentPickerContract.PICKER_LAUNCH_FAILED
                )
            }
        }

        private fun persistTerminalResult(
            result: NativeDocumentPickerResult
        ) {
            try {
                NativeDocumentPickerStore(activity).complete(result)
            } catch (_: RuntimeException) {
                // The controlled bridge or event result remains available.
            }
        }

        private fun accepted(
            request: NativeDocumentPickerRequest
        ): Map<String, Any> {
            return BridgeResponse.success(
                buildMap {
                    put("accepted", true)
                    putAll(
                        NativeDocumentPickerResult.pending(request)
                            .toBridgeMap()
                    )
                }
            )
        }

        private fun rejected(
            result: NativeDocumentPickerResult
        ): Map<String, Any> {
            return BridgeResponse.success(
                buildMap {
                    put("accepted", false)
                    putAll(result.toBridgeMap())
                }
            )
        }

        private fun activityUnavailable(
            id: String
        ): NativeDocumentPickerResult {
            return NativeDocumentPickerResult.failed(
                id = id,
                errorCode =
                    NativeDocumentPickerContract.ACTIVITY_UNAVAILABLE
            )
        }
    }

    class GetStatus(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = try {
                NativeDocumentPickerContract.normalizeRequestId(
                    parameters["id"]
                )
            } catch (_: RuntimeException) {
                null
            }

            val result = if (id == null) {
                NativeDocumentPickerResult.failed(
                    id = UUID.randomUUID().toString(),
                    errorCode =
                        NativeDocumentPickerContract.INVALID_REQUEST_ID
                )
            } else {
                savedResult(id)
            }

            return BridgeResponse.success(result.toBridgeMap())
        }

        private fun savedResult(
            id: String
        ): NativeDocumentPickerResult {
            return try {
                NativeDocumentPickerStore(context).result(id)
                    ?: NativeDocumentPickerResult.notFound(id)
            } catch (_: RuntimeException) {
                NativeDocumentPickerResult.failed(
                    id = id,
                    errorCode = NativeDocumentPickerContract
                        .RESULT_PERSISTENCE_FAILED
                )
            }
        }
    }
}

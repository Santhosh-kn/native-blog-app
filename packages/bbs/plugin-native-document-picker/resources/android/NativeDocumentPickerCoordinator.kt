package com.bbs.plugins.native_document_picker

import android.content.ActivityNotFoundException
import android.content.Context
import android.net.Uri
import android.os.Handler
import android.os.Looper
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import java.io.File
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.RejectedExecutionException

internal class NativeDocumentPickerCoordinator : Fragment() {

    private val mainHandler = Handler(Looper.getMainLooper())
    private val copyExecutor: ExecutorService = Executors.newSingleThreadExecutor()

    private lateinit var store: NativeDocumentPickerStore
    private lateinit var copier: NativeDocumentPickerCopier

    private val launcher = registerForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri ->
        handlePickerResult(uri)
    }

    override fun onAttach(context: Context) {
        super.onAttach(context)

        store = NativeDocumentPickerStore(context)
        copier = NativeDocumentPickerCopier(context)
    }

    override fun onDestroy() {
        copyExecutor.shutdown()
        super.onDestroy()
    }

    fun launch(request: NativeDocumentPickerRequest) {
        if (Looper.myLooper() != Looper.getMainLooper()) {
            mainHandler.post {
                launch(request)
            }

            return
        }

        val hostActivity = activity as? FragmentActivity

        if (!isAdded || hostActivity == null || hostActivity.isFinishing || hostActivity.isDestroyed) {
            completeAndDispatch(
                NativeDocumentPickerResult.failed(
                    id = request.id,
                    errorCode = NativeDocumentPickerContract.ACTIVITY_UNAVAILABLE
                )
            )

            return
        }

        val beginResult = try {
            store.begin(request)
        } catch (_: RuntimeException) {
            NativeDocumentPickerBeginResult.Failed
        }

        when (beginResult) {
            NativeDocumentPickerBeginResult.Stored ->
                launchSystemPicker(request)

            NativeDocumentPickerBeginResult.Busy ->
                completeAndDispatch(
                    NativeDocumentPickerResult.failed(
                        id = request.id,
                        errorCode = NativeDocumentPickerContract.PICKER_BUSY
                    )
                )

            NativeDocumentPickerBeginResult.Failed ->
                completeAndDispatch(
                    NativeDocumentPickerResult.failed(
                        id = request.id,
                        errorCode = NativeDocumentPickerContract.RESULT_PERSISTENCE_FAILED
                    )
                )
        }
    }

    private fun launchSystemPicker(request: NativeDocumentPickerRequest) {
        try {
            launcher.launch(request.mimeTypes.toTypedArray())
        } catch (_: ActivityNotFoundException) {
            completeAndDispatch(
                NativeDocumentPickerResult.failed(
                    id = request.id,
                    errorCode = NativeDocumentPickerContract.PICKER_UNAVAILABLE
                )
            )
        } catch (_: RuntimeException) {
            completeAndDispatch(
                NativeDocumentPickerResult.failed(
                    id = request.id,
                    errorCode = NativeDocumentPickerContract.PICKER_LAUNCH_FAILED
                )
            )
        }
    }

    private fun handlePickerResult(uri: Uri?) {
        val request = try {
            store.activeRequest()
        } catch (_: RuntimeException) {
            null
        } ?: return

        if (uri == null) {
            completeAndDispatch(
                NativeDocumentPickerResult.cancelled(request.id)
            )

            return
        }

        try {
            copyExecutor.execute {
                copySelectedDocument(
                    uri = uri,
                    request = request
                )
            }
        } catch (_: RejectedExecutionException) {
            completeAndDispatch(
                NativeDocumentPickerResult.failed(
                    id = request.id,
                    errorCode = NativeDocumentPickerContract.COPY_FAILED
                )
            )
        }
    }

    private fun copySelectedDocument(
        uri: Uri,
        request: NativeDocumentPickerRequest
    ) {
        val copyResult = try {
            copier.copy(
                uri = uri,
                request = request
            )
        } catch (_: RuntimeException) {
            NativeDocumentPickerCopyResult.Rejected(
                errorCode = NativeDocumentPickerContract.UNKNOWN_ERROR
            )
        }

        when (copyResult) {
            is NativeDocumentPickerCopyResult.Copied -> {
                val result = NativeDocumentPickerResult.succeeded(
                    id = request.id,
                    path = copyResult.file.path,
                    originalName = copyResult.originalName,
                    mimeType = copyResult.mimeType,
                    size = copyResult.size
                )

                completeAndDispatch(
                    result = result,
                    copiedFile = copyResult.file
                )
            }

            is NativeDocumentPickerCopyResult.Rejected -> {
                val errorCode = copyResult.errorCode.takeIf(
                    NativeDocumentPickerContract::isKnownErrorCode
                ) ?: NativeDocumentPickerContract.UNKNOWN_ERROR

                completeAndDispatch(
                    NativeDocumentPickerResult.failed(
                        id = request.id,
                        errorCode = errorCode
                    )
                )
            }
        }
    }

    private fun completeAndDispatch(result: NativeDocumentPickerResult, copiedFile: File? = null) {
        val persisted = try {
            store.complete(result)
        } catch (_: RuntimeException) {
            false
        }

        if (persisted) {
            dispatchOnMainThread(result)
            return
        }

        copiedFile?.let { file ->
            try {
                copier.discard(file)
            } catch (_: RuntimeException) {
                // The file remains inside application-private storage.
            }
        }

        val persistenceFailure = NativeDocumentPickerResult.failed(
            id = result.id,
            errorCode = NativeDocumentPickerContract.RESULT_PERSISTENCE_FAILED
        )

        try {
            store.complete(persistenceFailure)
        } catch (_: RuntimeException) {
            // Dispatch remains available for the current application process.
        }

        dispatchOnMainThread(persistenceFailure)
    }

    private fun dispatchOnMainThread( result: NativeDocumentPickerResult ) {
        mainHandler.post {
            val hostActivity = activity as? FragmentActivity ?: return@post

            NativeDocumentPickerEventDispatcher.dispatch(activity = hostActivity, result = result)
        }
    }

    companion object {
        private const val FRAGMENT_TAG = "Bbs.NativeDocumentPicker.Coordinator"

        fun install(
            activity: FragmentActivity
        ): NativeDocumentPickerCoordinator? {
            if (Looper.myLooper() != Looper.getMainLooper() || activity.isFinishing || activity.isDestroyed || activity.supportFragmentManager.isDestroyed) {
                return null
            }

            val fragmentManager = activity.supportFragmentManager
            val existing = fragmentManager.findFragmentByTag(FRAGMENT_TAG)

            if (existing is NativeDocumentPickerCoordinator) {
                return existing
            }

            if (existing != null) {
                return null
            }

            return try {
                NativeDocumentPickerCoordinator().also { coordinator ->
                    fragmentManager.beginTransaction()
                        .add(coordinator, FRAGMENT_TAG)
                        .commitNowAllowingStateLoss()
                }
            } catch (_: RuntimeException) {
                null
            }
        }
    }
}

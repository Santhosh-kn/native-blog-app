package com.bbs.plugins.native_nfc

import android.app.Activity
import android.app.Application
import android.os.Bundle
import android.nfc.NfcAdapter
import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import java.lang.ref.WeakReference
import android.content.Context
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

internal object NativeNfcCoordinator {

    private val mainHandler = Handler(Looper.getMainLooper())
    private val tagExecutor: ExecutorService = Executors.newSingleThreadExecutor()
    private var store: NativeNfcStore? = null

    private var activityReference: WeakReference<FragmentActivity>? = null
    private var adapter: NfcAdapter? = null

    private var lifecycleRegistered = false
    private var readerModeEnabled = false

    private val operationLock = Any()

    private var activeHandle: NativeNfcOperationHandle? = null

    private var tagProcessingToken: String? = null

    private fun onTagDiscovered(tag: android.nfc.Tag) {
        val handle = synchronized(operationLock) {
            val current = activeHandle ?: return
            if (tagProcessingToken != null) return
            tagProcessingToken = current.token
            current
        }

        try {
            tagExecutor.execute {
                processDiscoveredTag(handle, tag)
            }
        } catch (_: java.util.concurrent.RejectedExecutionException) {
            completeOperation(
                handle = handle,
                terminalStatus = NativeNfcContract.STATUS_FAILED,
                error = NativeNfcContract.ERROR_UNKNOWN
            )
        } catch (_: RuntimeException) {
            completeOperation(
                handle = handle,
                terminalStatus = NativeNfcContract.STATUS_FAILED,
                error = NativeNfcContract.ERROR_UNKNOWN
            )
        }
    }


    private fun getStoreFromHandle(
        handle: NativeNfcOperationHandle
    ): NativeNfcStore? =
        synchronized(operationLock) {
            val current = activeHandle ?: return@synchronized null

            if (
                current.id != handle.id ||
                current.token != handle.token ||
                current.processId != handle.processId
            ) {
                return@synchronized null
            }

            store
        }

    private fun processDiscoveredTag(
        handle: NativeNfcOperationHandle,
        tag: android.nfc.Tag
    ) {
        val isCurrent = synchronized(operationLock) {
            activeHandle?.let {
                it.id == handle.id &&
                    it.token == handle.token &&
                    it.processId == handle.processId &&
                    it.operation == handle.operation &&
                    tagProcessingToken == handle.token
            } == true
        }

        if (!isCurrent) return

        try {
            when (handle.operation) {
                NativeNfcContract.OP_START_SCAN -> {
                    when (val result = NativeNfcTagHandler.readText(tag)) {
                        is NativeNfcTagReadResult.Success -> {
                            val size = result.text.toByteArray(
                                java.nio.charset.StandardCharsets.UTF_8
                            ).size

                            if (size > NativeNfcContract.MAX_TEXT_BYTES) {
                                completeOperation(
                                    handle,
                                    NativeNfcContract.STATUS_FAILED,
                                    NativeNfcContract.ERROR_TEXT_TOO_LARGE
                                )
                            } else {
                                completeOperation(
                                    handle,
                                    NativeNfcContract.STATUS_READ,
                                    scanText = result.text
                                )
                            }
                        }
                        is NativeNfcTagReadResult.Failure -> {
                            completeOperation(
                                handle,
                                NativeNfcContract.STATUS_FAILED,
                                result.errorCode
                            )
                        }
                    }
                }
                NativeNfcContract.OP_START_WRITE -> {
                    val text = getStoreFromHandle(handle)?.writeText(handle)

                    if (text == null) {
                        completeOperation(
                            handle,
                            NativeNfcContract.STATUS_FAILED,
                            NativeNfcContract.ERROR_UNKNOWN
                        )
                    } else {
                        when (val result = NativeNfcTagHandler.writeText(tag, text)) {
                            NativeNfcTagWriteResult.Written -> {
                                completeOperation(
                                    handle,
                                    NativeNfcContract.STATUS_WRITTEN
                                )
                            }
                            is NativeNfcTagWriteResult.Failed -> {
                                completeOperation(
                                    handle,
                                    NativeNfcContract.STATUS_FAILED,
                                    result.errorCode
                                )
                            }
                            is NativeNfcTagWriteResult.Unknown -> {
                                completeOperation(
                                    handle,
                                    NativeNfcContract.STATUS_UNKNOWN,
                                    result.errorCode
                                )
                            }
                        }
                    }
                }
                else -> {
                    completeOperation(
                        handle,
                        NativeNfcContract.STATUS_FAILED,
                        NativeNfcContract.ERROR_UNKNOWN
                    )
                }
            }
        } catch (_: Exception) {
            completeOperation(
                handle,
                NativeNfcContract.STATUS_FAILED,
                NativeNfcContract.ERROR_UNKNOWN
            )
        }
    }

    private val readerCallback =
        NfcAdapter.ReaderCallback { tag ->
            onTagDiscovered(tag)
        }

    private val lifecycleCallbacks =
        object : Application.ActivityLifecycleCallbacks {

            override fun onActivityResumed(activity: Activity) {
                val host = activityReference?.get() ?: return

                if (activity === host) {
                    onActivityResume()
                }
            }

            override fun onActivityPaused(activity: Activity) {
                val host = activityReference?.get() ?: return

                if (activity === host) {
                    disableReaderMode()
                }
            }

            override fun onActivityDestroyed(activity: Activity) {
                val host = activityReference?.get() ?: return

                if (activity === host) {
                    disableReaderMode()
                    activityReference?.clear()
                    activityReference = null
                    adapter = null
                }
            }

            override fun onActivityCreated(
                activity: Activity,
                savedInstanceState: Bundle?
            ) = Unit

            override fun onActivityStarted(activity: Activity) = Unit

            override fun onActivityStopped(activity: Activity) = Unit

            override fun onActivitySaveInstanceState(
                activity: Activity,
                outState: Bundle
            ) = Unit
        }

    /**
     * Attaches the current NativePHP Activity.
     * Lifecycle callbacks are registered once per process.
     */
    fun ensure(activity: FragmentActivity): Boolean {
        val reference = WeakReference(activity)

        return postMain {
            val host = reference.get() ?: return@postMain

            if (!isActivityUsable(host)) {
                return@postMain
            }

            val current = activityReference?.get()

            if (current !== host) {
                disableReaderMode()

                activityReference?.clear()
                activityReference = WeakReference(host)

                adapter = NativeNfcAvailabilityChecker
                    .check(host.applicationContext)
                    .adapter
            }

            registerLifecycleCallbacks(host.application)

            if (
                host.lifecycle.currentState.isAtLeast(
                    Lifecycle.State.RESUMED
                )
            ) {
                onActivityResume()
            }
        }
    }

    private fun registerLifecycleCallbacks(
        application: Application
    ) {
        if (lifecycleRegistered) {
            return
        }

        application.registerActivityLifecycleCallbacks(
            lifecycleCallbacks
        )

        lifecycleRegistered = true
    }

    private fun onActivityResume() {
        val host = activityReference?.get() ?: return
        if (!isActivityUsable(host)) return

        val shouldEnable = synchronized(operationLock) {
            activeHandle != null && tagProcessingToken == null
        }

        if (shouldEnable) {
            enableReaderMode(host)
        }
    }


    private fun enableReaderMode(host: FragmentActivity) {
        if (readerModeEnabled) return

        val handle = synchronized(operationLock) {
            if (tagProcessingToken == null) activeHandle else null
        } ?: return

        if (!isActivityUsable(host)) {
            completeOperation(handle, NativeNfcContract.STATUS_FAILED, NativeNfcContract.ERROR_ACTIVITY_UNAVAILABLE)
            return
        }

        val currentAdapter = adapter
        if (currentAdapter == null) {
            completeOperation(handle, NativeNfcContract.STATUS_FAILED, NativeNfcContract.ERROR_NFC_UNAVAILABLE)
            return
        }

        if (!currentAdapter.isEnabled) {
            completeOperation(handle, NativeNfcContract.STATUS_FAILED, NativeNfcContract.ERROR_NFC_DISABLED)
            return
        }

        try {
            currentAdapter.enableReaderMode(
                host,
                readerCallback,
                NfcAdapter.FLAG_READER_NFC_A or
                    NfcAdapter.FLAG_READER_NFC_B or
                    NfcAdapter.FLAG_READER_NFC_F or
                    NfcAdapter.FLAG_READER_NFC_V,
                null
            )
            readerModeEnabled = true
        } catch (_: RuntimeException) {
            completeOperation(handle, NativeNfcContract.STATUS_FAILED, NativeNfcContract.ERROR_UNKNOWN)
        }
    }


    private fun disableReaderMode() {
        if (!readerModeEnabled) {
            return
        }

        val host = activityReference?.get()

        try {
            if (host != null && !host.isDestroyed) {
                adapter?.disableReaderMode(host)
            }
        } catch (_: RuntimeException) {
            // Activity may already be transitioning out of the foreground.
        } finally {
            readerModeEnabled = false
        }
    }

    private fun isActivityUsable(
        activity: FragmentActivity
    ): Boolean =
        !activity.isFinishing &&
            !activity.isDestroyed &&
            activity.lifecycle.currentState.isAtLeast(
                Lifecycle.State.CREATED
            )

    private fun postMain(action: () -> Unit): Boolean =
        try {
            if (Looper.myLooper() == Looper.getMainLooper()) {
                action()
                true
            } else {
                mainHandler.post(Runnable { action() })
            }
        } catch (_: RuntimeException) {
            false
        }

    fun getStore(context: Context): NativeNfcStore =
        synchronized(this) {
            store ?: NativeNfcStore
                .forContext(context.applicationContext)
                .also { store = it }
        }

    fun beginOperation(
        context: Context,
        id: String,
        operation: String,
        text: String? = null
    ): NativeNfcBeginResult {
        val result = synchronized(operationLock) {
            if (activeHandle != null) {
                return@synchronized NativeNfcBeginResult.Busy
            }

            val started = getStore(context).begin(
                id = id,
                operation = operation,
                text = text
            )

            if (started is NativeNfcBeginResult.Started) {
                activeHandle = started.handle
            }

            started
        }

        if (result is NativeNfcBeginResult.Started) {
            val handle = result.handle
            val scheduled = postMain {
                val host = activityReference?.get()
                if (host == null || host.isFinishing || host.isDestroyed) {
                    completeOperation(
                        handle,
                        NativeNfcContract.STATUS_FAILED,
                        NativeNfcContract.ERROR_ACTIVITY_UNAVAILABLE
                    )
                } else if (
                    host.lifecycle.currentState.isAtLeast(
                        Lifecycle.State.RESUMED
                    )
                ) {
                    enableReaderMode(host)
                }
            }

            if (!scheduled) {
                completeOperation(
                    handle,
                    NativeNfcContract.STATUS_FAILED,
                    NativeNfcContract.ERROR_ACTIVITY_UNAVAILABLE
                )
            }
        }

        return result
    }


    fun completeOperation(
        handle: NativeNfcOperationHandle,
        terminalStatus: String,
        error: String? = null,
        scanText: String? = null
    ): Boolean {
        val completion = synchronized(operationLock) {
            val current = activeHandle ?: return@synchronized null

            if (
                current.id != handle.id ||
                current.token != handle.token ||
                current.processId != handle.processId ||
                current.operation != handle.operation
            ) {
                return@synchronized null
            }

            val result = store?.complete(
                handle = handle,
                terminalStatus = terminalStatus,
                error = error,
                scanText = scanText
            ) ?: return@synchronized null

            activeHandle = null
            if (tagProcessingToken == handle.token) {
                tagProcessingToken = null
            }
            result to activityReference?.get()
        }

        if (completion != null) {
            val (result, activity) = completion

            postMain {
                synchronized(operationLock) {
                    if (activeHandle == null) {
                        disableReaderMode()
                    }
                }

                if (activity != null) {
                    NativeNfcEventDispatcher.dispatch(activity, result)
                }
            }

            return true
        }

        return false
    }

    fun stopOperation(id: String): Boolean {
        val completion = synchronized(operationLock) {
            val current = activeHandle ?: return@synchronized null

            if (current.id != id || tagProcessingToken == current.token) {
                return@synchronized null
            }

            val result = store?.complete(
                handle = current,
                terminalStatus = NativeNfcContract.STATUS_CANCELLED
            ) ?: return@synchronized null

            activeHandle = null
            result to activityReference?.get()
        } ?: return false

        val (result, activity) = completion

        postMain {
            synchronized(operationLock) {
                if (activeHandle == null) {
                    disableReaderMode()
                }
            }

            if (activity != null) {
                NativeNfcEventDispatcher.dispatch(activity, result)
            }
        }

        return true
    }

    override fun toString(): String =
        "NativeNfcCoordinator(private)"
}

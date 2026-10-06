package com.bbs.plugins.native_contacts

import android.app.Activity
import android.content.Context
import android.net.Uri
import android.os.Bundle
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import androidx.activity.result.ActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import java.lang.ref.WeakReference
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.atomic.AtomicReference

/**
 * One Fragment and launcher identity per picker request.
 *
 * Arguments contain only the request ID and callback token.
 * Restoration never launches the picker or repeats a provider read.
 */
class NativeContactsPickerFragment : Fragment() {

    private var resultReceived = false
    private var launchedHere = false

    // Registration is unconditional and happens before Fragment creation.
    private val picker = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        receive(result)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        resultReceived =
            savedInstanceState?.getBoolean(KEY_RECEIVED, false) ?: false

        val binding = identity() ?: return
        val appContext = requireContext().applicationContext
        val activityReference = WeakReference(requireActivity())
        val alreadyReceived = resultReceived

        NativeContactsWork.state {
            try {
                val store = NativeContactsStore(appContext)
                val restored = store.restorePicker(
                    binding.first,
                    binding.second
                )

                if (alreadyReceived && restored != null) {
                    // The callback was consumed, but its read cannot be replayed.
                    completePersisted(
                        store,
                        restored.handle,
                        Contract.STATUS_UNKNOWN,
                        Contract.OPERATION_INTERRUPTED,
                        activityReference = activityReference
                    )
                }
            } catch (_: Exception) {
                // GetStatus exposes controlled storage failures.
            }
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        outState.putBoolean(KEY_RECEIVED, resultReceived)
        super.onSaveInstanceState(outState)
    }

    override fun onResume() {
        super.onResume()

        val binding = identity() ?: return
        val appContext = requireContext().applicationContext
        val alreadyReceived = resultReceived

        NativeContactsWork.state {
            try {
                val store = NativeContactsStore(appContext)
                store.purgeExpired()

                if (
                    alreadyReceived &&
                    store.getStatus(binding.first).status !=
                        Contract.STATUS_PENDING
                ) {
                    retire()
                }
            } catch (_: Exception) {
                // No provider access or private payload delivery on resume.
            }
        }
    }

    internal fun identity(): Pair<String, String>? {
        return try {
            val id = Contract.requestId(arguments?.getString(KEY_ID))
                ?: return null
            val token = Contract.requestId(arguments?.getString(KEY_TOKEN))
                ?: return null

            id to token
        } catch (_: RuntimeException) {
            null
        }
    }

    internal fun launch(request: NativeContactsRequest) {
        check(Looper.myLooper() == Looper.getMainLooper())
        check(request.operation == Contract.PICK)
        check(identity()?.first == request.id)
        check(!launchedHere)

        launchedHere = true
        picker.launch(NativeContactsIntents.forRequest(request))
    }

    private fun receive(result: ActivityResult) {
        if (resultReceived) {
            return
        }

        resultReceived = true

        val binding = identity() ?: return
        val appContext = context?.applicationContext ?: return
        val host = activity ?: return
        val activityReference = WeakReference(host)

        // Ignore all returned extras; only the selected data URI is used.
        val returnedUri = try {
            result.data?.data
        } catch (_: RuntimeException) {
            null
        }

        val queued = NativeContactsWork.state {
            processResult(
                appContext,
                binding,
                result.resultCode,
                returnedUri,
                activityReference
            )
        }

        if (!queued) {
            // Fail closed: never read contacts without the durable transition.
            NativeContactsWork.read {
                failUnreadCallback(
                    appContext,
                    binding,
                    activityReference
                )
            }
        }
    }

    private fun processResult(
        appContext: Context,
        binding: Pair<String, String>,
        resultCode: Int,
        returnedUri: Uri?,
        activityReference: WeakReference<FragmentActivity>
    ) {
        var ownedHandle: NativeContactsPendingHandle? = null

        try {
            val store = NativeContactsStore(appContext)
            val pending = store.restorePicker(
                binding.first,
                binding.second
            )

            if (pending == null) {
                retire()
                return
            }

            ownedHandle = pending.handle

            if (resultCode == Activity.RESULT_CANCELED) {
                completePersisted(
                    store,
                    pending.handle,
                    Contract.STATUS_CANCELLED,
                    activityReference = activityReference
                )
                return
            }

            if (resultCode != Activity.RESULT_OK) {
                completePersisted(
                    store,
                    pending.handle,
                    Contract.STATUS_FAILED,
                    Contract.INVALID_SELECTION,
                    activityReference = activityReference
                )
                return
            }

            val readingHandle =
                store.markReadingPicker(pending.handle) ?: return

            ownedHandle = readingHandle

            val mode = pending.result.mode
                ?: throw IllegalStateException(
                    "Invalid contacts picker metadata."
                )

            readSelection(
                appContext,
                store,
                readingHandle,
                mode,
                returnedUri,
                activityReference
            )
        } catch (_: Exception) {
            ownedHandle?.let { handle ->
                try {
                    completePersisted(
                        NativeContactsStore(appContext),
                        handle,
                        Contract.STATUS_FAILED,
                        Contract.RESULT_PERSISTENCE_FAILED,
                        activityReference = activityReference
                    )
                } catch (_: Exception) {
                    // No unverified result or event escapes.
                }
            }
            retire()
        }
    }

    private fun failUnreadCallback(
        appContext: Context,
        binding: Pair<String, String>,
        activityReference: WeakReference<FragmentActivity>
    ) {
        try {
            val store = NativeContactsStore(appContext)
            val pending = store.restorePicker(
                binding.first,
                binding.second
            ) ?: return

            completePersisted(
                store,
                pending.handle,
                Contract.STATUS_FAILED,
                Contract.CONTACT_READ_FAILED,
                activityReference = activityReference
            )
        } catch (_: Exception) {
            // No provider read is attempted.
        }
    }

    private fun readSelection(
        appContext: Context,
        store: NativeContactsStore,
        handle: NativeContactsPendingHandle,
        mode: String,
        returnedUri: Uri?,
        activityReference: WeakReference<FragmentActivity>
    ) {
        val gate = NativeContactsReadGate()
        val cancellation = CancellationSignal()
        val deadlineReference =
            AtomicReference<ScheduledFuture<*>?>(null)

        fun finish(outcome: NativeContactsSelectionReadResult) {
            val action: () -> Unit = {
                when (outcome) {
                    is NativeContactsSelectionReadResult.Selected ->
                        completePersisted(
                            store,
                            handle,
                            Contract.STATUS_SELECTED,
                            selection = outcome.selection,
                            activityReference = activityReference
                        )

                    is NativeContactsSelectionReadResult.Failed ->
                        completePersisted(
                            store,
                            handle,
                            Contract.STATUS_FAILED,
                            outcome.errorCode,
                            activityReference = activityReference
                        )
                }
            }

            // Both callers are worker threads; fallback never runs on main.
            if (!NativeContactsWork.state(action)) {
                action()
            }
        }

        val deadline = NativeContactsWork.after(READ_DEADLINE_MS) {
            if (gate.timeOut()) {
                finish(
                    NativeContactsSelectionReadResult.Failed(
                        Contract.CONTACT_READ_FAILED
                    )
                )

                // Remote cancellation is best effort and cannot block timers.
                NativeContactsWork.read {
                    try {
                        cancellation.cancel()
                    } catch (_: RuntimeException) {
                        // Late read results remain suppressed.
                    }
                }
            }
        }

        if (deadline == null) {
            gate.timeOut()
            finish(
                NativeContactsSelectionReadResult.Failed(
                    Contract.CONTACT_READ_FAILED
                )
            )
            return
        }

        deadlineReference.set(deadline)

        if (gate.isFinished()) {
            deadlineReference.getAndSet(null)?.cancel(false)
            return
        }

        val read = NativeContactsWork.read readTask@ {
            if (gate.isFinished()) {
                return@readTask
            }

            val outcome = try {
                NativeContactsSelectionReader(appContext).read(
                    mode,
                    returnedUri,
                    cancellation
                )
            } catch (_: Exception) {
                NativeContactsSelectionReadResult.Failed(
                    Contract.CONTACT_READ_FAILED
                )
            }

            if (gate.completeRead()) {
                deadlineReference.getAndSet(null)?.cancel(false)
                finish(outcome)
            }
        }

        if (read == null) {
            if (gate.completeRead()) {
                deadlineReference.getAndSet(null)?.cancel(false)
                finish(
                    NativeContactsSelectionReadResult.Failed(
                        Contract.CONTACT_READ_FAILED
                    )
                )
            }
            return
        }

        gate.bindRead(read)
    }

    private fun completePersisted(
        store: NativeContactsStore,
        handle: NativeContactsPendingHandle,
        status: String,
        errorCode: String? = null,
        selection: NativeContactsSelection? = null,
        activityReference: WeakReference<FragmentActivity>
    ) {
        val completed = try {
            store.complete(
                handle,
                status,
                errorCode,
                selection
            )
        } catch (_: Exception) {
            try {
                store.complete(
                    handle,
                    Contract.STATUS_FAILED,
                    Contract.RESULT_PERSISTENCE_FAILED
                )
            } catch (_: Exception) {
                null
            }
        }

        if (completed != null) {
            activityReference.get()?.let { host ->
                NativeContactsEventDispatcher.dispatch(host, completed)
            }
        }

        retire()
    }

    internal fun retire() {
        val reference = WeakReference(this)

        try {
            Handler(Looper.getMainLooper()).post {
                val fragment = reference.get() ?: return@post

                try {
                    if (!fragment.isAdded) {
                        return@post
                    }

                    val manager = fragment.parentFragmentManager
                    if (!manager.isStateSaved) {
                        manager.beginTransaction()
                            .remove(fragment)
                            .commit()
                    }
                } catch (_: RuntimeException) {
                    // A later lifecycle reconciliation can remove it.
                }
            }
        } catch (_: RuntimeException) {
            // Persisted metadata remains recoverable.
        }
    }

    companion object {
        private const val KEY_ID = "bbs_contacts_request_id"
        private const val KEY_TOKEN = "bbs_contacts_callback_token"
        private const val KEY_RECEIVED = "bbs_contacts_result_received"
        private const val READ_DEADLINE_MS = 10_000L

        internal fun create(
            id: String,
            token: String
        ): NativeContactsPickerFragment {
            require(Contract.requestId(id) == id)
            require(Contract.requestId(token) == token)

            return NativeContactsPickerFragment().apply {
                arguments = Bundle().apply {
                    putString(KEY_ID, id)
                    putString(KEY_TOKEN, token)
                }
            }
        }
    }
}
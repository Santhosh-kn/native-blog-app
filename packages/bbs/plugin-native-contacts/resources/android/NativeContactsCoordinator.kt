package com.bbs.plugins.native_contacts

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import java.lang.ref.WeakReference

/**
 * Observes lifecycle changes without retaining contact inputs.
 * Its zero-argument constructor supports FragmentManager restoration.
 */
class NativeContactsLifecycleFragment : Fragment() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        NativeContactsCoordinator.ensure(requireActivity())
    }

    override fun onResume() {
        super.onResume()
        NativeContactsCoordinator.ensure(requireActivity())
    }
}

internal object NativeContactsCoordinator {

    private const val HOST_TAG = "bbs.native_contacts.lifecycle"

    /**
     * Returns whether installation/reconciliation was scheduled.
     * FragmentManager operations always run on the main thread.
     */
    fun ensure(activity: FragmentActivity): Boolean {
        val reference = WeakReference(activity)

        return postMain {
            val host = reference.get()

            if (host != null) {
                try {
                    if (canManageFragments(host)) {
                        ensureHostOnMain(host)
                        reconcileOnMain(host)
                    }
                } catch (_: RuntimeException) {
                    // A later lifecycle or bridge call can retry.
                }
            }
        }
    }

    /**
     * The bridge must durably reserve the pending request before calling this.
     * True means the UI preparation was scheduled, not that UI was launched.
     */
    fun launch(
        activity: FragmentActivity,
        request: NativeContactsRequest,
        handle: NativeContactsPendingHandle
    ): Boolean {
        if (request.id != handle.id) {
            return false
        }

        val reference = WeakReference(activity)
        val appContext = activity.applicationContext

        return postMain launchTask@ {
            val host = reference.get()

            if (host == null || !canLaunch(host)) {
                finishAsync(
                    appContext,
                    handle,
                    Contract.STATUS_FAILED,
                    Contract.ACTIVITY_UNAVAILABLE,
                    reference
                )
                return@launchTask
            }

            try {
                ensureHostOnMain(host)
                reconcileOnMain(host)

                if (
                    request.operation == Contract.PICK &&
                    host.supportFragmentManager.fragments.count {
                        it is NativeContactsPickerFragment
                    } >= Contract.MAX_STORED_RESULTS
                ) {
                    finishAsync(
                        appContext,
                        handle,
                        Contract.STATUS_FAILED,
                        Contract.OPERATION_BUSY,
                        reference
                    )
                    return@launchTask
                }
            } catch (_: RuntimeException) {
                finishAsync(
                    appContext,
                    handle,
                    Contract.STATUS_FAILED,
                    Contract.ACTIVITY_UNAVAILABLE,
                    reference
                )
                return@launchTask
            }

            val queued = NativeContactsWork.state stateTask@ {
                try {
                    val store = NativeContactsStore(appContext)
                    val intent = NativeContactsIntents.forRequest(request)

                    if (
                        Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL ||
                        intent.resolveActivity(appContext.packageManager) == null
                    ) {
                        finishNow(
                            appContext,
                            handle,
                            Contract.STATUS_FAILED,
                            Contract.OPERATION_UNAVAILABLE,
                            reference
                        )
                        return@stateTask
                    }

                    // Persist the launch phase before touching external UI.
                    val launchingHandle =
                        if (request.operation == Contract.PICK) {
                            store.markAwaitingPicker(handle)
                        } else {
                            store.markLaunching(handle)
                        } ?: return@stateTask

                    val scheduled = postMain {
                        performLaunch(
                            appContext,
                            request,
                            launchingHandle,
                            intent,
                            reference
                        )
                    }

                    if (!scheduled) {
                        finishNow(
                            appContext,
                            launchingHandle,
                            Contract.STATUS_FAILED,
                            Contract.ACTIVITY_UNAVAILABLE,
                            reference
                        )
                    }
                } catch (_: Exception) {
                    finishNow(
                        appContext,
                        handle,
                        Contract.STATUS_FAILED,
                        Contract.RESULT_PERSISTENCE_FAILED,
                        reference
                    )
                }
            }

            if (!queued) {
                finishAsync(
                    appContext,
                    handle,
                    Contract.STATUS_FAILED,
                    Contract.RESULT_PERSISTENCE_FAILED,
                    reference
                )
            }
        }
    }

    private fun performLaunch(
        appContext: Context,
        request: NativeContactsRequest,
        handle: NativeContactsPendingHandle,
        intent: Intent,
        activityReference: WeakReference<FragmentActivity>
    ) {
        val host = activityReference.get()

        if (host == null || !canLaunch(host)) {
            finishAsync(
                appContext,
                handle,
                Contract.STATUS_FAILED,
                Contract.ACTIVITY_UNAVAILABLE,
                activityReference
            )
            return
        }

        var pickerFragment: NativeContactsPickerFragment? = null

        try {
            if (request.operation == Contract.PICK) {
                val manager = host.supportFragmentManager
                val tag = "bbs.native_contacts.picker.${handle.token}"

                check(manager.findFragmentByTag(tag) == null)

                val fragment = NativeContactsPickerFragment.create(
                    handle.id,
                    handle.token
                )
                pickerFragment = fragment

                // No state-loss commit and no replay of an existing Fragment.
                manager.beginTransaction()
                    .add(fragment, tag)
                    .commitNow()

                fragment.launch(request)
            } else {
                host.startActivity(intent)

                finishAsync(
                    appContext,
                    handle,
                    Contract.STATUS_LAUNCHED,
                    null,
                    activityReference
                )
            }
        } catch (_: ActivityNotFoundException) {
            pickerFragment?.retire()
            finishAsync(
                appContext,
                handle,
                Contract.STATUS_FAILED,
                Contract.OPERATION_UNAVAILABLE,
                activityReference
            )
        } catch (_: RuntimeException) {
            pickerFragment?.retire()
            finishAsync(
                appContext,
                handle,
                Contract.STATUS_FAILED,
                Contract.LAUNCH_FAILED,
                activityReference
            )
        }
    }

    private fun ensureHostOnMain(activity: FragmentActivity) {
        check(Looper.myLooper() == Looper.getMainLooper())

        val manager = activity.supportFragmentManager
        val existing = manager.findFragmentByTag(HOST_TAG)

        if (existing != null) {
            check(existing is NativeContactsLifecycleFragment)
            return
        }

        manager.beginTransaction()
            .add(NativeContactsLifecycleFragment(), HOST_TAG)
            .commitNow()
    }

    private fun reconcileOnMain(activity: FragmentActivity) {
        check(Looper.myLooper() == Looper.getMainLooper())

        // Capture restored identities on main; never choose an arbitrary
        // active request as the owner of an Activity Result callback.
        val bindings = activity.supportFragmentManager.fragments
            .filterIsInstance<NativeContactsPickerFragment>()
            .mapNotNull { fragment ->
                fragment.identity()?.let { binding ->
                    BindingSnapshot(
                        binding.first,
                        binding.second,
                        WeakReference(fragment)
                    )
                }
            }

        val appContext = activity.applicationContext
        val activityReference = WeakReference(activity)

        NativeContactsWork.state {
            try {
                val store = NativeContactsStore(appContext)

                // Adopt valid awaiting callbacks before interrupting an
                // older process's queued, launching, or reading operation.
                bindings.forEach { binding ->
                    store.restorePicker(binding.id, binding.token)
                }

                val interrupted = store.interruptPreviousProcess()
                store.purgeExpired()

                if (interrupted != null) {
                    activityReference.get()?.let { host ->
                        NativeContactsEventDispatcher.dispatch(
                            host,
                            interrupted
                        )
                    }
                }

                bindings.forEach { binding ->
                    if (
                        store.getStatus(binding.id).status !=
                            Contract.STATUS_PENDING
                    ) {
                        binding.fragment.get()?.retire()
                    }
                }
            } catch (_: Exception) {
                // No private payload or exception details are emitted.
            }
        }
    }

    private fun finishAsync(
        appContext: Context,
        handle: NativeContactsPendingHandle,
        status: String,
        errorCode: String?,
        activityReference: WeakReference<FragmentActivity>
    ) {
        val action: () -> Unit = {
            finishNow(
                appContext,
                handle,
                status,
                errorCode,
                activityReference
            )
        }

        if (!NativeContactsWork.state(action)) {
            // The fallback also runs off main and stays bounded.
            NativeContactsWork.read(action)
        }
    }

    private fun finishNow(
        appContext: Context,
        handle: NativeContactsPendingHandle,
        status: String,
        errorCode: String?,
        activityReference: WeakReference<FragmentActivity>
    ) {
        val completed = try {
            NativeContactsStore(appContext).complete(
                handle,
                status,
                errorCode
            )
        } catch (_: Exception) {
            try {
                NativeContactsStore(appContext).complete(
                    handle,
                    Contract.STATUS_FAILED,
                    Contract.RESULT_PERSISTENCE_FAILED
                )
            } catch (_: Exception) {
                null
            }
        }

        // Store completion returns only after a verified durable write.
        if (completed != null) {
            activityReference.get()?.let { host ->
                NativeContactsEventDispatcher.dispatch(host, completed)
            }
        }
    }

    private fun canManageFragments(activity: FragmentActivity): Boolean =
        !activity.isFinishing &&
            !activity.isDestroyed &&
            activity.lifecycle.currentState.isAtLeast(
                Lifecycle.State.CREATED
            ) &&
            !activity.supportFragmentManager.isStateSaved

    private fun canLaunch(activity: FragmentActivity): Boolean =
        canManageFragments(activity) &&
            activity.lifecycle.currentState.isAtLeast(
                Lifecycle.State.RESUMED
            )

    private fun postMain(action: () -> Unit): Boolean =
        try {
            Handler(Looper.getMainLooper()).post(Runnable { action() })
        } catch (_: RuntimeException) {
            false
        }

    private data class BindingSnapshot(
        val id: String,
        val token: String,
        val fragment: WeakReference<NativeContactsPickerFragment>
    ) {
        override fun toString(): String =
            "NativeContactsBindingSnapshot(private)"
    }
}
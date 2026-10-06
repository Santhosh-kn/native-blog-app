package com.bbs.plugins.native_calendar

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
import java.lang.ref.WeakReference
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

/**
 * Restorable lifecycle observer. It retains no event inputs.
 */
class NativeCalendarLifecycleFragment : Fragment() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        NativeCalendarCoordinator.ensure(requireActivity())
    }

    override fun onResume() {
        super.onResume()
        NativeCalendarCoordinator.ensure(requireActivity())
    }
}

/**
 * Serializes reservation, launch preparation and terminal persistence.
 *
 * Event inputs exist only in the transient preparation/main-thread task.
 * Process death never causes an editor or viewer launch to be replayed.
 */
internal object NativeCalendarCoordinator {

    private const val HOST_TAG = "bbs.native_calendar.lifecycle"
    private const val BRIDGE_WAIT_MS = 5_000L
    private const val LAUNCH_WAIT_MS = 10_000L

    private val main = Handler(Looper.getMainLooper())

    // Accessed only by NativeCalendarWork's serialized state worker.
    private var active: Operation? = null

    /**
     * Main-thread fragment installation; worker-thread state maintenance.
     */
    fun ensure(activity: FragmentActivity): Boolean {
        val reference = WeakReference(activity)
        val appContext = activity.applicationContext

        return postMain {
            val host = reference.get()

            if (host != null && canManageFragments(host)) {
                try {
                    val manager = host.supportFragmentManager
                    val existing = manager.findFragmentByTag(HOST_TAG)

                    if (existing == null) {
                        manager.beginTransaction()
                            .add(NativeCalendarLifecycleFragment(), HOST_TAG)
                            .commitNow()
                    } else {
                        check(existing is NativeCalendarLifecycleFragment)
                    }

                    NativeCalendarWork.state {
                        try {
                            drainActive()
                            reconcile(
                                NativeCalendarStore(appContext),
                                reference
                            )
                        } catch (_: Exception) {
                            // A later resume or bridge call can retry.
                        }
                    }
                } catch (_: RuntimeException) {
                    // A later resume or bridge call can retry.
                }
            }
        }
    }

    /**
     * Called by the bridge off main. Pending acknowledgments follow a
     * verified durable reservation. Waiting is bounded.
     *
     * A storage/wait failure can leave the acknowledgment uncertain.
     * Callers can subsequently query the same request ID through GetStatus.
     */
    fun start(
        activity: FragmentActivity,
        request: NativeCalendarRequest
    ): NativeCalendarResult {
        fun rejected(code: String): NativeCalendarResult =
            NativeCalendarResult.failure(
                request.id,
                code,
                request.operation,
                request.target
            )

        if (Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL) {
            return rejected(Contract.UNSUPPORTED_ANDROID_VERSION)
        }

        if (Looper.myLooper() == Looper.getMainLooper()) {
            return rejected(Contract.ACTIVITY_UNAVAILABLE)
        }

        if (!ensure(activity)) {
            return rejected(Contract.ACTIVITY_UNAVAILABLE)
        }

        val operation = Operation(
            context = activity.applicationContext,
            activity = WeakReference(activity),
            id = request.id,
            operation = request.operation,
            target = request.target
        )

        val future = NativeCalendarWork.submitState {
            startOnWorker(operation, request)
        } ?: return rejected(Contract.PERSIST_FAILED)

        return try {
            future.get(BRIDGE_WAIT_MS, TimeUnit.MILLISECONDS)
        } catch (_: InterruptedException) {
            cancelWait(operation)
            future.cancel(false)
            Thread.currentThread().interrupt()
            rejected(Contract.PERSIST_FAILED)
        } catch (_: Exception) {
            cancelWait(operation)
            future.cancel(false)
            rejected(Contract.PERSIST_FAILED)
        }
    }

    /**
     * Metadata lookup and recovery always run off main.
     */
    fun getStatus(
        activity: FragmentActivity,
        id: String
    ): NativeCalendarResult {
        if (Contract.requestId(id) != id) {
            return NativeCalendarResult.failure(
                id,
                Contract.INVALID_REQUEST_ID
            )
        }

        if (Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL) {
            return NativeCalendarResult.failure(
                id,
                Contract.UNSUPPORTED_ANDROID_VERSION
            )
        }

        if (Looper.myLooper() == Looper.getMainLooper()) {
            return NativeCalendarResult.failure(
                id,
                Contract.ACTIVITY_UNAVAILABLE
            )
        }

        ensure(activity)
        val context = activity.applicationContext
        val reference = WeakReference(activity)

        val future = NativeCalendarWork.submitState {
            drainActive()
            val store = NativeCalendarStore(context)
            reconcile(store, reference)
            store.getStatus(id)
        } ?: return NativeCalendarResult.failure(id, Contract.PERSIST_FAILED)

        return try {
            future.get(BRIDGE_WAIT_MS, TimeUnit.MILLISECONDS)
        } catch (_: InterruptedException) {
            future.cancel(false)
            Thread.currentThread().interrupt()
            NativeCalendarResult.failure(id, Contract.PERSIST_FAILED)
        } catch (_: Exception) {
            future.cancel(false)
            NativeCalendarResult.failure(id, Contract.PERSIST_FAILED)
        }
    }

    private fun startOnWorker(
        operation: Operation,
        request: NativeCalendarRequest
    ): NativeCalendarResult {
        fun rejected(code: String): NativeCalendarResult =
            NativeCalendarResult.failure(
                operation.id,
                code,
                operation.operation,
                operation.target
            )

        try {
            drainActive()
            val store = NativeCalendarStore(operation.context)
            reconcile(store, operation.activity)

            if (operation.gate.isFinished) {
                return rejected(Contract.PERSIST_FAILED)
            }

            val intent = NativeCalendarIntents.forRequest(request)

            if (!NativeCalendarIntents.resolves(operation.context, intent)) {
                return rejected(Contract.NO_CALENDAR_APP)
            }

            if (operation.gate.isFinished) {
                return rejected(Contract.PERSIST_FAILED)
            }

            val reservation = store.begin(
                operation.id,
                operation.operation,
                operation.target
            )

            if (reservation is NativeCalendarBeginResult.Rejected) {
                return reservation.result
            }

            val pending =
                (reservation as NativeCalendarBeginResult.Started).pending

            operation.handle = pending.handle
            active = operation

            if (operation.decision.get() != null) {
                return settle(operation) ?: rejected(Contract.PERSIST_FAILED)
            }

            val deadline = NativeCalendarWork.after(LAUNCH_WAIT_MS) {
                expire(operation, Contract.LAUNCH_FAILED)
            }

            operation.deadline.set(deadline)

            if (operation.gate.isFinished) {
                operation.deadline.getAndSet(null)?.cancel(false)
                return settle(operation) ?: pending.result
            }

            if (deadline == null) {
                failBeforeLaunch(operation, Contract.PERSIST_FAILED)
                return settle(operation) ?: rejected(Contract.PERSIST_FAILED)
            }

            // The disk phase is verified before any external UI handoff.
            val launching = store.markLaunching(pending.handle)

            if (launching == null) {
                failBeforeLaunch(operation, Contract.PERSIST_FAILED)
                return settle(operation) ?: rejected(Contract.PERSIST_FAILED)
            }

            operation.handle = launching

            if (operation.gate.isFinished) {
                return settle(operation) ?: pending.result
            }

            val task = Runnable {
                performLaunch(operation, intent)
            }

            operation.launchTask.set(task)

            if (!postMain(task)) {
                failBeforeLaunch(operation, Contract.ACTIVITY_UNAVAILABLE)
            }

            // Cover completion/expiry racing with callback registration.
            if (operation.gate.isFinished) {
                removeLaunchTask(operation)
            }

            return settle(operation) ?: pending.result
        } catch (_: Exception) {
            failBeforeLaunch(operation, Contract.PERSIST_FAILED)

            if (active === operation) {
                return settle(operation) ?: rejected(Contract.PERSIST_FAILED)
            }

            // begin() may have committed before its verification failed.
            try {
                reconcile(
                    NativeCalendarStore(operation.context),
                    operation.activity
                )
            } catch (_: Exception) {
                // Recovery can be retried through resume or GetStatus.
            }

            return rejected(Contract.PERSIST_FAILED)
        }
    }

    private fun performLaunch(
        operation: Operation,
        intent: Intent
    ) {
        val host = operation.activity.get()

        if (host == null || !canLaunch(host)) {
            failBeforeLaunch(operation, Contract.ACTIVITY_UNAVAILABLE)
            return
        }

        when (operation.gate.claimLaunch()) {
            NativeCalendarLaunchGate.Claim.DECLINED -> return

            NativeCalendarLaunchGate.Claim.EXPIRED_BEFORE_LAUNCH -> {
                record(
                    operation,
                    Decision(Contract.STATUS_FAILED, Contract.LAUNCH_FAILED)
                )
                return
            }

            NativeCalendarLaunchGate.Claim.CLAIMED -> Unit
        }

        val outcome = try {
            host.startActivity(intent)
            Decision(Contract.STATUS_LAUNCHED, null)
        } catch (_: ActivityNotFoundException) {
            Decision(Contract.STATUS_FAILED, Contract.NO_CALENDAR_APP)
        } catch (_: RuntimeException) {
            Decision(Contract.STATUS_FAILED, Contract.LAUNCH_FAILED)
        }

        if (operation.gate.completeLaunch()) {
            record(operation, outcome)
        }
    }

    private fun failBeforeLaunch(
        operation: Operation,
        code: String
    ) {
        if (operation.gate.completeWithoutLaunch()) {
            record(operation, Decision(Contract.STATUS_FAILED, code))
        }
    }

    private fun cancelWait(operation: Operation) {
        expire(operation, Contract.PERSIST_FAILED)
    }

    private fun expire(
        operation: Operation,
        beforeLaunchCode: String
    ) {
        when (operation.gate.timeOut()) {
            NativeCalendarLaunchGate.Timeout.BEFORE_LAUNCH ->
                record(
                    operation,
                    Decision(Contract.STATUS_FAILED, beforeLaunchCode)
                )

            NativeCalendarLaunchGate.Timeout.DURING_LAUNCH ->
                record(
                    operation,
                    Decision(Contract.STATUS_UNKNOWN, Contract.INTERRUPTED)
                )

            NativeCalendarLaunchGate.Timeout.DECLINED -> Unit
        }
    }

    private fun record(
        operation: Operation,
        decision: Decision
    ) {
        if (!operation.decision.compareAndSet(null, decision)) {
            return
        }

        operation.deadline.getAndSet(null)?.cancel(false)
        removeLaunchTask(operation)

        // If the bounded queue is full, the decision remains in memory.
        // The next worker operation/resume drains it before new reservation.
        NativeCalendarWork.state {
            settle(operation)
        }
    }

    private fun drainActive() {
        active?.let { operation ->
            if (operation.decision.get() != null) {
                settle(operation)
            }
        }
    }

    private fun settle(operation: Operation): NativeCalendarResult? {
        if (active !== operation) {
            return null
        }

        val decision = operation.decision.get() ?: return null
        val handle = operation.handle ?: return null
        val store = NativeCalendarStore(operation.context)

        val completed = try {
            when (decision.status) {
                Contract.STATUS_LAUNCHED ->
                    store.completeLaunched(handle)

                Contract.STATUS_UNKNOWN ->
                    store.interrupt(handle)

                else ->
                    store.completeFailed(handle, decision.errorCode)
            } ?: store.getStatus(operation.id).takeIf { it.isTerminal }
        } catch (_: Exception) {
            // Release ownership before repairing an uncertain commit.
            active = null

            try {
                store.interruptUnowned(null)
                store.getStatus(operation.id).takeIf { it.isTerminal }
            } catch (_: Exception) {
                null
            }
        } finally {
            if (active === operation) {
                active = null
            }

            operation.handle = null
            operation.deadline.getAndSet(null)?.cancel(false)
            removeLaunchTask(operation)
        }

        if (completed != null) {
            notify(operation.activity, completed)
        }

        return completed
    }

    private fun reconcile(
        store: NativeCalendarStore,
        activity: WeakReference<FragmentActivity>
    ) {
        val recovered = store.interruptUnowned(active?.handle?.token)
        store.purgeExpired()

        if (recovered != null) {
            notify(activity, recovered)
        }
    }

    private fun notify(
        activity: WeakReference<FragmentActivity>,
        result: NativeCalendarResult
    ) {
        activity.get()?.let { host ->
            NativeCalendarEventDispatcher.dispatch(host, result)
        }
    }

    private fun removeLaunchTask(operation: Operation) {
        operation.launchTask.getAndSet(null)?.let { task ->
            main.removeCallbacks(task)
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
        postMain(Runnable { action() })

    private fun postMain(task: Runnable): Boolean =
        try {
            main.post(task)
        } catch (_: RuntimeException) {
            false
        }

    private class Operation(
        val context: Context,
        val activity: WeakReference<FragmentActivity>,
        val id: String,
        val operation: String,
        val target: String
    ) {
        val gate = NativeCalendarLaunchGate(LAUNCH_WAIT_MS)
        val decision = AtomicReference<Decision?>(null)
        val deadline = AtomicReference<ScheduledFuture<*>?>(null)
        val launchTask = AtomicReference<Runnable?>(null)

        // Accessed only by the serialized state worker.
        var handle: NativeCalendarPendingHandle? = null

        override fun toString(): String =
            "NativeCalendarOperation(redacted)"
    }

    private class Decision(
        val status: String,
        val errorCode: String?
    ) {
        override fun toString(): String =
            "NativeCalendarDecision(redacted)"
    }
}

package com.bbs.plugins.native_contacts

import java.util.concurrent.Future
import java.util.concurrent.atomic.AtomicReference

/**
 * A read outcome and its deadline compete for one completion.
 *
 * Only a winning deadline may interrupt the read worker. A completed read can
 * still be running its persistence action when its Future is published.
 */
internal class NativeContactsReadGate {

    private enum class Completion {
        READ,
        TIMEOUT
    }

    private val completion = AtomicReference<Completion?>(null)
    private val read = AtomicReference<Future<*>?>(null)

    fun isFinished(): Boolean = completion.get() != null

    fun completeRead(): Boolean {
        if (!completion.compareAndSet(null, Completion.READ)) {
            return false
        }

        // Release the reference without cancelling the completion action.
        read.set(null)
        return true
    }

    fun timeOut(): Boolean {
        if (!completion.compareAndSet(null, Completion.TIMEOUT)) {
            return false
        }

        read.getAndSet(null)?.cancel(true)
        return true
    }

    /**
     * Called once after submitting the read. The task may already have reached
     * either outcome before the submitting thread receives its Future.
     */
    fun bindRead(task: Future<*>) {
        read.set(task)

        when (completion.get()) {
            Completion.READ -> read.set(null)
            Completion.TIMEOUT -> read.getAndSet(null)?.cancel(true)
            null -> Unit
        }
    }
}
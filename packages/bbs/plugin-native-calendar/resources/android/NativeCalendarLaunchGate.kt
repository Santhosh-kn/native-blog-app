package com.bbs.plugins.native_calendar

import android.os.SystemClock

/**
 * Coordinates one main-thread launch attempt with its deadline.
 *
 * Expiry before the launch claim guarantees that no launch is authorized.
 * Expiry after the claim leaves the handoff outcome unknown.
 *
 * No request payload, activity, callback or disk state is retained here.
 * Callers perform launch and persistence work outside this object's lock.
 */
internal class NativeCalendarLaunchGate(
    timeoutMs: Long = 10_000L,
    private val clock: () -> Long = { SystemClock.elapsedRealtime() }
) {

    enum class Claim {
        CLAIMED,
        EXPIRED_BEFORE_LAUNCH,
        DECLINED
    }

    enum class Timeout {
        BEFORE_LAUNCH,
        DURING_LAUNCH,
        DECLINED
    }

    private enum class State {
        QUEUED,
        LAUNCHING,
        FINISHED,
        TIMED_OUT
    }

    private val lock = Any()
    private var state = State.QUEUED
    private val deadlineAtMs: Long

    init {
        require(timeoutMs in 1L..60_000L) {
            "The Calendar launch deadline is invalid."
        }

        val startedAtMs = clock()
        require(
            startedAtMs >= 0L &&
                startedAtMs <= Long.MAX_VALUE - timeoutMs
        ) {
            "The Calendar monotonic clock is invalid."
        }

        deadlineAtMs = startedAtMs + timeoutMs
    }

    override fun toString(): String =
        "NativeCalendarLaunchGate(private)"

    val isFinished: Boolean
        get() = synchronized(lock) {
            state == State.FINISHED || state == State.TIMED_OUT
        }

    /**
     * Call immediately before attempting the Android UI handoff.
     *
     * An expired claim owns the terminal decision, just like timeOut().
     * A declined claim must never launch or publish another completion.
     */
    fun claimLaunch(): Claim = synchronized(lock) {
        if (state != State.QUEUED) {
            return@synchronized Claim.DECLINED
        }

        if (clock() >= deadlineAtMs) {
            state = State.TIMED_OUT
            return@synchronized Claim.EXPIRED_BEFORE_LAUNCH
        }

        state = State.LAUNCHING
        Claim.CLAIMED
    }

    /**
     * Complete a known outcome after the launch attempt returns or throws.
     * False means another terminal decision already owns completion.
     */
    fun completeLaunch(): Boolean = synchronized(lock) {
        if (state != State.LAUNCHING) {
            return@synchronized false
        }

        state = State.FINISHED
        true
    }

    /**
     * Complete a failure discovered before claiming the launch.
     */
    fun completeWithoutLaunch(): Boolean = synchronized(lock) {
        if (state != State.QUEUED) {
            return@synchronized false
        }

        state = State.FINISHED
        true
    }

    /**
     * Only the winning caller may persist the corresponding timeout result.
     * A claimed launch cannot safely be reported as a definite failure.
     */
    fun timeOut(): Timeout = synchronized(lock) {
        when (state) {
            State.QUEUED -> {
                state = State.TIMED_OUT
                Timeout.BEFORE_LAUNCH
            }

            State.LAUNCHING -> {
                state = State.TIMED_OUT
                Timeout.DURING_LAUNCH
            }

            State.FINISHED,
            State.TIMED_OUT -> Timeout.DECLINED
        }
    }
}

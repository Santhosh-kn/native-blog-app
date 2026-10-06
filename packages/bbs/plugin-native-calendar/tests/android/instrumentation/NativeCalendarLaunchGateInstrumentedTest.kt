package com.bbs.plugins.native_calendar

import androidx.test.ext.junit.runners.AndroidJUnit4
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeCalendarLaunchGateInstrumentedTest {

    @Test
    fun queuedTimeoutPreventsAnyLaunchOrCompletion() {
        val gate = NativeCalendarLaunchGate(clock = { 100L })

        assertFalse(gate.isFinished)
        assertEquals(NativeCalendarLaunchGate.Timeout.BEFORE_LAUNCH, gate.timeOut())
        assertTrue(gate.isFinished)
        assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
        assertFalse(gate.completeLaunch())
        assertFalse(gate.completeWithoutLaunch())
        assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, gate.timeOut())
    }

    @Test
    fun launchClaimIsExclusive() {
        val gate = NativeCalendarLaunchGate(clock = { 100L })

        assertEquals(NativeCalendarLaunchGate.Claim.CLAIMED, gate.claimLaunch())
        assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
        assertFalse(gate.isFinished)
        assertFalse(gate.completeWithoutLaunch())
        assertTrue(gate.completeLaunch())
    }

    @Test
    fun completedLaunchPreventsAnotherTerminalDecision() {
        val gate = NativeCalendarLaunchGate(clock = { 100L })
        assertEquals(NativeCalendarLaunchGate.Claim.CLAIMED, gate.claimLaunch())

        assertTrue(gate.completeLaunch())
        assertTrue(gate.isFinished)
        assertFalse(gate.completeLaunch())
        assertFalse(gate.completeWithoutLaunch())
        assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, gate.timeOut())
    }

    @Test
    fun failureBeforeLaunchPreventsHandoff() {
        val gate = NativeCalendarLaunchGate(clock = { 100L })

        assertTrue(gate.completeWithoutLaunch())
        assertFalse(gate.completeWithoutLaunch())
        assertFalse(gate.completeLaunch())
        assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
        assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, gate.timeOut())
    }

    @Test
    fun timeoutAfterClaimLeavesOutcomeUnknownAndRejectsLateCompletion() {
        val gate = NativeCalendarLaunchGate(clock = { 100L })
        assertEquals(NativeCalendarLaunchGate.Claim.CLAIMED, gate.claimLaunch())

        assertEquals(NativeCalendarLaunchGate.Timeout.DURING_LAUNCH, gate.timeOut())
        assertTrue(gate.isFinished)
        assertFalse(gate.completeLaunch())
        assertFalse(gate.completeWithoutLaunch())
        assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, gate.timeOut())
    }

    @Test
    fun claimJustBeforeDeadlineIsAllowed() {
        val clock = AtomicLong(100L)
        val gate = NativeCalendarLaunchGate(20L, clock::get)
        clock.set(119L)

        assertEquals(NativeCalendarLaunchGate.Claim.CLAIMED, gate.claimLaunch())
        assertTrue(gate.completeLaunch())
    }

    @Test
    fun claimAtExactDeadlineExpiresWithoutLaunching() {
        val clock = AtomicLong(100L)
        val gate = NativeCalendarLaunchGate(20L, clock::get)
        clock.set(120L)

        assertEquals(
            NativeCalendarLaunchGate.Claim.EXPIRED_BEFORE_LAUNCH,
            gate.claimLaunch()
        )
        assertTrue(gate.isFinished)
        assertFalse(gate.completeLaunch())
        assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, gate.timeOut())
    }

    @Test
    fun delayedTimerCannotPermitLaunchPastDeadline() {
        val clock = AtomicLong(100L)
        val gate = NativeCalendarLaunchGate(20L, clock::get)
        clock.set(500L)

        // No timer callback has run: claim checks the clock itself.
        assertEquals(
            NativeCalendarLaunchGate.Claim.EXPIRED_BEFORE_LAUNCH,
            gate.claimLaunch()
        )
        assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
    }

    @Test
    fun expiredGateCannotBeReopenedByClockChanges() {
        val clock = AtomicLong(100L)
        val gate = NativeCalendarLaunchGate(20L, clock::get)

        assertEquals(NativeCalendarLaunchGate.Timeout.BEFORE_LAUNCH, gate.timeOut())
        clock.set(0L)

        assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
        assertFalse(gate.completeLaunch())
    }

    @Test
    fun invalidTimeoutBoundsAreRejected() {
        for (timeout in listOf(-1L, 0L, 60_001L, Long.MAX_VALUE)) {
            invalid { NativeCalendarLaunchGate(timeout, clock = { 100L }) }
        }

        assertFalse(NativeCalendarLaunchGate(1L, clock = { 0L }).isFinished)
        assertFalse(NativeCalendarLaunchGate(60_000L, clock = { 0L }).isFinished)
    }

    @Test
    fun invalidInitialClockAndDeadlineOverflowAreRejected() {
        invalid { NativeCalendarLaunchGate(10L, clock = { -1L }) }
        invalid { NativeCalendarLaunchGate(10L, clock = { Long.MAX_VALUE }) }

        assertFalse(
            NativeCalendarLaunchGate(
                10L,
                clock = { Long.MAX_VALUE - 10L }
            ).isFinished
        )
    }

    @Test
    fun completionAndTimeoutRaceHasExactlyOneWinner() {
        val executor = Executors.newFixedThreadPool(2)

        try {
            repeat(100) {
                val gate = NativeCalendarLaunchGate(clock = { 100L })
                assertEquals(
                    NativeCalendarLaunchGate.Claim.CLAIMED,
                    gate.claimLaunch()
                )
                val start = CountDownLatch(1)

                val completion = executor.submit<Boolean> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.completeLaunch()
                }
                val timeout = executor.submit<NativeCalendarLaunchGate.Timeout> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.timeOut()
                }

                start.countDown()
                val completed = completion.get(5, TimeUnit.SECONDS)
                val expired = timeout.get(5, TimeUnit.SECONDS)

                if (completed) {
                    assertEquals(NativeCalendarLaunchGate.Timeout.DECLINED, expired)
                } else {
                    assertEquals(
                        NativeCalendarLaunchGate.Timeout.DURING_LAUNCH,
                        expired
                    )
                }

                assertTrue(gate.isFinished)
                assertFalse(gate.completeLaunch())
                assertEquals(
                    NativeCalendarLaunchGate.Timeout.DECLINED,
                    gate.timeOut()
                )
            }
        } finally {
            executor.shutdownNow()
        }
    }

    @Test
    fun claimAndTimeoutRaceCannotAuthorizeASecondLaunch() {
        val executor = Executors.newFixedThreadPool(2)

        try {
            repeat(100) {
                val gate = NativeCalendarLaunchGate(clock = { 100L })
                val start = CountDownLatch(1)

                val claim = executor.submit<NativeCalendarLaunchGate.Claim> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.claimLaunch()
                }
                val timeout = executor.submit<NativeCalendarLaunchGate.Timeout> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.timeOut()
                }

                start.countDown()
                val claimed = claim.get(5, TimeUnit.SECONDS)
                val expired = timeout.get(5, TimeUnit.SECONDS)

                if (claimed == NativeCalendarLaunchGate.Claim.CLAIMED) {
                    assertEquals(
                        NativeCalendarLaunchGate.Timeout.DURING_LAUNCH,
                        expired
                    )
                } else {
                    assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, claimed)
                    assertEquals(
                        NativeCalendarLaunchGate.Timeout.BEFORE_LAUNCH,
                        expired
                    )
                }

                assertTrue(gate.isFinished)
                assertFalse(gate.completeLaunch())
                assertEquals(NativeCalendarLaunchGate.Claim.DECLINED, gate.claimLaunch())
            }
        } finally {
            executor.shutdownNow()
        }
    }

    private fun invalid(action: () -> Any?) {
        try {
            action()
        } catch (failure: IllegalArgumentException) {
            assertNull(failure.cause)
            return
        }

        throw AssertionError("Expected invalid Calendar deadline configuration.")
    }
}

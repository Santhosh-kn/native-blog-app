package com.bbs.plugins.native_media_optimizer

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerStorageInstrumentedTest {
    private fun directory(): File = File(InstrumentationRegistry.getInstrumentation().targetContext.cacheDir, "media-state-test-${UUID.randomUUID()}")

    @Test fun atomicCommitCanBeReopenedAndReadExactly() {
        val directory = directory()
        try {
            val state = NativeMediaOptimizerAtomicStateFile(directory)
            val bytes = "{\"version\":1,\"records\":[]}".toByteArray()
            state.locked { assertNull(state.read()); state.write(bytes); assertArrayEquals(bytes, state.read()) }
            val reopened = NativeMediaOptimizerAtomicStateFile(directory)
            reopened.locked { assertArrayEquals(bytes, reopened.read()) }
            assertFalse(File(directory, "state.json.new").exists())
            assertFalse(File(directory, "state.json.bak").exists())
        } finally { directory.deleteRecursively() }
    }

    @Test fun readsAndWritesRequireALock() {
        val directory = directory()
        try {
            val state = NativeMediaOptimizerAtomicStateFile(directory)
            try { state.read(); fail("Read requires a transaction.") } catch (_: NativeMediaOptimizerStorageException) { }
            try { state.write(byteArrayOf(1)); fail("Write requires a transaction.") } catch (_: NativeMediaOptimizerStorageException) { }
        } finally { directory.deleteRecursively() }
    }

    @Test fun oversizeStateCannotReplaceACommittedFile() {
        val directory = directory()
        try {
            val state = NativeMediaOptimizerAtomicStateFile(directory)
            val bytes = "{}".toByteArray()
            state.locked { state.write(bytes) }
            try {
                state.locked { state.write(ByteArray(NativeMediaOptimizerContract.MAX_STATE_BYTES + 1)) }
                fail("Oversize state must be rejected.")
            } catch (_: NativeMediaOptimizerStorageException) { }
            state.locked { assertArrayEquals(bytes, state.read()) }
        } finally { directory.deleteRecursively() }
    }
}

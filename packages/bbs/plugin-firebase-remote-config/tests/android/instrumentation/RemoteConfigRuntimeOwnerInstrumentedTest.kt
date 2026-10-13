package com.bbs.plugins.firebase_remote_config

import android.content.Context
import android.content.ContextWrapper
import java.io.File
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

class RemoteConfigRuntimeOwnerInstrumentedTest {
    private val context get() = InstrumentationRegistry.getInstrumentation().targetContext
    private fun failure(code: String, action: () -> Unit) {
        try { action(); fail() } catch (error: RemoteConfigCoordinatorException) { assertEquals(code, error.code) }
    }
    @Test fun oneOwnerCreatesAndInitializesExactlyOneCoordinator() {
        val f = RemoteConfigCoordinatorFixture()
        var creations = 0
        val owner = RemoteConfigRuntimeOwner { creations++; RemoteConfigRuntimeBundle(f.coordinator) { } }
        assertSame(f.coordinator, owner.get(context))
        assertSame(f.coordinator, owner.get(context))
        f.scheduler.runWorker()
        assertEquals(1, creations)
        assertEquals(1, f.sdk.initializes)
        assertEquals(0, f.sdk.fetches)
        assertEquals(0, f.sdk.activations)
    }
    @Test fun factoryReceivesOnlyApplicationContext() {
        val f = RemoteConfigCoordinatorFixture()
        var received: Context? = null
        val wrapped = object : ContextWrapper(context) { override fun getApplicationContext(): Context = context.applicationContext }
        val owner = RemoteConfigRuntimeOwner { app -> received = app; RemoteConfigRuntimeBundle(f.coordinator) { } }
        owner.get(wrapped)
        assertSame(context.applicationContext, received)
        assertNotSame(wrapped, received)
    }
    @Test fun concurrentCallersShareOneProcessOwner() {
        val f = RemoteConfigCoordinatorFixture()
        var creations = 0
        val owner = RemoteConfigRuntimeOwner { creations++; RemoteConfigRuntimeBundle(f.coordinator) { } }
        val executor = Executors.newFixedThreadPool(4)
        try {
            val futures = List(8) { executor.submit<RemoteConfigCoordinator> { owner.get(context) } }
            for (future in futures) assertSame(f.coordinator, future.get(2, TimeUnit.SECONDS))
            assertEquals(1, creations)
        } finally { executor.shutdownNow() }
    }
    @Test fun corruptStorageFailsStickyInsteadOfReplacingHistoryOrRetryingCreation() {
        var creations = 0
        val owner = RemoteConfigRuntimeOwner { creations++; throw RemoteConfigStorageException() }
        failure(Contract.PERSIST_FAILED) { owner.get(context) }
        failure(Contract.PERSIST_FAILED) { owner.get(context) }
        assertEquals(1, creations)
    }
    @Test fun factoryMemoryAndLinkageFailuresAreStickyControlledCodes() {
        for ((error, code) in listOf(OutOfMemoryError("private details") to Contract.LIMIT_EXCEEDED,
            LinkageError("private details") to Contract.NATIVE_UNAVAILABLE)) {
            var creations = 0
            val owner = RemoteConfigRuntimeOwner { creations++; throw error }
            failure(code) { owner.get(context) }
            failure(code) { owner.get(context) }
            assertEquals(1, creations)
        }
    }
    @Test fun failedInitializationDisposesCreatedResourcesBeforeCachingTheFailure() {
        val f = RemoteConfigCoordinatorFixture()
        f.ready(); f.defaults()
        f.storage.failNext = true
        try { f.start() } catch (_: RemoteConfigCoordinatorException) { }
        var disposals = 0
        val owner = RemoteConfigRuntimeOwner { RemoteConfigRuntimeBundle(f.coordinator) { disposals++ } }
        failure(Contract.PERSIST_FAILED) { owner.get(context) }
        failure(Contract.PERSIST_FAILED) { owner.get(context) }
        assertEquals(1, disposals)
    }
    @Test fun mainThreadOwnerAccessStopsBeforeReadingContextOrCreatingResources() {
        var lookups = 0
        var creations = 0
        var rejected = false
        val wrapped = object : ContextWrapper(context) {
            override fun getApplicationContext(): Context { lookups++; return context.applicationContext }
        }
        val owner = RemoteConfigRuntimeOwner { creations++; throw AssertionError() }
        RemoteConfigBridgeTestFixture.onMain {
            try { owner.get(wrapped) } catch (_: IllegalStateException) { rejected = true }
        }
        assertTrue(rejected)
        assertEquals(0, lookups)
        assertEquals(0, creations)
    }
    @Test fun ownedStorageUsesThePrivateNoBackupDirectory() {
        assertEquals(File(context.noBackupFilesDir, "firebase-remote-config").canonicalPath,
            RemoteConfigRuntime.storageDirectory(context).canonicalPath)
    }
}

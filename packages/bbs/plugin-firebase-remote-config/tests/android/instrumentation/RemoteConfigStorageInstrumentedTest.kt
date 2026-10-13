package com.bbs.plugins.firebase_remote_config

import android.util.AtomicFile
import java.io.File
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

class RemoteConfigStorageInstrumentedTest {
    private fun inDirectory(block: (File) -> Unit) {
        val directory = Fixture.directory()
        try { block(directory) } finally { directory.deleteRecursively() }
    }
    private fun persistenceFailure(block: () -> Unit) {
        try { block(); fail("Persistence failure was hidden") } catch (_: RemoteConfigStorageException) { }
    }

    @Test fun absentHistoryDoesNotCreateFiles() = inDirectory { directory ->
        val child = File(directory, "new")
        assertTrue(RemoteConfigStorage(child).load().isEmpty())
        assertFalse(child.exists())
    }
    @Test fun atomicRoundTripSurvivesANewStorageInstance() = inDirectory { directory ->
        val records = listOf(Fixture.record(Fixture.pending().running(1000).succeeded(2000)))
        RemoteConfigStorage(directory).save(records)
        assertEquals(records, RemoteConfigStorage(directory).load())
        assertTrue(File(directory, "operations-v1.json").isFile)
        assertFalse(File(directory, "operations-v1.json.new").exists())
    }
    @Test fun failedAtomicWritePreservesPreviousHistory() = inDirectory { directory ->
        val storage = RemoteConfigStorage(directory)
        val records = listOf(Fixture.record())
        storage.save(records)
        val atomic = AtomicFile(File(directory, "operations-v1.json"))
        val stream = atomic.startWrite()
        stream.write("partial replacement".toByteArray())
        atomic.failWrite(stream)
        assertEquals(records, storage.load())
    }
    @Test fun incompleteNewFileDoesNotReplaceCommittedHistory() = inDirectory { directory ->
        val records = listOf(Fixture.record())
        val storage = RemoteConfigStorage(directory)
        storage.save(records)
        File(directory, "operations-v1.json.new").writeText("partial crash write")
        assertEquals(records, storage.load())
    }
    @Test fun legacyAtomicBackupRecoversTheLastCommittedHistory() = inDirectory { directory ->
        val records = listOf(Fixture.record())
        val bytes = RemoteConfigRecordCodec.encode(records)
        File(directory, "operations-v1.json").writeText("broken replacement")
        File(directory, "operations-v1.json.bak").writeBytes(bytes)
        assertEquals(records, RemoteConfigStorage(directory).load())
    }
    @Test fun corruptHistoryIsReportedAndPreserved() = inDirectory { directory ->
        val base = File(directory, "operations-v1.json")
        base.writeText("{broken}")
        persistenceFailure { RemoteConfigStorage(directory).load() }
        assertEquals("{broken}", base.readText())
    }
    @Test fun oversizedHistoryIsRejected() = inDirectory { directory ->
        File(directory, "operations-v1.json").writeBytes(ByteArray(RemoteConfigRecordCodec.MAX_STORAGE_BYTES + 1))
        persistenceFailure { RemoteConfigStorage(directory).load() }
    }
    @Test fun impossibleStoragePathIsReportedWithoutReplacingTheFile() = inDirectory { directory ->
        val path = File(directory, "file-instead-of-directory").also { it.writeText("keep") }
        persistenceFailure { RemoteConfigStorage(path).save(listOf(Fixture.record())) }
        persistenceFailure { RemoteConfigStorage(path).load() }
        assertEquals("keep", path.readText())
    }
    @Test fun invalidCandidateCannotOverwriteExistingHistory() = inDirectory { directory ->
        val storage = RemoteConfigStorage(directory)
        val records = listOf(Fixture.record())
        storage.save(records)
        persistenceFailure { storage.save(listOf(Fixture.record(Fixture.pending().copy(success = true)))) }
        assertEquals(records, storage.load())
    }
    @Test fun persistenceContainsOnlyDigestAndStatusNotDefaultValues() = inDirectory { directory ->
        val request = RemoteConfigRequest("SetDefaults", Fixture.id(), defaults =
            mapOf("welcome_message" to RemoteConfigDefault("string", "private-test-marker-ಕನ್ನಡ")))
        RemoteConfigStorage(directory).save(listOf(RemoteConfigStoredRecord(RemoteConfigFingerprint.of(request),
            RemoteConfigOperationResult.pending(request))))
        val text = File(directory, "operations-v1.json").readText()
        assertFalse(text.contains("private-test-marker"))
        assertFalse(text.contains("welcome_message"))
        assertFalse(text.contains("ಕನ್ನಡ"))
    }
    @Test fun mainThreadCallsAreRejectedBeforeDiskAccess() = inDirectory { directory ->
        val child = File(directory, "ui-thread")
        val storage = RemoteConfigStorage(child)
        var loadRejected = false
        var saveRejected = false
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            try { storage.load() } catch (_: IllegalStateException) { loadRejected = true }
            try { storage.save(emptyList()) } catch (_: IllegalStateException) { saveRejected = true }
        }
        assertTrue(loadRejected)
        assertTrue(saveRejected)
        assertFalse(child.exists())
    }
}

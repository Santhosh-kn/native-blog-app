package com.bbs.plugins.firebase_remote_config

import java.io.File
import java.util.UUID
import androidx.test.platform.app.InstrumentationRegistry

internal object RemoteConfigStorageTestFixture {
    fun id(number: Int = 1): String = "123e4567-e89b-42d3-a456-" + number.toString().padStart(12, '0')
    fun request(operation: String = "Fetch", number: Int = 1) = RemoteConfigRequest(operation, id(number))
    fun pending(operation: String = "Fetch", number: Int = 1) = RemoteConfigOperationResult.pending(request(operation, number))
    fun record(result: RemoteConfigOperationResult = pending()) = RemoteConfigStoredRecord("a".repeat(64), result)
    fun directory(): File {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        return File(context.cacheDir, "remote-config-store-test-" + UUID.randomUUID()).also { check(it.mkdirs()) }
    }
}

internal class RemoteConfigMemoryStorage : RemoteConfigRecordStorage {
    var durable = emptyList<RemoteConfigStoredRecord>()
    var writes = 0
    var failNext = false
    var commitThenFail = false

    override fun load() = durable.toList()
    override fun save(records: List<RemoteConfigStoredRecord>) {
        writes++
        if (failNext) { failNext = false; throw RemoteConfigStorageException() }
        durable = records.toList()
        if (commitThenFail) { commitThenFail = false; throw RemoteConfigStorageException() }
    }
}

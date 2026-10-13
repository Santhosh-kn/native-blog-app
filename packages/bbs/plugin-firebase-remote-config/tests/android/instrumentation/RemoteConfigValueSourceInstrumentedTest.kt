package com.bbs.plugins.firebase_remote_config

import com.google.firebase.remoteconfig.FirebaseRemoteConfig as FirebaseSdk
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.Parameterized

@RunWith(Parameterized::class)
class RemoteConfigValueSourceInstrumentedTest(
    private val type: String, private val source: Int, private val value: Any, private val expectedSource: String
) {
    @Test fun typedValueRetainsItsSdkSource() {
        val sdk = RemoteConfigValueTestSdk()
        sdk.values["example"] = RemoteConfigValueTestRaw(source, value)
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to type), sdk, true, true))
        assertTrue(json.getBoolean("success"))
        val entry = json.getJSONObject("values").getJSONObject("example")
        assertEquals(type, entry.getString("type"))
        assertEquals(expectedSource, entry.getString("source"))
        when (type) {
            "string" -> assertEquals(value, entry.getString("value"))
            "boolean" -> assertEquals(value, entry.getBoolean("value"))
            "integer" -> assertEquals(value, entry.getLong("value"))
            "decimal" -> assertEquals(value as Double, entry.getDouble("value"), 0.0)
        }
        assertEquals(listOf("example"), sdk.calls)
    }

    companion object {
        @JvmStatic @Parameterized.Parameters(name = "{0} / {3}")
        fun cases(): Collection<Array<Any>> {
            val rows = mutableListOf<Array<Any>>()
            for ((source, label) in listOf(FirebaseSdk.VALUE_SOURCE_REMOTE to "remote",
                FirebaseSdk.VALUE_SOURCE_DEFAULT to "default", FirebaseSdk.VALUE_SOURCE_STATIC to "static")) {
                for ((type, value) in listOf("string" to "", "boolean" to false, "integer" to 0L, "decimal" to 0.0)) {
                    rows += arrayOf(type, source, value, label)
                }
            }
            return rows
        }
    }
}

package com.bbs.plugins.firebase_remote_config

import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.Parameterized

/** Runs the same fixture file as RemoteConfigSharedVectorsTest.php. */
@RunWith(Parameterized::class)
class RemoteConfigContractVectorInstrumentedTest(
    private val caseName: String,
    private val operation: String,
    private val objectInput: JSONObject,
    private val expectedCode: String?
) {
    @Test fun requestMatchesTheSharedContract() {
        // Match BridgeRouter: only the top-level JSONObject becomes a Map.
        val parameters = linkedMapOf<String, Any>()
        val keys = objectInput.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            parameters[key] = objectInput.get(key)
        }
        val result = RemoteConfigRequest.parse(operation, parameters)
        val actualCode = (result as? RemoteConfigRequestParseResult.Invalid)?.code
        assertEquals(caseName, expectedCode, actualCode)
    }

    companion object {
        @JvmStatic @Parameterized.Parameters(name = "{index}: {0}")
        fun cases(): Collection<Array<Any?>> {
            val text = InstrumentationRegistry.getInstrumentation().context.assets
                .open("remote-config-contract-vectors.json").bufferedReader(Charsets.UTF_8).use { it.readText() }
            val vectors = JSONArray(text)
            return List(vectors.length()) { index ->
                val vector = vectors.getJSONObject(index)
                arrayOf(vector.getString("name"), vector.getString("operation"),
                    vector.getJSONObject("parameters"),
                    if (vector.isNull("expectedCode")) null else vector.getString("expectedCode"))
            }
        }
    }
}

package com.bbs.plugins.firebase_remote_config

import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.Parameterized

/** Shared failure vectors also exercised by RemoteConfigControlFailureTest.php. */
@RunWith(Parameterized::class)
class RemoteConfigBridgeControlVectorInstrumentedTest(
    private val name: String,
    private val method: String,
    private val input: JSONObject,
    private val expected: JSONObject
) {
    @Test fun nativeControlFailureMatchesTheSharedPhpContract() {
        val dispatcher = RemoteConfigBridgeDispatcher {
            throw RemoteConfigCoordinatorException(expected.getString("errorCode"))
        }
        val parameters = linkedMapOf<String, Any>()
        val keys = input.keys()
        while (keys.hasNext()) { val key = keys.next(); parameters[key] = input.get(key) }
        val reply = dispatcher.execute(method, parameters)
        assertEquals(name, RemoteConfigContract.objectMap(expected), RemoteConfigContract.objectMap(reply))
        assertTrue(RemoteConfigContract.boundedResponse(reply))
        assertTrue(JSONObject(reply).has("operation"))
        assertEquals(method == "GetStatus", JSONObject(reply).isNull("operation"))
    }
    companion object {
        @JvmStatic @Parameterized.Parameters(name = "{index}: {0}")
        fun cases(): Collection<Array<Any>> {
            val text = InstrumentationRegistry.getInstrumentation().context.assets
                .open("remote-config-control-failures.json").bufferedReader(Charsets.UTF_8).use { it.readText() }
            val cases = JSONArray(text)
            return List(cases.length()) { index ->
                val item = cases.getJSONObject(index)
                arrayOf(item.getString("name"), item.getString("method"), item.getJSONObject("parameters"), item.getJSONObject("response"))
            }
        }
    }
}

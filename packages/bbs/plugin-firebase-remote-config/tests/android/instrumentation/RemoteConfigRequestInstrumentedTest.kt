package com.bbs.plugins.firebase_remote_config

import androidx.test.ext.junit.runners.AndroidJUnit4
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

@RunWith(AndroidJUnit4::class)
class RemoteConfigRequestInstrumentedTest {
    private val id = "123e4567-e89b-42d3-a456-426614174000"

    @Test fun realRouterNestedObjectsAreDecodedWithoutLosingTypes() {
        val request = JSONObject("""{"id":"$id","defaults":{
            "text":{"type":"string","value":"Welcome"},
            "flag":{"type":"boolean","value":false},
            "count":{"type":"integer","value":80},
            "scale":{"type":"decimal","value":1.0}
        }}""")
        val parsed = RemoteConfigRequest.parse("SetDefaults", shallowMap(request)) as RemoteConfigRequestParseResult.Valid
        assertEquals("Welcome", parsed.request.defaults["text"]?.value)
        assertEquals(false, parsed.request.defaults["flag"]?.value)
        assertEquals(80L, parsed.request.defaults["count"]?.value)
        assertEquals(1.0, parsed.request.defaults["scale"]?.value)
    }

    @Test fun jsonWholeTokenAndDecimalTokenRemainDistinct() {
        val whole = JSONObject("{\"value\":80}").get("value")
        val decimal = JSONObject("{\"value\":80.0}").get("value")
        assertEquals(80L, Contract.integer(whole))
        assertNull(Contract.integer(decimal))
        assertEquals(80.0, Contract.decimal(decimal))
    }

    @Test fun jsonNullCannotBecomeAStringDefault() {
        val parsed = RemoteConfigRequest.parse("SetDefaults", mapOf("id" to id,
            "defaults" to JSONObject("{\"text\":{\"type\":\"string\",\"value\":null}}")))
        assertEquals(Contract.INVALID_VALUE, (parsed as RemoteConfigRequestParseResult.Invalid).code)
    }

    @Test fun defaultMapsAreCopiedBeforeTheCallerMutatesThem() {
        val entry = mutableMapOf<String, Any>("type" to "integer", "value" to 80)
        val defaults = mutableMapOf<String, Any>("quality" to entry)
        val parsed = RemoteConfigRequest.parse("SetDefaults", mapOf("id" to id, "defaults" to defaults)) as RemoteConfigRequestParseResult.Valid
        entry["value"] = 1
        defaults.clear()
        assertEquals(80L, parsed.request.defaults["quality"]?.value)
    }

    @Test fun valueMapsAreCopiedBeforeTheCallerMutatesThem() {
        val values = mutableMapOf<String, Any>("quality" to "integer")
        val parsed = RemoteConfigRequest.parse("GetValues", mapOf("values" to values)) as RemoteConfigRequestParseResult.Valid
        values.clear()
        assertEquals("integer", parsed.request.values["quality"])
    }

    @Test fun unpairedHighSurrogateIsRejected() {
        assertFalse(Contract.validUnicode("\uD800"))
        assertEquals(Contract.INVALID_OPTIONS, Contract.sizeError(mapOf("text" to "\uD800")))
    }

    @Test fun unpairedLowSurrogateIsRejected() {
        assertFalse(Contract.validUnicode("\uDC00"))
        assertNull(Contract.typedValue("string", "\uDC00"))
    }

    @Test fun validEmojiCountsUtf8Bytes() {
        val emoji = "\uD83D\uDE00"
        assertEquals(4, Contract.utf8Size(emoji))
        assertNotNull(Contract.typedValue("string", emoji.repeat(256)))
        assertNull(Contract.typedValue("string", emoji.repeat(257)))
    }

    @Test fun nonFiniteNumbersAreRejectedBeforeExecution() {
        assertNull(Contract.decimal(Double.NaN))
        assertNull(Contract.decimal(Double.POSITIVE_INFINITY))
        assertNull(Contract.decimal(Double.NEGATIVE_INFINITY))
        assertEquals(Contract.INVALID_OPTIONS, Contract.sizeError(mapOf("number" to Double.NaN)))
    }

    @Test fun deeplyNestedInputIsRejectedWithoutRecursingIndefinitely() {
        var value: Any = "leaf"
        repeat(Contract.JSON_DEPTH + 1) { value = mapOf("child" to value) }
        assertEquals(Contract.INVALID_OPTIONS, Contract.sizeError(mapOf("nested" to value)))
    }

    @Test fun cyclicInputIsRejectedWithoutRecursingIndefinitely() {
        val cycle = mutableMapOf<String, Any>()
        cycle["child"] = cycle
        assertEquals(Contract.INVALID_OPTIONS, Contract.sizeError(cycle))
    }

    @Test fun arraysCannotBecomeDefaultObjects() {
        val parsed = RemoteConfigRequest.parse("SetDefaults", mapOf("id" to id, "defaults" to JSONArray("[1,2]")))
        assertEquals(Contract.INVALID_OPTIONS, (parsed as RemoteConfigRequestParseResult.Invalid).code)
    }

    @Test fun unsupportedObjectsAreControlled() {
        assertEquals(Contract.INVALID_OPTIONS, Contract.sizeError(mapOf("value" to Any())))
    }

    @Test fun unsafeIntegersAreRejectedAtBothEndsOfTheRange() {
        assertEquals(Contract.MAX_SAFE_INTEGER, Contract.integer(Contract.MAX_SAFE_INTEGER))
        assertEquals(-Contract.MAX_SAFE_INTEGER, Contract.integer(-Contract.MAX_SAFE_INTEGER))
        assertNull(Contract.integer(Contract.MAX_SAFE_INTEGER + 1))
        assertNull(Contract.integer(-Contract.MAX_SAFE_INTEGER - 1))
    }

    @Test fun identifiersRequireLowercaseVersionAndVariant() {
        assertEquals(id, Contract.requestId(id))
        assertNull(Contract.requestId(id.uppercase()))
        assertNull(Contract.requestId("00000000-0000-0000-0000-000000000000"))
        assertNull(Contract.requestId("123e4567-e89b-42d3-7456-426614174000"))
        assertNull(Contract.requestId(id + "\n"))
    }

    private fun shallowMap(value: JSONObject): Map<String, Any> {
        val result = linkedMapOf<String, Any>()
        val keys = value.keys()
        while (keys.hasNext()) { val key = keys.next(); result[key] = value.get(key) }
        return result
    }
}

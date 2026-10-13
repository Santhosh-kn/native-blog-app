package com.bbs.plugins.firebase_remote_config

import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

class RemoteConfigFingerprintInstrumentedTest {
    private fun defaults(entries: Map<String, RemoteConfigDefault>, number: Int = 1) =
        RemoteConfigRequest("SetDefaults", Fixture.id(number), defaults = entries)
    private fun one(type: String, value: Any) = defaults(mapOf("value" to RemoteConfigDefault(type, value)))

    @Test fun digestHasTheBoundedLowercaseSha256Shape() {
        assertTrue(Regex("[0-9a-f]{64}").matches(RemoteConfigFingerprint.of(Fixture.request())))
    }
    @Test fun parameterOrderDoesNotChangeIdentity() {
        val a = RemoteConfigDefault("integer", 80)
        val b = RemoteConfigDefault("boolean", false)
        assertEquals(RemoteConfigFingerprint.of(defaults(linkedMapOf("quality" to a, "show" to b))),
            RemoteConfigFingerprint.of(defaults(linkedMapOf("show" to b, "quality" to a))))
    }
    @Test fun integerJvmRepresentationsNormalizeToTheSameIdentity() {
        assertEquals(RemoteConfigFingerprint.of(one("integer", 80)), RemoteConfigFingerprint.of(one("integer", 80L)))
    }
    @Test fun decimalIntegerInputNormalizesToTheSameDoubleIdentity() {
        assertEquals(RemoteConfigFingerprint.of(one("decimal", 80)), RemoteConfigFingerprint.of(one("decimal", 80.0)))
    }
    @Test fun integerAndDecimalTypesHaveDifferentIdentities() {
        assertNotEquals(RemoteConfigFingerprint.of(one("integer", 80)), RemoteConfigFingerprint.of(one("decimal", 80.0)))
    }
    @Test fun operationAndIdArePartOfIdentity() {
        val original = RemoteConfigFingerprint.of(Fixture.request())
        assertNotEquals(original, RemoteConfigFingerprint.of(Fixture.request("Activate")))
        assertNotEquals(original, RemoteConfigFingerprint.of(Fixture.request(number = 2)))
    }
    @Test fun settingsChangesArePartOfIdentity() {
        val request = RemoteConfigRequest("Configure", Fixture.id(), RemoteConfigSettings(43200, 60))
        assertNotEquals(RemoteConfigFingerprint.of(request),
            RemoteConfigFingerprint.of(request.copy(settings = RemoteConfigSettings(0, 60))))
        assertNotEquals(RemoteConfigFingerprint.of(request),
            RemoteConfigFingerprint.of(request.copy(settings = RemoteConfigSettings(43200, 30))))
    }
    @Test fun falseEmptyAndUnicodeValuesRemainDistinct() {
        assertNotEquals(RemoteConfigFingerprint.of(one("boolean", false)), RemoteConfigFingerprint.of(one("boolean", true)))
        assertNotEquals(RemoteConfigFingerprint.of(one("string", "")), RemoteConfigFingerprint.of(one("string", "ಕನ್ನಡ")))
        assertNotEquals(RemoteConfigFingerprint.of(one("string", "ab")), RemoteConfigFingerprint.of(one("string", "a\u0000b")))
    }
    @Test(expected = IllegalArgumentException::class)
    fun decimalTokensCannotBecomeIntegerDefaults() { RemoteConfigFingerprint.of(one("integer", 80.0)) }
    @Test(expected = IllegalArgumentException::class)
    fun unusedPayloadIsNotSilentlyIgnored() {
        RemoteConfigFingerprint.of(Fixture.request().copy(defaults = mapOf("show" to RemoteConfigDefault("boolean", true))))
    }
    @Test(expected = IllegalArgumentException::class)
    fun oversizedDefaultsNeverReachHashing() { RemoteConfigFingerprint.of(one("string", "x".repeat(1025))) }
    @Test(expected = IllegalArgumentException::class)
    fun queriesAreNotMutationIdentities() { RemoteConfigFingerprint.of(RemoteConfigRequest("GetInfo")) }
}

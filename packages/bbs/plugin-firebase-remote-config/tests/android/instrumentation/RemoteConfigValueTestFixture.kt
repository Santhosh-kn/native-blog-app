package com.bbs.plugins.firebase_remote_config

/** Read-only doubles: any unexpected fetch/default/activation call fails the test. */
internal class RemoteConfigValueTestSdk : RemoteConfigSdk {
    val values = linkedMapOf<String, RemoteConfigRawValue>()
    val calls = mutableListOf<String>()
    var exception: Throwable? = null

    override fun value(key: String): RemoteConfigRawValue {
        exception?.let { throw it }
        calls += key
        return requireNotNull(values[key])
    }
    override fun info() = RemoteConfigSdkInfo("no_fetch_yet", null, 43200, 60)
    override fun initialize(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) = unexpected()
    override fun configure(settings: RemoteConfigSettings, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) = unexpected()
    override fun setDefaults(defaults: Map<String, RemoteConfigDefault>, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) = unexpected()
    override fun fetch(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) = unexpected()
    override fun activate(callback: (RemoteConfigSdkOutcome<Boolean>) -> Unit) = unexpected()
    private fun unexpected(): Nothing = throw AssertionError("Value reads must not mutate or fetch configuration.")
}

internal class RemoteConfigValueTestRaw(override val source: Int, private val value: Any) : RemoteConfigRawValue {
    var conversionFailure = false
    override fun convert(type: String): Any {
        if (conversionFailure) throw IllegalArgumentException("private parameter detail")
        return value
    }
}

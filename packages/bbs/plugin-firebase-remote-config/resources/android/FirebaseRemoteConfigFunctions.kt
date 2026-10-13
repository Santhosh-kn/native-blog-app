package com.bbs.plugins.firebase_remote_config

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

/** Reflection targets match nativephp.json. Instances retain application context only. */
object FirebaseRemoteConfigFunctions {
    class IsAvailable(activity: FragmentActivity) : Function(activity, "IsAvailable")
    class Configure(activity: FragmentActivity) : Function(activity, "Configure")
    class SetDefaults(activity: FragmentActivity) : Function(activity, "SetDefaults")
    class GetValues(activity: FragmentActivity) : Function(activity, "GetValues")
    class Fetch(activity: FragmentActivity) : Function(activity, "Fetch")
    class Activate(activity: FragmentActivity) : Function(activity, "Activate")
    class FetchAndActivate(activity: FragmentActivity) : Function(activity, "FetchAndActivate")
    class GetStatus(activity: FragmentActivity) : Function(activity, "GetStatus")
    class GetInfo(activity: FragmentActivity) : Function(activity, "GetInfo")

    abstract class Function(activity: FragmentActivity, private val operation: String) : BridgeFunction {
        private val context: Context? = activity.applicationContext
        final override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val dispatcher = RemoteConfigBridgeDispatcher { RemoteConfigRuntime.get(requireNotNull(context)) }
            return BridgeResponse.success(dispatcher.execute(operation, parameters))
        }
    }
}

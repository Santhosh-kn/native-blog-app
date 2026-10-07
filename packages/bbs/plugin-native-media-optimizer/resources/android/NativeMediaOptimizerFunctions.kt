package com.bbs.plugins.native_media_optimizer

import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

/** Public reflection targets exactly matching nativephp.json. Inputs contain IDs only. */
object NativeMediaOptimizerFunctions {
    class IsAvailable(activity: FragmentActivity) : Function(activity, "IsAvailable")
    class InspectMedia(activity: FragmentActivity) : Function(activity, "InspectMedia")
    class OptimizeImage(activity: FragmentActivity) : Function(activity, "OptimizeImage")
    class OptimizeVideo(activity: FragmentActivity) : Function(activity, "OptimizeVideo")
    class GenerateThumbnail(activity: FragmentActivity) : Function(activity, "GenerateThumbnail")
    class GetStatus(activity: FragmentActivity) : Function(activity, "GetStatus")
    class Cancel(activity: FragmentActivity) : Function(activity, "Cancel")
    class GetResult(activity: FragmentActivity) : Function(activity, "GetResult")
    class DeleteOutput(activity: FragmentActivity) : Function(activity, "DeleteOutput")

    abstract class Function(private val activity: FragmentActivity, private val operation: String) : BridgeFunction {
        final override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            NativeMediaOptimizerEventDispatcher.attach(activity)
            var request: NativeMediaOptimizerRequest? = null
            var code: String? = null
            if (operation in Contract.OPERATIONS) {
                when (val parsed = NativeMediaOptimizerRequest.parse(operation, parameters)) {
                    is NativeMediaOptimizerRequestParseResult.Valid -> request = parsed.request
                    is NativeMediaOptimizerRequestParseResult.Invalid -> code = parsed.code
                }
            } else if (operation == "IsAvailable") {
                if (parameters.isNotEmpty()) code = Contract.INVALID_OPTIONS
            } else {
                code = queryError(parameters)
            }
            if (code != null) return BridgeResponse.success(failure(parameters["id"], code))
            if (Looper.myLooper() == Looper.getMainLooper()) {
                return BridgeResponse.success(failure(parameters["id"], Contract.NATIVE_UNAVAILABLE))
            }
            val data = try {
                val runtime = NativeMediaOptimizerRuntime.get(activity.applicationContext)
                when (operation) {
                    "IsAvailable" -> runtime.availability(NativeMediaOptimizerCapabilities::read)
                    "GetStatus", "GetResult" -> runtime.getStatus(parameters["id"] as String).toBridgeMap()
                    "Cancel" -> runtime.cancel(parameters["id"] as String).toBridgeMap()
                    "DeleteOutput" -> runtime.deleteOutput(parameters["id"] as String)
                    else -> runtime.start(requireNotNull(request)).toBridgeMap()
                }
            } catch (_: NativeMediaOptimizerStorageException) { failure(parameters["id"], Contract.PERSIST_FAILED) }
            catch (_: OutOfMemoryError) { failure(parameters["id"], Contract.LIMIT_EXCEEDED) }
            catch (_: Exception) { failure(parameters["id"], Contract.NATIVE_UNAVAILABLE) }
            catch (_: LinkageError) { failure(parameters["id"], Contract.NATIVE_UNAVAILABLE) }
            return BridgeResponse.success(data)
        }

        private fun failure(id: Any?, code: String): Map<String, Any> = when (operation) {
            "IsAvailable" -> NativeMediaOptimizerCapabilities.unavailable()
            "DeleteOutput" -> NativeMediaOptimizerCoordinator.deletion(id, false, code)
            else -> NativeMediaOptimizerResult.rejected(id, code, operation).toBridgeMap()
        }
    }

    internal fun queryError(parameters: Map<*, *>): String? {
        Contract.sizeError(parameters)?.let { return it }
        if (parameters.keys != setOf("id")) return Contract.INVALID_OPTIONS
        if (Contract.requestId(parameters["id"]) == null) return Contract.INVALID_REQUEST_ID
        return null
    }
}

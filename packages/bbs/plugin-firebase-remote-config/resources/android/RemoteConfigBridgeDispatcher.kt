package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import org.json.JSONObject
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** Validates before touching runtime/Firebase. No Activity, private path or exception text is returned. */
internal class RemoteConfigBridgeDispatcher(private val provider: () -> RemoteConfigCoordinator) {
    fun execute(operation: String, parameters: Map<String, Any>): Map<String, Any> = try {
        val parsed = RemoteConfigRequest.parse(operation, parameters)
        if (parsed is RemoteConfigRequestParseResult.Invalid) {
            failure(operation, parameters["id"], parsed.code, rejected = true)
        } else if (Looper.myLooper() == Looper.getMainLooper()) {
            failure(operation, parameters["id"], Contract.NATIVE_UNAVAILABLE)
        } else {
            val request = (parsed as RemoteConfigRequestParseResult.Valid).request
            val coordinator = provider()
            val response = when (operation) {
                "IsAvailable" -> coordinator.availability()
                "GetInfo" -> coordinator.getInfo()
                "GetValues" -> coordinator.getValues(request.values)
                "GetStatus" -> coordinator.getStatus(requireNotNull(request.id)).toBridgeMap()
                else -> coordinator.start(request).toBridgeMap()
            }
            if (Contract.boundedResponse(response)) response
            else failure(operation, request.id, Contract.LIMIT_EXCEEDED)
        }
    } catch (error: RemoteConfigCoordinatorException) { failure(operation, parameters["id"], error.code) }
    catch (_: RemoteConfigStorageException) { failure(operation, parameters["id"], Contract.PERSIST_FAILED) }
    catch (_: OutOfMemoryError) { failure(operation, parameters["id"], Contract.LIMIT_EXCEEDED) }
    catch (_: LinkageError) { failure(operation, parameters["id"], Contract.NATIVE_UNAVAILABLE) }
    catch (_: Exception) { failure(operation, parameters["id"], Contract.NATIVE_UNAVAILABLE) }

    companion object {
        private fun failure(operation: String, id: Any?, code: String, rejected: Boolean = false): Map<String, Any> {
            return when (operation) {
                "IsAvailable" -> mapOf("platform" to "android", "available" to false, "errorCode" to code)
                "GetValues" -> RemoteConfigValuesReader.failure(code)
                "GetInfo" -> RemoteConfigSdkInfo.failure(code)
                "GetStatus" -> controlFailure(id, null, code)
                in Contract.OPERATIONS -> if (rejected) RemoteConfigOperationResult.rejected(id, operation, code).toBridgeMap()
                    else controlFailure(id, operation, code)
                else -> RemoteConfigSdkInfo.failure(Contract.INVALID_METHOD)
            }
        }

        /** A control failure says neither that a mutation was rejected nor that it completed. */
        internal fun controlFailure(id: Any?, operation: String?, code: String): Map<String, Any> = mapOf(
            "id" to (Contract.requestId(id) ?: JSONObject.NULL),
            "operation" to (operation ?: JSONObject.NULL), "success" to false, "errorCode" to code)
    }
}

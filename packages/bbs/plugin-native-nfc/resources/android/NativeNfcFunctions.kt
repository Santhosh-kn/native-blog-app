package com.bbs.plugins.native_nfc

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

object NativeNfcFunctions {

    class IsAvailable(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            NativeNfcRequest.requireNoParameters(parameters)

            return BridgeResponse.success(
                NativeNfcAvailabilityChecker
                    .check(context.applicationContext)
                    .toMap()
            )
        }
    }

    class StartScan(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = NativeNfcRequest.parseId(parameters)

            if (!NativeNfcCoordinator.ensure(activity)) {
                throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_ACTIVITY_UNAVAILABLE
                )
            }

            return when (
                val result = NativeNfcCoordinator.beginOperation(
                    context = activity.applicationContext,
                    id = request.id,
                    operation = NativeNfcContract.OP_START_SCAN
                )
            ) {
                is NativeNfcBeginResult.Started -> BridgeResponse.success(
                    mapOf(
                        "id" to request.id,
                        "operation" to NativeNfcContract.OP_START_SCAN,
                        "status" to NativeNfcContract.STATUS_PENDING
                    )
                )

                NativeNfcBeginResult.Busy -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_OPERATION_BUSY
                )

                NativeNfcBeginResult.DuplicateId -> throw BridgeError.ExecutionFailed(
                    "duplicate_request_id"
                )
            }
        }
    }

    class StartWrite(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = NativeNfcRequest.parseWrite(parameters)

            if (!NativeNfcCoordinator.ensure(activity)) {
                throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_ACTIVITY_UNAVAILABLE
                )
            }

            return when (
                NativeNfcCoordinator.beginOperation(
                    context = activity.applicationContext,
                    id = request.id,
                    operation = NativeNfcContract.OP_START_WRITE,
                    text = request.text
                )
            ) {
                is NativeNfcBeginResult.Started -> BridgeResponse.success(
                    mapOf(
                        "id" to request.id,
                        "operation" to NativeNfcContract.OP_START_WRITE,
                        "status" to NativeNfcContract.STATUS_PENDING
                    )
                )

                NativeNfcBeginResult.Busy -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_OPERATION_BUSY
                )

                NativeNfcBeginResult.DuplicateId -> throw BridgeError.ExecutionFailed(
                    "duplicate_request_id"
                )
            }
        }
    }

    class GetStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = NativeNfcRequest.parseId(parameters)

            return BridgeResponse.success(
                NativeNfcCoordinator
                    .getStore(context.applicationContext)
                    .getStatus(request.id)
            )
        }
    }

    class StopSession(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = NativeNfcRequest.parseId(parameters)
            val stopped = NativeNfcCoordinator.stopOperation(request.id)

            val status = NativeNfcCoordinator
                .getStore(context.applicationContext)
                .getStatus(request.id)

            if (!stopped && status["status"] == NativeNfcContract.STATUS_PENDING) {
                throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_OPERATION_BUSY
                )
            }

            return BridgeResponse.success(status)
        }
    }

    class ConsumeResult(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = NativeNfcRequest.parseId(parameters)

            return when (
                val result = NativeNfcCoordinator
                    .getStore(context.applicationContext)
                    .consume(request.id)
            ) {
                is NativeNfcConsumeResult.Consumed -> BridgeResponse.success(
                    mapOf(
                        "id" to request.id,
                        "status" to NativeNfcContract.STATUS_READ,
                        "consumed" to true,
                        "text" to result.text
                    )
                )

                NativeNfcConsumeResult.NotFound -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.STATUS_NOT_FOUND
                )

                NativeNfcConsumeResult.NotReady -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_RESULT_NOT_READY
                )

                NativeNfcConsumeResult.AlreadyConsumed -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_RESULT_ALREADY_CONSUMED
                )

                NativeNfcConsumeResult.Expired -> throw BridgeError.ExecutionFailed(
                    NativeNfcContract.ERROR_RESULT_EXPIRED
                )
            }
        }
    }
}

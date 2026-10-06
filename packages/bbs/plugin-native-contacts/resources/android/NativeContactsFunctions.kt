package com.bbs.plugins.native_contacts

import android.os.Build
import androidx.fragment.app.FragmentActivity
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import java.util.UUID

object NativeContactsFunctions {

    class IsAvailable(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val data = if (parameters.isNotEmpty()) {
                unavailable(Contract.INVALID_PARAMETERS)
            } else {
                try {
                    NativeContactsIntents.availability(
                        activity.applicationContext
                    )
                } catch (_: Exception) {
                    unavailable(Contract.OPERATION_UNAVAILABLE)
                }
            }

            return BridgeResponse.success(data)
        }
    }

    class Pick(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            start(activity, Contract.PICK, parameters)
    }

    class Create(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            start(activity, Contract.CREATE, parameters)
    }

    class Open(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            start(activity, Contract.OPEN, parameters)
    }

    class GetStatus(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            lookup(activity, NativeContactsRequest.GET_STATUS, parameters)
    }

    class ConsumeResult(
        private val activity: FragmentActivity
    ) : BridgeFunction {
        init {
            NativeContactsCoordinator.ensure(activity)
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            lookup(activity, NativeContactsRequest.CONSUME_RESULT, parameters)
    }

    private fun start(
        activity: FragmentActivity,
        operation: String,
        parameters: Map<String, Any>
    ): Map<String, Any> {
        val validation = validate(operation, parameters)

        if (validation is NativeContactsRequestValidation.Invalid) {
            return rejected(
                NativeContactsResult.failed(
                    validation.id,
                    validation.errorCode,
                    validation.operation,
                    validation.mode
                )
            )
        }

        val request =
            (validation as NativeContactsRequestValidation.Valid).request

        if (Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL) {
            return rejected(failedRequest(request, Contract.OPERATION_UNAVAILABLE))
        }

        if (
            activity.isFinishing ||
            activity.isDestroyed ||
            !NativeContactsCoordinator.ensure(activity)
        ) {
            return rejected(failedRequest(request, Contract.ACTIVITY_UNAVAILABLE))
        }

        val store = try {
            NativeContactsStore(activity.applicationContext)
        } catch (_: Exception) {
            return rejected(
                failedRequest(request, Contract.RESULT_PERSISTENCE_FAILED)
            )
        }

        val reservation = try {
            store.begin(request)
        } catch (_: Exception) {
            return rejected(
                failedRequest(request, Contract.RESULT_PERSISTENCE_FAILED)
            )
        }

        return when (reservation) {
            is NativeContactsBeginResult.Rejected ->
                rejected(reservation.result)

            is NativeContactsBeginResult.Started -> {
                val pending = reservation.pending

                val scheduled = try {
                    NativeContactsCoordinator.launch(
                        activity,
                        request,
                        pending.handle
                    )
                } catch (_: Exception) {
                    false
                }

                if (scheduled) {
                    BridgeResponse.success(
                        pending.result.toBridgeMap(accepted = true)
                    )
                } else {
                    val completed = try {
                        store.complete(
                            pending.handle,
                            Contract.STATUS_FAILED,
                            Contract.ACTIVITY_UNAVAILABLE
                        )
                    } catch (_: Exception) {
                        return rejected(
                            failedRequest(
                                request,
                                Contract.RESULT_PERSISTENCE_FAILED
                            )
                        )
                    }

                    if (completed != null) {
                        NativeContactsEventDispatcher.dispatch(
                            activity,
                            completed
                        )
                    }

                    rejected(
                        completed ?: failedRequest(
                            request,
                            Contract.ACTIVITY_UNAVAILABLE
                        )
                    )
                }
            }
        }
    }

    private fun lookup(
        activity: FragmentActivity,
        operation: String,
        parameters: Map<String, Any>
    ): Map<String, Any> {
        val validation = validate(operation, parameters)

        if (validation is NativeContactsRequestValidation.Invalid) {
            return BridgeResponse.success(
                NativeContactsResult.failed(
                    validation.id,
                    validation.errorCode
                ).toBridgeMap()
            )
        }

        val request =
            (validation as NativeContactsRequestValidation.Valid).request

        NativeContactsCoordinator.ensure(activity)

        return try {
            val store = NativeContactsStore(activity.applicationContext)

            if (operation == NativeContactsRequest.GET_STATUS) {
                // getStatus() strips selection data before returning.
                BridgeResponse.success(
                    store.getStatus(request.id).toBridgeMap()
                )
            } else {
                when (val result = store.consume(request.id)) {
                    is NativeContactsConsumeResult.Metadata ->
                        BridgeResponse.success(
                            result.result.toBridgeMap(
                                errorOverride = result.errorOverride
                            )
                        )

                    is NativeContactsConsumeResult.Delivered ->
                        // consume() has already verified durable redaction.
                        BridgeResponse.success(
                            result.result.toBridgeMap(
                                selectionToDeliver = result.selection
                            )
                        )
                }
            }
        } catch (_: Exception) {
            // The operation may be unknowable when storage cannot be read.
            BridgeResponse.success(
                NativeContactsResult.failed(
                    request.id,
                    Contract.RESULT_PERSISTENCE_FAILED
                ).toBridgeMap()
            )
        }
    }

    private fun validate(
        operation: String,
        parameters: Map<String, Any>
    ): NativeContactsRequestValidation =
        try {
            NativeContactsRequest.fromParameters(operation, parameters)
        } catch (_: Exception) {
            NativeContactsRequestValidation.Invalid(
                id = Contract.requestId(parameters["id"])
                    ?: UUID.randomUUID().toString(),
                operation = operation.takeIf {
                    it in Contract.operations
                },
                mode = (parameters["mode"] as? String)?.takeIf {
                    operation == Contract.PICK && it in Contract.modes
                },
                errorCode = Contract.INVALID_PARAMETERS
            )
        }

    private fun failedRequest(
        request: NativeContactsRequest,
        errorCode: String
    ): NativeContactsResult =
        NativeContactsResult.failed(
            request.id,
            errorCode,
            request.operation,
            request.mode
        )

    private fun rejected(
        result: NativeContactsResult
    ): Map<String, Any> =
        BridgeResponse.success(result.toBridgeMap(accepted = false))

    private fun unavailable(errorCode: String): Map<String, Any> =
        mapOf(
            "available" to false,
            "platform" to "android",
            "apiLevel" to Build.VERSION.SDK_INT,
            "minimumApiLevel" to Contract.MIN_API_LEVEL,
            "capabilities" to mapOf(
                "pickContact" to false,
                "pickPhone" to false,
                "pickEmail" to false,
                "create" to false,
                "open" to false
            ),
            "errorCode" to errorCode,
            "errorMessage" to Contract.message(errorCode)
        )
}
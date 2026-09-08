package com.bbs.plugins.native_background_transfer

import android.content.Context
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import java.util.UUID

object NativeBackgroundTransferFunctions {

    class StartDownload(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            return when (
                val validation =
                    NativeBackgroundTransferRequest
                        .fromParameters(parameters)
            ) {
                is NativeBackgroundTransferRequestValidation.Invalid ->
                    BridgeResponse.success(
                        rejectedStart(
                            id = validation.id,
                            errorCode =
                                validation.errorCode
                        )
                    )

                is NativeBackgroundTransferRequestValidation.Valid -> {
                    val request =
                        validation.request

                    val store =
                        NativeBackgroundTransferStore(
                            context
                        )

                    when (
                        store.begin(request)
                    ) {
                        NativeBackgroundTransferBeginResult.Stored -> {
                            val scheduler =
                                NativeBackgroundTransferScheduler(
                                    context
                                )

                            if (
                                scheduler.enqueueDownload(
                                    request.id
                                )
                            ) {
                                BridgeResponse.success(
                                    acceptedStart(
                                        request.id
                                    )
                                )
                            } else {
                                val failed =
                                    NativeBackgroundTransferResult
                                        .failed(
                                            id = request.id,
                                            errorCode =
                                                NativeBackgroundTransferContract
                                                    .SCHEDULER_UNAVAILABLE
                                        )

                                val persisted =
                                    store.update(
                                        failed
                                    )

                                BridgeResponse.success(
                                    rejectedStart(
                                        id = request.id,
                                        errorCode =
                                            if (persisted) {
                                                NativeBackgroundTransferContract
                                                    .SCHEDULER_UNAVAILABLE
                                            } else {
                                                NativeBackgroundTransferContract
                                                    .RESULT_PERSISTENCE_FAILED
                                            }
                                    )
                                )
                            }
                        }

                        NativeBackgroundTransferBeginResult.Duplicate ->
                            BridgeResponse.success(
                                rejectedStart(
                                    id = request.id,
                                    errorCode =
                                        NativeBackgroundTransferContract
                                            .DUPLICATE_TRANSFER_ID
                                )
                            )

                        NativeBackgroundTransferBeginResult.Failed ->
                            BridgeResponse.success(
                                rejectedStart(
                                    id = request.id,
                                    errorCode =
                                        NativeBackgroundTransferContract
                                            .RESULT_PERSISTENCE_FAILED
                                )
                            )
                    }
                }
            }
        }
    }
    class GetStatus(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = normalizedId(
                parameters["id"]
            )

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                )
            }

            val store =
                NativeBackgroundTransferStore(context)

            val result = store.result(id)
                ?: NativeBackgroundTransferResult.failed(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract
                            .TRANSFER_NOT_FOUND
                )

            return BridgeResponse.success(
                result.toBridgeMap()
            )
        }
    }

    class ListTransfers(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val store =
                NativeBackgroundTransferStore(context)

            val transfers = store
                .listResults()
                .map {
                    it.toBridgeMap()
                }

            return BridgeResponse.success(
                mapOf(
                    "transfers" to transfers
                )
            )
        }
    }

    class Cancel(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = normalizedId(
                parameters["id"]
            )

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                )
            }

            val store =
                NativeBackgroundTransferStore(
                    context
                )

            val current =
                store.result(id)
                    ?: return BridgeResponse.success(
                        NativeBackgroundTransferResult
                            .failed(
                                id = id,
                                errorCode =
                                    NativeBackgroundTransferContract
                                        .TRANSFER_NOT_FOUND
                            )
                            .toBridgeMap()
                    )

            /*
             * Terminal transfers are immutable.
             * Cancellation becomes an idempotent read.
             */
            if (
                NativeBackgroundTransferContract
                    .isTerminalStatus(
                        current.status
                    )
            ) {
                return BridgeResponse.success(
                    current.toBridgeMap()
                )
            }

            val scheduler =
                NativeBackgroundTransferScheduler(
                    context
                )

            if (!scheduler.cancel(id)) {
                return BridgeResponse.success(
                    NativeBackgroundTransferResult
                        .failed(
                            id = id,
                            errorCode =
                                NativeBackgroundTransferContract
                                    .SCHEDULER_UNAVAILABLE,
                            transferredBytes =
                                current.transferredBytes,
                            totalBytes =
                                current.totalBytes,
                            progress =
                                current.progress
                        )
                        .toBridgeMap()
                )
            }

            val cancelled =
                NativeBackgroundTransferResult(
                    id = id,
                    status =
                        NativeBackgroundTransferContract
                            .STATUS_CANCELLED,
                    transferredBytes =
                        current.transferredBytes,
                    totalBytes =
                        current.totalBytes,
                    progress =
                        current.progress,
                    consumed = false,
                    updatedAt =
                        System.currentTimeMillis()
                )

            if (store.update(cancelled)) {
                return BridgeResponse.success(
                    cancelled.toBridgeMap()
                )
            }

            /*
             * The worker may have reached a terminal state between
             * cancelUniqueWork() and our cancellation persistence.
             */
            val latest =
                store.result(id)

            if (
                latest != null &&
                NativeBackgroundTransferContract
                    .isTerminalStatus(
                        latest.status
                    )
            ) {
                return BridgeResponse.success(
                    latest.toBridgeMap()
                )
            }

            return BridgeResponse.success(
                NativeBackgroundTransferResult
                    .failed(
                        id = id,
                        errorCode =
                            NativeBackgroundTransferContract
                                .RESULT_PERSISTENCE_FAILED,
                        transferredBytes =
                            latest?.transferredBytes
                                ?: current.transferredBytes,
                        totalBytes =
                            latest?.totalBytes
                                ?: current.totalBytes,
                        progress =
                            latest?.progress
                                ?: current.progress
                    )
                    .toBridgeMap()
            )
        }
    }
    class ConsumeResult(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = normalizedId(
                parameters["id"]
            )

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                )
            }

            val store =
                NativeBackgroundTransferStore(context)

            val result = when (
                val consumeResult =
                    store.consume(id)
            ) {
                is NativeBackgroundTransferConsumeResult.Consumed ->
                    consumeResult.result

                is NativeBackgroundTransferConsumeResult.AlreadyConsumed ->
                    NativeBackgroundTransferResult.failed(
                        id = id,
                        errorCode =
                            NativeBackgroundTransferContract
                                .RESULT_ALREADY_CONSUMED,
                        transferredBytes =
                            consumeResult.result
                                .transferredBytes,
                        totalBytes =
                            consumeResult.result
                                .totalBytes,
                        progress =
                            consumeResult.result
                                .progress,
                        consumed = true
                    )

                is NativeBackgroundTransferConsumeResult.NotTerminal ->
                    consumeResult.result

                NativeBackgroundTransferConsumeResult.NotFound ->
                    NativeBackgroundTransferResult.failed(
                        id = id,
                        errorCode =
                            NativeBackgroundTransferContract
                                .TRANSFER_NOT_FOUND
                    )

                NativeBackgroundTransferConsumeResult.Failed ->
                    NativeBackgroundTransferResult.failed(
                        id = id,
                        errorCode =
                            NativeBackgroundTransferContract
                                .RESULT_PERSISTENCE_FAILED
                    )
            }

            return BridgeResponse.success(
                result.toBridgeMap()
            )
        }
    }

    private fun normalizedId(
        value: Any?
    ): String? {
        return NativeBackgroundTransferContract
            .normalizeRequestId(value)
    }

    private fun invalidRequestResult():
        Map<String, Any> {
        return NativeBackgroundTransferResult
            .failed(
                id = UUID.randomUUID().toString(),
                errorCode =
                    NativeBackgroundTransferContract
                        .INVALID_REQUEST_ID
            )
            .toBridgeMap()
    }

    private fun acceptedStart(
        id: String
    ): Map<String, Any> {
        return mapOf(
            "accepted" to true,
            "id" to id,
            "type" to
                NativeBackgroundTransferContract
                    .TYPE_DOWNLOAD,
            "status" to
                NativeBackgroundTransferContract
                    .STATUS_QUEUED
        )
    }
    private fun rejectedStart(
        id: String,
        errorCode: String
    ): Map<String, Any> {
        return mapOf(
            "accepted" to false,
            "id" to id,
            "type" to
                NativeBackgroundTransferContract
                    .TYPE_DOWNLOAD,
            "status" to
                NativeBackgroundTransferContract
                    .STATUS_FAILED,
            "errorCode" to errorCode,
            "errorMessage" to
                NativeBackgroundTransferContract
                    .errorMessage(errorCode)
        )
    }
}
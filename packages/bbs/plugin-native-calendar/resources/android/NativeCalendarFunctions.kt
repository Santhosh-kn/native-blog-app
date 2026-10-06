package com.bbs.plugins.native_calendar

import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

object NativeCalendarFunctions {

    class IsAvailable(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        init {
            NativeCalendarCoordinator.ensure(activity)
        }

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val data = if (parameters.isNotEmpty()) {
                NativeCalendarIntents.unavailable(Contract.INVALID_OPTIONS)
            } else {
                try {
                    NativeCalendarIntents.availability(
                        activity.applicationContext
                    )
                } catch (_: Exception) {
                    NativeCalendarIntents.unavailable(
                        Contract.NO_CALENDAR_APP
                    )
                }
            }

            return BridgeResponse.success(data)
        }
    }

    class CreateEvent(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        init {
            NativeCalendarCoordinator.ensure(activity)
        }

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> =
            start(activity, Contract.CREATE_EVENT, parameters)
    }

    class Open(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        init {
            NativeCalendarCoordinator.ensure(activity)
        }

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> =
            start(activity, Contract.OPEN, parameters)
    }

    class GetStatus(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        init {
            NativeCalendarCoordinator.ensure(activity)
        }

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = Contract.requestId(parameters["id"])

            val result = when {
                parameters.size != 1 ||
                    parameters.keys.any { it != "id" } ->
                    NativeCalendarResult.failure(
                        id,
                        Contract.INVALID_OPTIONS
                    )

                id == null ->
                    NativeCalendarResult.failure(
                        null,
                        Contract.INVALID_REQUEST_ID
                    )

                else -> try {
                    NativeCalendarCoordinator.getStatus(activity, id)
                } catch (_: Exception) {
                    NativeCalendarResult.failure(
                        id,
                        Contract.PERSIST_FAILED
                    )
                }
            }

            return BridgeResponse.success(result.toBridgeMap())
        }
    }

    private fun start(
        activity: FragmentActivity,
        operation: String,
        parameters: Map<String, Any>
    ): Map<String, Any> {
        val validation = try {
            if (operation == Contract.CREATE_EVENT) {
                NativeCalendarRequest.parseCreate(parameters)
            } else {
                NativeCalendarRequest.parseOpen(parameters)
            }
        } catch (_: Exception) {
            NativeCalendarRequestParseResult.Invalid(
                id = Contract.requestId(parameters["id"])
                    ?: Contract.FALLBACK_REQUEST_ID,
                operation = operation,
                target = safeTarget(operation, parameters),
                errorCode = Contract.INVALID_OPTIONS
            )
        }

        val result = when (validation) {
            is NativeCalendarRequestParseResult.Invalid ->
                NativeCalendarResult.failure(
                    validation.id,
                    validation.errorCode,
                    validation.operation,
                    validation.target
                )

            is NativeCalendarRequestParseResult.Valid -> {
                val request = validation.request

                try {
                    NativeCalendarCoordinator.start(activity, request)
                } catch (_: Exception) {
                    NativeCalendarResult.failure(
                        request.id,
                        Contract.PERSIST_FAILED,
                        request.operation,
                        request.target
                    )
                }
            }
        }

        return BridgeResponse.success(result.toBridgeMap())
    }

    private fun safeTarget(
        operation: String,
        parameters: Map<String, Any>
    ): String? =
        when {
            operation == Contract.CREATE_EVENT ->
                Contract.TARGET_EDITOR

            parameters.containsKey("dateMs") &&
                !parameters.containsKey("eventId") ->
                Contract.TARGET_DATE

            parameters.containsKey("eventId") &&
                !parameters.containsKey("dateMs") ->
                Contract.TARGET_EVENT

            else -> null
        }
}

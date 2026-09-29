package com.bbs.plugins.native_passkeys

import android.content.Context
import android.os.Build
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import androidx.core.content.ContextCompat
import androidx.credentials.CreateCredentialResponse
import androidx.credentials.CreatePublicKeyCredentialRequest
import androidx.credentials.CreatePublicKeyCredentialResponse
import androidx.credentials.CredentialManager
import androidx.credentials.CredentialManagerCallback
import androidx.credentials.GetCredentialRequest
import androidx.credentials.GetCredentialResponse
import androidx.credentials.GetPublicKeyCredentialOption
import androidx.credentials.PublicKeyCredential
import androidx.credentials.exceptions.CreateCredentialCancellationException
import androidx.credentials.exceptions.CreateCredentialException
import androidx.credentials.exceptions.CreateCredentialInterruptedException
import androidx.credentials.exceptions.CreateCredentialProviderConfigurationException
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.GetCredentialInterruptedException
import androidx.credentials.exceptions.GetCredentialProviderConfigurationException
import androidx.credentials.exceptions.NoCredentialException
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import java.util.UUID

object NativePasskeysFunctions {

    class IsAvailable(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val apiLevel = Build.VERSION.SDK_INT

            if (
                apiLevel <
                NativePasskeysContract.MINIMUM_ANDROID_API
            ) {
                return BridgeResponse.success(
                    availability(
                        available = false,
                        apiLevel = apiLevel,
                        errorCode =
                            NativePasskeysContract
                                .ANDROID_VERSION_UNSUPPORTED
                    )
                )
            }

            return try {
                CredentialManager.create(context)

                BridgeResponse.success(
                    availability(
                        available = true,
                        apiLevel = apiLevel
                    )
                )
            } catch (_: RuntimeException) {
                BridgeResponse.success(
                    availability(
                        available = false,
                        apiLevel = apiLevel,
                        errorCode =
                            NativePasskeysContract
                                .CREDENTIAL_MANAGER_UNAVAILABLE
                    )
                )
            }
        }
    }

    class Create(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val validation = validatedRequest(
                parameters = parameters,
                operation =
                    NativePasskeysContract.OPERATION_CREATE
            )

            return when (validation) {
                is NativePasskeysRequestValidation.Invalid ->
                    rejected(
                        NativePasskeysResult.failed(
                            id = validation.id,
                            operation = validation.operation,
                            errorCode = validation.errorCode
                        )
                    )

                is NativePasskeysRequestValidation.Valid ->
                    startRequest(
                        activity = activity,
                        request = validation.request
                    ) { request, signal ->
                        startCreate(
                            activity = activity,
                            request = request,
                            signal = signal
                        )
                    }
            }
        }
    }

    class Authenticate(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val validation = validatedRequest(
                parameters = parameters,
                operation =
                    NativePasskeysContract
                        .OPERATION_AUTHENTICATE
            )

            return when (validation) {
                is NativePasskeysRequestValidation.Invalid ->
                    rejected(
                        NativePasskeysResult.failed(
                            id = validation.id,
                            operation = validation.operation,
                            errorCode = validation.errorCode
                        )
                    )

                is NativePasskeysRequestValidation.Valid ->
                    startRequest(
                        activity = activity,
                        request = validation.request
                    ) { request, signal ->
                        startAuthentication(
                            activity = activity,
                            request = request,
                            signal = signal
                        )
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
            val id =
                normalizedParameterId(parameters)

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                        .toBridgeMap()
                )
            }

            val store =
                NativePasskeysStore(context)

            var result =
                store.result(id)
                    ?: NativePasskeysResult.notFound(id)

            if (
                result.status ==
                    NativePasskeysContract.STATUS_PENDING &&
                !NativePasskeysOperationRegistry
                    .isActive(id)
            ) {
                val interrupted =
                    NativePasskeysResult.failed(
                        id = id,
                        operation = result.operation,
                        errorCode =
                            NativePasskeysContract
                                .OPERATION_INTERRUPTED
                    )

                result =
                    if (store.complete(interrupted)) {
                        interrupted
                    } else {
                        store.result(id)
                            ?: NativePasskeysResult.failed(
                                id = id,
                                operation = result.operation,
                                errorCode =
                                    NativePasskeysContract
                                        .RESULT_PERSISTENCE_FAILED
                            )
                    }
            }

            return BridgeResponse.success(
                result.toBridgeMap()
            )
        }
    }

    class ConsumeResult(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id =
                normalizedParameterId(parameters)

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                        .toBridgeMap()
                )
            }

            val store =
                NativePasskeysStore(context)

            val result =
                when (
                    val consumed = store.consume(id)
                ) {
                    is NativePasskeysConsumeResult.Consumed ->
                        consumed.result

                    is NativePasskeysConsumeResult
                        .AlreadyConsumed ->
                        NativePasskeysResult.failed(
                            id = id,
                            operation =
                                consumed.result.operation,
                            errorCode =
                                NativePasskeysContract
                                    .RESULT_ALREADY_CONSUMED,
                            consumed = true
                        )

                    is NativePasskeysConsumeResult.NotTerminal ->
                        consumed.result

                    NativePasskeysConsumeResult.NotFound ->
                        NativePasskeysResult.notFound(id)

                    NativePasskeysConsumeResult.Failed ->
                        NativePasskeysResult.failed(
                            id = id,
                            operation = null,
                            errorCode =
                                NativePasskeysContract
                                    .RESULT_PERSISTENCE_FAILED
                        )
                }

            return BridgeResponse.success(
                result.toBridgeMap(
                    includeResponseJson =
                        result.status ==
                            NativePasskeysContract
                                .STATUS_SUCCEEDED &&
                        result.consumed
                )
            )
        }
    }

    class Cancel(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id =
                normalizedParameterId(parameters)

            if (id == null) {
                return BridgeResponse.success(
                    invalidRequestResult()
                        .toBridgeMap()
                )
            }

            val store =
                NativePasskeysStore(activity)

            val current =
                store.result(id)
                    ?: return BridgeResponse.success(
                        NativePasskeysResult
                            .notFound(id)
                            .toBridgeMap()
                    )

            if (
                current.status !=
                NativePasskeysContract.STATUS_PENDING
            ) {
                return BridgeResponse.success(
                    current.toBridgeMap()
                )
            }

            NativePasskeysOperationRegistry.cancel(id)

            val operation =
                current.operation
                    ?: return BridgeResponse.success(
                        NativePasskeysResult.failed(
                            id = id,
                            operation = null,
                            errorCode =
                                NativePasskeysContract
                                    .UNKNOWN_ERROR
                        ).toBridgeMap()
                    )

            val cancelled =
                NativePasskeysResult.cancelled(
                    id = id,
                    operation = operation
                )

            val result =
                if (store.complete(cancelled)) {
                    NativePasskeysEventDispatcher.dispatch(
                        activity = activity,
                        result = cancelled
                    )
                    cancelled
                } else {
                    store.result(id)
                        ?: NativePasskeysResult.failed(
                            id = id,
                            operation = operation,
                            errorCode =
                                NativePasskeysContract
                                    .RESULT_PERSISTENCE_FAILED
                        )
                }

            return BridgeResponse.success(
                result.toBridgeMap()
            )
        }
    }

    private fun validatedRequest(
        parameters: Map<String, Any>,
        operation: String
    ): NativePasskeysRequestValidation {
        return try {
            NativePasskeysRequest.fromParameters(
                parameters = parameters,
                expectedOperation = operation
            )
        } catch (_: RuntimeException) {
            NativePasskeysRequestValidation.Invalid(
                id = UUID.randomUUID().toString(),
                operation =
                    NativePasskeysContract
                        .normalizeOperation(operation),
                errorCode =
                    NativePasskeysContract.UNKNOWN_ERROR
            )
        }
    }

    private fun startRequest(
        activity: FragmentActivity,
        request: NativePasskeysRequest,
        launcher: (
            NativePasskeysRequest,
            CancellationSignal
        ) -> Unit
    ): Map<String, Any> {
        if (
            Build.VERSION.SDK_INT <
            NativePasskeysContract.MINIMUM_ANDROID_API
        ) {
            return rejected(
                NativePasskeysResult.failed(
                    id = request.id,
                    operation = request.operation,
                    errorCode =
                        NativePasskeysContract
                            .ANDROID_VERSION_UNSUPPORTED
                )
            )
        }

        val store =
            NativePasskeysStore(activity)

        when (store.begin(request)) {
            NativePasskeysBeginResult.Busy ->
                return rejected(
                    NativePasskeysResult.failed(
                        id = request.id,
                        operation = request.operation,
                        errorCode =
                            NativePasskeysContract
                                .REQUEST_BUSY
                    )
                )

            NativePasskeysBeginResult.Failed ->
                return rejected(
                    NativePasskeysResult.failed(
                        id = request.id,
                        operation = request.operation,
                        errorCode =
                            NativePasskeysContract
                                .RESULT_PERSISTENCE_FAILED
                    )
                )

            NativePasskeysBeginResult.Stored -> Unit
        }

        val signal = CancellationSignal()

        if (
            !NativePasskeysOperationRegistry.register(
                id = request.id,
                signal = signal
            )
        ) {
            val failure =
                NativePasskeysResult.failed(
                    id = request.id,
                    operation = request.operation,
                    errorCode =
                        NativePasskeysContract.REQUEST_BUSY
                )

            store.complete(failure)

            return rejected(failure)
        }

        val launch:
            () -> NativePasskeysResult? = {
                try {
                    launcher(request, signal)
                    null
                } catch (_: IllegalArgumentException) {
                    NativePasskeysResult.failed(
                        id = request.id,
                        operation = request.operation,
                        errorCode =
                            NativePasskeysContract
                                .INVALID_REQUEST_JSON
                    )
                } catch (_: RuntimeException) {
                    NativePasskeysResult.failed(
                        id = request.id,
                        operation = request.operation,
                        errorCode =
                            fallbackOperationError(
                                request.operation
                            )
                    )
                }
            }

        if (
            Looper.myLooper() ==
            Looper.getMainLooper()
        ) {
            val failure = launch()

            if (failure != null) {
                return failBeforeAcceptance(
                    store = store,
                    request = request,
                    signal = signal,
                    failure = failure
                )
            }

            return accepted(request)
        }

        val scheduled = try {
            Handler(
                Looper.getMainLooper()
            ).post {
                val failure = launch()

                if (failure != null) {
                    completeAndDispatch(
                        activity = activity,
                        request = request,
                        signal = signal,
                        result = failure
                    )
                }
            }
        } catch (_: RuntimeException) {
            false
        }

        if (!scheduled) {
            val failure =
                NativePasskeysResult.failed(
                    id = request.id,
                    operation = request.operation,
                    errorCode =
                        NativePasskeysContract
                            .ACTIVITY_UNAVAILABLE
                )

            return failBeforeAcceptance(
                store = store,
                request = request,
                signal = signal,
                failure = failure
            )
        }

        return accepted(request)
    }

    private fun startCreate(
        activity: FragmentActivity,
        request: NativePasskeysRequest,
        signal: CancellationSignal
    ) {
        val credentialManager =
            CredentialManager.create(activity)

        val nativeRequest =
            CreatePublicKeyCredentialRequest(
                requestJson = request.requestJson
            )

        credentialManager.createCredentialAsync(
            activity,
            nativeRequest,
            signal,
            ContextCompat.getMainExecutor(activity),
            object : CredentialManagerCallback<
                CreateCredentialResponse,
                CreateCredentialException
            > {
                override fun onResult(
                    result: CreateCredentialResponse
                ) {
                    val responseJson =
                        (
                            result as?
                                CreatePublicKeyCredentialResponse
                            )
                            ?.registrationResponseJson
                            ?.let(
                                NativePasskeysContract::
                                    normalizeResponseJson
                            )

                    val completed =
                        if (responseJson == null) {
                            NativePasskeysResult.failed(
                                id = request.id,
                                operation = request.operation,
                                errorCode =
                                    NativePasskeysContract
                                        .CREATE_FAILED
                            )
                        } else {
                            NativePasskeysResult.succeeded(
                                id = request.id,
                                operation = request.operation,
                                responseJson = responseJson
                            )
                        }

                    completeAndDispatch(
                        activity = activity,
                        request = request,
                        signal = signal,
                        result = completed
                    )
                }

                override fun onError(
                    e: CreateCredentialException
                ) {
                    val result =
                        when (e) {
                            is CreateCredentialCancellationException ->
                                NativePasskeysResult.cancelled(
                                    id = request.id,
                                    operation =
                                        request.operation
                                )

                            is CreateCredentialInterruptedException ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .OPERATION_INTERRUPTED
                                )

                            is CreateCredentialProviderConfigurationException ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .PROVIDER_CONFIGURATION_ERROR
                                )

                            else ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .CREATE_FAILED
                                )
                        }

                    completeAndDispatch(
                        activity = activity,
                        request = request,
                        signal = signal,
                        result = result
                    )
                }
            }
        )
    }

    private fun startAuthentication(
        activity: FragmentActivity,
        request: NativePasskeysRequest,
        signal: CancellationSignal
    ) {
        val credentialManager =
            CredentialManager.create(activity)

        val publicKeyOption =
            GetPublicKeyCredentialOption(
                requestJson = request.requestJson
            )

        val nativeRequest =
            GetCredentialRequest.Builder()
                .addCredentialOption(
                    publicKeyOption
                )
                .build()

        credentialManager.getCredentialAsync(
            activity,
            nativeRequest,
            signal,
            ContextCompat.getMainExecutor(activity),
            object : CredentialManagerCallback<
                GetCredentialResponse,
                GetCredentialException
            > {
                override fun onResult(
                    result: GetCredentialResponse
                ) {
                    val responseJson =
                        (
                            result.credential as?
                                PublicKeyCredential
                            )
                            ?.authenticationResponseJson
                            ?.let(
                                NativePasskeysContract::
                                    normalizeResponseJson
                            )

                    val completed =
                        if (responseJson == null) {
                            NativePasskeysResult.failed(
                                id = request.id,
                                operation = request.operation,
                                errorCode =
                                    NativePasskeysContract
                                        .AUTHENTICATION_FAILED
                            )
                        } else {
                            NativePasskeysResult.succeeded(
                                id = request.id,
                                operation = request.operation,
                                responseJson = responseJson
                            )
                        }

                    completeAndDispatch(
                        activity = activity,
                        request = request,
                        signal = signal,
                        result = completed
                    )
                }

                override fun onError(
                    e: GetCredentialException
                ) {
                    val result =
                        when (e) {
                            is GetCredentialCancellationException ->
                                NativePasskeysResult.cancelled(
                                    id = request.id,
                                    operation =
                                        request.operation
                                )

                            is NoCredentialException ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .NO_CREDENTIAL
                                )

                            is GetCredentialInterruptedException ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .OPERATION_INTERRUPTED
                                )

                            is GetCredentialProviderConfigurationException ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .PROVIDER_CONFIGURATION_ERROR
                                )

                            else ->
                                NativePasskeysResult.failed(
                                    id = request.id,
                                    operation =
                                        request.operation,
                                    errorCode =
                                        NativePasskeysContract
                                            .AUTHENTICATION_FAILED
                                )
                        }

                    completeAndDispatch(
                        activity = activity,
                        request = request,
                        signal = signal,
                        result = result
                    )
                }
            }
        )
    }

    private fun completeAndDispatch(
        activity: FragmentActivity,
        request: NativePasskeysRequest,
        signal: CancellationSignal,
        result: NativePasskeysResult
    ) {
        val wasActive =
            NativePasskeysOperationRegistry.finish(
                id = request.id,
                expectedSignal = signal
            )

        if (!wasActive) {
            return
        }

        val store =
            NativePasskeysStore(activity)

        val delivered =
            if (store.complete(result)) {
                result
            } else {
                NativePasskeysResult.failed(
                    id = request.id,
                    operation = request.operation,
                    errorCode =
                        NativePasskeysContract
                            .RESULT_PERSISTENCE_FAILED
                )
            }

        NativePasskeysEventDispatcher.dispatch(
            activity = activity,
            result = delivered
        )
    }

    private fun failBeforeAcceptance(
        store: NativePasskeysStore,
        request: NativePasskeysRequest,
        signal: CancellationSignal,
        failure: NativePasskeysResult
    ): Map<String, Any> {
        NativePasskeysOperationRegistry.finish(
            id = request.id,
            expectedSignal = signal
        )

        val persisted =
            store.complete(failure)

        return if (persisted) {
            rejected(failure)
        } else {
            rejected(
                NativePasskeysResult.failed(
                    id = request.id,
                    operation = request.operation,
                    errorCode =
                        NativePasskeysContract
                            .RESULT_PERSISTENCE_FAILED
                )
            )
        }
    }

    private fun accepted(
        request: NativePasskeysRequest
    ): Map<String, Any> {
        return BridgeResponse.success(
            buildMap {
                put("accepted", true)
                putAll(
                    NativePasskeysResult
                        .pending(request)
                        .toBridgeMap()
                )
            }
        )
    }

    private fun rejected(
        result: NativePasskeysResult
    ): Map<String, Any> {
        return BridgeResponse.success(
            buildMap {
                put("accepted", false)
                putAll(result.toBridgeMap())
            }
        )
    }

    private fun availability(
        available: Boolean,
        apiLevel: Int,
        errorCode: String? = null
    ): Map<String, Any> {
        return buildMap {
            put("available", available)
            put("platform", "android")
            put("apiLevel", apiLevel)
            put(
                "minimumApiLevel",
                NativePasskeysContract
                    .MINIMUM_ANDROID_API
            )

            errorCode?.let {
                put("errorCode", it)
                put(
                    "errorMessage",
                    NativePasskeysContract
                        .errorMessage(it)
                )
            }
        }
    }

    private fun normalizedParameterId(
        parameters: Map<String, Any>
    ): String? {
        return try {
            NativePasskeysContract
                .normalizeRequestId(
                    parameters["id"]
                )
        } catch (_: RuntimeException) {
            null
        }
    }

    private fun invalidRequestResult():
        NativePasskeysResult {
        return NativePasskeysResult.failed(
            id = UUID.randomUUID().toString(),
            operation = null,
            errorCode =
                NativePasskeysContract.INVALID_REQUEST_ID
        )
    }

    private fun fallbackOperationError(
        operation: String
    ): String {
        return if (
            operation ==
            NativePasskeysContract.OPERATION_CREATE
        ) {
            NativePasskeysContract.CREATE_FAILED
        } else {
            NativePasskeysContract
                .AUTHENTICATION_FAILED
        }
    }
}

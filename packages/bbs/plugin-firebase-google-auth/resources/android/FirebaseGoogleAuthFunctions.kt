package com.bbs.plugins.firebase_google_auth

import android.content.Context
import android.os.CancellationSignal
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.content.ContextCompat
import androidx.credentials.ClearCredentialStateRequest
import androidx.credentials.CredentialManager
import androidx.credentials.CredentialManagerCallback
import androidx.credentials.CustomCredential
import androidx.credentials.GetCredentialRequest
import androidx.credentials.GetCredentialResponse
import androidx.credentials.exceptions.ClearCredentialException
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.fragment.app.FragmentActivity
import com.google.android.libraries.identity.googleid.GetSignInWithGoogleOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.google.firebase.auth.FirebaseAuth
import com.google.firebase.auth.GoogleAuthProvider
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

object FirebaseGoogleAuthFunctions {

    private const val TAG = "FirebaseGoogleAuth"

    private const val COMPLETED_EVENT =
        "Bbs\\FirebaseGoogleAuth\\Events\\FirebaseGoogleAuthCompleted"

    class SignIn(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String

            Handler(Looper.getMainLooper()).post {
                startGoogleSignIn(id)
            }

            val response = mutableMapOf<String, Any>(
                "started" to true
            )

            id?.let {
                response["id"] = it
            }

            return BridgeResponse.success(response)
        }

        private fun startGoogleSignIn(id: String?) {
            try {
                val clientIdResource = activity.resources.getIdentifier(
                    "default_web_client_id",
                    "string",
                    activity.packageName
                )

                if (clientIdResource == 0) {
                    dispatchFailure(
                        message = "default_web_client_id was not found",
                        id = id
                    )
                    return
                }

                val serverClientId = activity.getString(clientIdResource)

                if (serverClientId.isBlank()) {
                    dispatchFailure(
                        message = "default_web_client_id is empty",
                        id = id
                    )
                    return
                }

                val googleSignInOption = GetSignInWithGoogleOption.Builder(serverClientId).build()

                val request = GetCredentialRequest.Builder().addCredentialOption(googleSignInOption).build()

                val credentialManager = CredentialManager.create(activity)

                credentialManager.getCredentialAsync(
                    activity,
                    request,
                    CancellationSignal(),
                    ContextCompat.getMainExecutor(activity),
                    object : CredentialManagerCallback<
                        GetCredentialResponse,
                        GetCredentialException
                    > {
                        override fun onResult(result: GetCredentialResponse) {
                            handleCredential(result, id)
                        }

                        override fun onError(e: GetCredentialException) {
                            dispatchFailure(
                                message = e.localizedMessage
                                    ?: "Google credential request failed",
                                id = id,
                                cancelled = e is GetCredentialCancellationException
                            )
                        }
                    }
                )
            } catch (e: Exception) {
                Log.e(TAG, "Unable to start Google Sign-In", e)

                dispatchFailure(
                    message = e.localizedMessage
                        ?: "Unable to start Google Sign-In",
                    id = id
                )
            }
        }

        private fun handleCredential(
            response: GetCredentialResponse,
            id: String?
        ) {
            val credential = response.credential

            if (
                credential !is CustomCredential ||
                credential.type !=
                GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL
            ) {
                dispatchFailure(
                    message = "The returned credential is not a Google ID token",
                    id = id
                )
                return
            }

            try {
                val googleCredential =
                    GoogleIdTokenCredential.createFrom(credential.data)

                authenticateWithFirebase(
                    googleIdToken = googleCredential.idToken,
                    id = id
                )
            } catch (e: Exception) {
                Log.e(TAG, "Unable to parse Google ID token", e)

                dispatchFailure(
                    message = e.localizedMessage
                        ?: "Unable to parse Google ID token",
                    id = id
                )
            }
        }

        private fun authenticateWithFirebase(
            googleIdToken: String,
            id: String?
        ) {
            try {
                val auth = FirebaseAuth.getInstance()

                val firebaseCredential =
                    GoogleAuthProvider.getCredential(googleIdToken, null)

                auth.signInWithCredential(firebaseCredential)
                    .addOnCompleteListener(activity) { signInTask ->
                        if (! signInTask.isSuccessful) {
                            dispatchFailure(
                                message = signInTask.exception?.localizedMessage
                                    ?: "Firebase authentication failed",
                                id = id
                            )
                            return@addOnCompleteListener
                        }

                        val user = auth.currentUser

                        if (user == null) {
                            dispatchFailure(
                                message = "Firebase returned no authenticated user",
                                id = id
                            )
                            return@addOnCompleteListener
                        }

                        user.getIdToken(true)
                            .addOnCompleteListener(activity) { tokenTask ->
                                val firebaseIdToken = tokenTask.result?.token

                                if (! tokenTask.isSuccessful ||
                                    firebaseIdToken.isNullOrBlank()
                                ) {
                                    dispatchFailure(
                                        message = tokenTask.exception?.localizedMessage
                                            ?: "Unable to obtain Firebase ID token",
                                        id = id
                                    )
                                    return@addOnCompleteListener
                                }

                                val payload = JSONObject().apply {
                                    put("success", true)
                                    put("idToken", firebaseIdToken)
                                    put("uid", user.uid)
                                    put("cancelled", false)

                                    user.email?.let { put("email", it) }
                                    user.displayName?.let { put("name", it) }
                                    user.photoUrl?.let {
                                        put("photoUrl", it.toString())
                                    }
                                    id?.let { put("id", it) }
                                }

                                dispatchResult(payload)
                            }
                    }
            } catch (e: Exception) {
                Log.e(TAG, "Firebase authentication error", e)

                dispatchFailure(
                    message = e.localizedMessage
                        ?: "Firebase authentication error",
                    id = id
                )
            }
        }

        private fun dispatchFailure(
            message: String,
            id: String?,
            cancelled: Boolean = false
        ) {
            val payload = JSONObject().apply {
                put("success", false)
                put("error", message)
                put("cancelled", cancelled)
                id?.let { put("id", it) }
            }

            dispatchResult(payload)
        }

        private fun dispatchResult(payload: JSONObject) {
            Handler(Looper.getMainLooper()).post {
                NativeActionCoordinator.dispatchEvent(
                    activity,
                    COMPLETED_EVENT,
                    payload.toString()
                )
            }
        }
    }

    class SignOut(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            FirebaseAuth.getInstance().signOut()

            val credentialManager = CredentialManager.create(context)

            credentialManager.clearCredentialStateAsync(
                ClearCredentialStateRequest(),
                CancellationSignal(),
                ContextCompat.getMainExecutor(context),
                object : CredentialManagerCallback<
                    Void?,
                    ClearCredentialException
                > {
                    override fun onResult(result: Void?) {
                        Log.d(TAG, "Credential state cleared")
                    }

                    override fun onError(e: ClearCredentialException) {
                        Log.e(
                            TAG,
                            "Unable to clear credential state",
                            e
                        )
                    }
                }
            )

            return BridgeResponse.success(
                mapOf(
                    "signedOut" to true,
                    "clearingCredentialState" to true
                )
            )
        }
    }
}
package com.bbs.plugins.biometric

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/**
 * Functions related to biometric authentication (Android only, for now)
 * Namespace: "Biometric.*"
 */
object BiometricFunctions {

    /**
     * Check whether biometric hardware is available and at least one
     * fingerprint/face is enrolled on this device.
     * Returns synchronously — no event involved.
     */
    class IsAvailable(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val biometricManager = BiometricManager.from(activity)
            val canAuthenticate = biometricManager.canAuthenticate(
                BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.BIOMETRIC_WEAK
            )

            return mapOf(
                "available" to (canAuthenticate == BiometricManager.BIOMETRIC_SUCCESS),
                "status" to when (canAuthenticate) {
                    BiometricManager.BIOMETRIC_SUCCESS -> "available"
                    BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE -> "no_hardware"
                    BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE -> "hardware_unavailable"
                    BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> "none_enrolled"
                    else -> "unknown"
                }
            )
        }
    }

    /**
     * Show the native fingerprint/face prompt.
     * Parameters:
     *   - title: (optional) string
     *   - subtitle: (optional) string
     *   - id: (optional) string - correlates the result event back to this call
     * Returns:
     *   - (empty map - result is returned via the BiometricCompleted event)
     * Events:
     *   - Fires "Bbs\Biometric\Events\BiometricCompleted" with {success, id} when the
     *     prompt genuinely resolves (success, hard error, or user cancel).
     */
    class Authenticate(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val title = parameters["title"] as? String ?: "Authenticate"
            val subtitle = parameters["subtitle"] as? String ?: "Confirm your identity"
            val id = parameters["id"] as? String

            Log.d("BiometricFunctions.Authenticate", "🔐 Launching biometric prompt, id=$id")

            Handler(Looper.getMainLooper()).post {
                try {
                    val executor = ContextCompat.getMainExecutor(activity)

                    val callback = object : BiometricPrompt.AuthenticationCallback() {
                        override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                            super.onAuthenticationSucceeded(result)
                            Log.d("BiometricFunctions.Authenticate", "✅ Success")

                            val payload = JSONObject().apply {
                                put("success", true)
                                id?.let { put("id", it) }
                            }
                            dispatchResult(payload.toString())
                        }

                        override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                            super.onAuthenticationError(errorCode, errString)
                            // Fires on hard errors AND on user-initiated cancel — both are
                            // genuine final outcomes, unlike onAuthenticationFailed() below.
                            Log.d("BiometricFunctions.Authenticate", "❌ Error/cancelled: $errString")

                            val payload = JSONObject().apply {
                                put("success", false)
                                put("error", errString.toString())
                                id?.let { put("id", it) }
                            }
                            dispatchResult(payload.toString())
                        }

                        override fun onAuthenticationFailed() {
                            super.onAuthenticationFailed()
                            // A single failed match attempt (e.g. wrong finger) — the
                            // system prompt stays open for another try. Not a final
                            // result, so deliberately no event dispatched here.
                            Log.d("BiometricFunctions.Authenticate", "⚠️ One failed attempt, prompt still open")
                        }
                    }

                    val biometricPrompt = BiometricPrompt(activity, executor, callback)

                    val promptInfo = BiometricPrompt.PromptInfo.Builder()
                        .setTitle(title)
                        .setSubtitle(subtitle)
                        .setNegativeButtonText("Cancel")
                        .setAllowedAuthenticators(
                            BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.BIOMETRIC_WEAK
                        )
                        .build()

                    biometricPrompt.authenticate(promptInfo)
                } catch (e: Exception) {
                    Log.e("BiometricFunctions.Authenticate", "❌ Error launching prompt: ${e.message}", e)

                    val payload = JSONObject().apply {
                        put("success", false)
                        put("error", e.message ?: "Unknown error")
                        id?.let { put("id", it) }
                    }
                    dispatchResult(payload.toString())
                }
            }

            return emptyMap()
        }

        private fun dispatchResult(payloadJson: String) {
            NativeActionCoordinator.dispatchEvent(activity, "Bbs\\Biometric\\Events\\BiometricCompleted", payloadJson)
        }
    }
}
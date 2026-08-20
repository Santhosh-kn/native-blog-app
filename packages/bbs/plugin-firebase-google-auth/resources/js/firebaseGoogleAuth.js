/**
 * FirebaseGoogleAuth Plugin for NativePHP Mobile
 *
 * @example
 * import { firebaseGoogleAuth } from '@bbs/plugin-firebase-google-auth';
 *
 * // Start native Google authentication.
 * const result = await firebaseGoogleAuth.signIn({
 *     id: 'login-request-id'
 * });
 *
 * // Sign out of Firebase and clear Credential Manager state.
 * await firebaseGoogleAuth.signOut();
 */

const baseUrl = '/_native/api/call';

/**
 * Call a NativePHP bridge function.
 *
 * @param {string} method
 * @param {Object} params
 * @returns {Promise<any>}
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            method,
            params
        })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;

    if (
        nativeResponse
        && nativeResponse.data !== undefined
    ) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Start Google Sign-In.
 *
 * The final authentication result is delivered through the
 * FirebaseGoogleAuthCompleted NativePHP event.
 *
 * @param {Object} options
 * @param {string} [options.id] Correlation ID for the completed event.
 * @returns {Promise<any>}
 */
export async function signIn(options = {}) {
    return bridgeCall(
        'FirebaseGoogleAuth.SignIn',
        options
    );
}

/**
 * Sign out of Firebase Authentication and clear credential state.
 *
 * @returns {Promise<any>}
 */
export async function signOut() {
    return bridgeCall('FirebaseGoogleAuth.SignOut');
}

/**
 * FirebaseGoogleAuth namespace object.
 */
export const firebaseGoogleAuth = {
    signIn,
    signOut
};

export default firebaseGoogleAuth;
/**
 * Firebase push-notification bridge for NativePHP Mobile.
 *
 * getToken() starts an asynchronous Firebase request.
 * The final token is returned through the
 * FirebasePushNotificationsCompleted native event.
 */

const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector('meta[name="csrf-token"]')
                    ?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(
            result.message || 'Native call failed'
        );
    }

    const nativeResponse = result.data;

    if (
        nativeResponse &&
        nativeResponse.data !== undefined
    ) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

export async function checkPermission() {
    return bridgeCall(
        'FirebasePushNotifications.CheckPermission'
    );
}

export async function requestPermission() {
    return bridgeCall(
        'FirebasePushNotifications.RequestPermission'
    );
}

export async function getToken(id = null) {
    const parameters = {};

    if (id !== null) {
        parameters.id = id;
    }

    return bridgeCall(
        'FirebasePushNotifications.GetToken',
        parameters
    );
}

export const firebasePushNotifications = {
    checkPermission,
    requestPermission,
    getToken
};

export default firebasePushNotifications;
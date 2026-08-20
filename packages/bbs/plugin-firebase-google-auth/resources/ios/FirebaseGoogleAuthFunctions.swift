import Foundation

enum FirebaseGoogleAuthFunctions {

    class SignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.success(data: [
                "started": false,
                "status": "not_implemented"
            ])
        }
    }

    class SignOut: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.success(data: [
                "signedOut": false,
                "status": "not_implemented"
            ])
        }
    }
}